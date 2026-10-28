package whatsapp

import (
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"sync"
	"time"

	"github.com/sirupsen/logrus"

	"whatsapp-server/pkg/types"
)

// StatusReport represents a pending status report to be sent asynchronously
type StatusReport struct {
	SessionID   string // Session ID for logging (session may be gone when processing)
	SiteURL     string // Cached site URL
	SystemToken string // Cached system token
	UID         string // Cached UID
	Hash        string // Cached hash
	Message     types.QueuedMessage
	Success     bool
	RetryCount  int
	CreatedAt   time.Time
}

// StatusReporter handles reporting message send status back to server
// Now uses an async background queue for non-blocking operation
type StatusReporter struct {
	sessionMgr  SessionManagerInterface
	logger      *logrus.Logger
	httpClient  HTTPClient
	reportQueue chan *StatusReport // Buffered channel for async reports
	wg          sync.WaitGroup     // For graceful shutdown
	shutdown    chan struct{}      // Signal to stop worker
}

// NewStatusReporter creates a new status reporter service with async queue
func NewStatusReporter(sessionMgr *SessionManager, logger *logrus.Logger) *StatusReporter {
	sr := &StatusReporter{
		sessionMgr: sessionMgr,
		logger:     logger,
		httpClient: &http.Client{
			Timeout: 30 * time.Second,
		},
		reportQueue: make(chan *StatusReport, 1000), // Buffer 1000 reports
		shutdown:    make(chan struct{}),
	}

	// Start background worker
	sr.startWorker()

	return sr
}

// NewStatusReporterWithDeps creates a new status reporter with injectable dependencies.
// This constructor is primarily used for testing with mock implementations.
func NewStatusReporterWithDeps(
	sessionMgr SessionManagerInterface,
	logger *logrus.Logger,
	httpClient HTTPClient,
) *StatusReporter {
	if httpClient == nil {
		httpClient = &http.Client{Timeout: 30 * time.Second}
	}
	sr := &StatusReporter{
		sessionMgr:  sessionMgr,
		logger:      logger,
		httpClient:  httpClient,
		reportQueue: make(chan *StatusReport, 1000),
		shutdown:    make(chan struct{}),
	}

	// Start background worker
	sr.startWorker()

	return sr
}

// startWorker starts the background goroutine that processes status reports
func (sr *StatusReporter) startWorker() {
	sr.wg.Add(1)
	go func() {
		defer sr.wg.Done()
		sr.logger.Info("Status reporter worker started")

		for {
			select {
			case <-sr.shutdown:
				sr.logger.Info("Status reporter worker shutting down")
				// Drain remaining reports with timeout
				sr.drainQueue()
				return

			case report := <-sr.reportQueue:
				sr.processReport(report)
			}
		}
	}()
}

// drainQueue processes remaining reports during shutdown (with timeout)
func (sr *StatusReporter) drainQueue() {
	timeout := time.After(10 * time.Second)
	drained := 0

	for {
		select {
		case <-timeout:
			if drained > 0 {
				sr.logger.WithField("drained", drained).Info("Shutdown timeout reached, some reports may be lost")
			}
			return

		case report := <-sr.reportQueue:
			sr.processReportSync(report) // Sync processing during shutdown
			drained++

		default:
			if drained > 0 {
				sr.logger.WithField("drained", drained).Info("All pending reports processed during shutdown")
			}
			return
		}
	}
}

// processReport processes a single status report with retry scheduling
func (sr *StatusReporter) processReport(report *StatusReport) {
	err := sr.sendReport(report)
	if err != nil && report.RetryCount < 3 {
		// Schedule retry with exponential backoff: 1s, 2s, 4s
		delay := time.Duration(1<<report.RetryCount) * time.Second
		report.RetryCount++

		sr.logger.WithFields(logrus.Fields{
			"session_id":  report.SessionID,
			"message_id":  report.Message.ID,
			"retry_count": report.RetryCount,
			"delay":       delay,
		}).Debug("Scheduling status report retry")

		// Use time.AfterFunc for non-blocking retry
		time.AfterFunc(delay, func() {
			select {
			case sr.reportQueue <- report:
				// Re-queued successfully
			default:
				sr.logger.WithFields(logrus.Fields{
					"session_id": report.SessionID,
					"message_id": report.Message.ID,
				}).Warn("Failed to re-queue status report - queue full")
			}
		})
	} else if err != nil {
		sr.logger.WithError(err).WithFields(logrus.Fields{
			"session_id":  report.SessionID,
			"message_id":  report.Message.ID,
			"retry_count": report.RetryCount,
		}).Error("Status report permanently failed after all retries")
	}
}

// processReportSync processes a report synchronously (used during shutdown)
func (sr *StatusReporter) processReportSync(report *StatusReport) {
	for attempt := 0; attempt <= 2; attempt++ {
		if err := sr.sendReport(report); err == nil {
			return
		}
		if attempt < 2 {
			time.Sleep(500 * time.Millisecond) // Brief delay during shutdown
		}
	}
}

// Shutdown gracefully stops the status reporter
func (sr *StatusReporter) Shutdown() {
	sr.logger.Info("Shutting down status reporter")
	close(sr.shutdown)
	sr.wg.Wait()
	sr.logger.Info("Status reporter shutdown complete")
}

// QueueStatusReport queues a status report for async processing
// Returns error only if queue is full (rare)
// This is the preferred method - non-blocking
func (sr *StatusReporter) QueueStatusReport(session *types.Session, message types.QueuedMessage, success bool) error {
	report := &StatusReport{
		SessionID:   session.ID,
		SiteURL:     session.Cache.SiteURL,
		SystemToken: session.Cache.SystemToken,
		UID:         session.Cache.UID,
		Hash:        session.Cache.Hash,
		Message:     message,
		Success:     success,
		RetryCount:  0,
		CreatedAt:   time.Now(),
	}

	select {
	case sr.reportQueue <- report:
		sr.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": message.ID,
			"success":    success,
		}).Debug("Status report queued for async processing")
		return nil
	default:
		sr.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": message.ID,
			"queue_size": len(sr.reportQueue),
		}).Error("Status report queue full - report dropped")
		return fmt.Errorf("status report queue full")
	}
}

// sendReport sends a single status report to the PHP server
func (sr *StatusReporter) sendReport(report *StatusReport) error {
	status := "4" // Default to failure
	if report.Success {
		status = "3" // Success
	}

	// Build request URL with query parameters
	reportURL := fmt.Sprintf("%s/whatsapp/sent",
		trimSuffix(report.SiteURL, "/"))

	params := url.Values{
		"system_token": {report.SystemToken},
		"id":           {strconv.Itoa(report.Message.ID)},
		"cid":          {strconv.Itoa(report.Message.CID)},
		"uid":          {report.UID},
		"hash":         {report.Hash},
		"status":       {status},
	}

	fullURL := fmt.Sprintf("%s?%s", reportURL, params.Encode())

	startTime := time.Now()

	// Create HTTP request
	req, err := http.NewRequest("GET", fullURL, nil)
	if err != nil {
		return fmt.Errorf("failed to create request: %w", err)
	}

	// Set headers
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36")
	req.Header.Set("ngrok-skip-browser-warning", generateUniqID())
	req.Header.Set("Bypass-Tunnel-Reminder", generateUniqID())
	req.Header.Set("X-Pinggy-No-Screen", generateUniqID())

	// Execute request
	resp, err := sr.httpClient.Do(req)
	duration := time.Since(startTime)

	if err != nil {
		sr.logger.WithError(err).WithFields(logrus.Fields{
			"session_id":  report.SessionID,
			"message_id":  report.Message.ID,
			"retry_count": report.RetryCount,
			"duration":    duration,
		}).Warn("Status report request failed with network error")
		return err
	}
	defer resp.Body.Close()

	// Read response body for debugging
	bodyBytes, _ := io.ReadAll(resp.Body)

	if resp.StatusCode == 200 {
		sr.logger.WithFields(logrus.Fields{
			"session_id":  report.SessionID,
			"message_id":  report.Message.ID,
			"duration":    duration,
			"retry_count": report.RetryCount,
		}).Info("Message status reported successfully to server")
		return nil
	}

	sr.logger.WithFields(logrus.Fields{
		"session_id":    report.SessionID,
		"message_id":    report.Message.ID,
		"status_code":   resp.StatusCode,
		"response_body": string(bodyBytes),
		"retry_count":   report.RetryCount,
	}).Warn("Server returned non-200 status for status report")

	return fmt.Errorf("server returned status %d", resp.StatusCode)
}

// ReportMessageStatus reports the send status of a message back to PHP (sync - legacy)
// Prefer QueueStatusReport for non-blocking operation
func (sr *StatusReporter) ReportMessageStatus(session *types.Session, message types.QueuedMessage, success bool) error {
	return sr.QueueStatusReport(session, message, success)
}

// ReportMessageSuccess reports a successful message send
func (sr *StatusReporter) ReportMessageSuccess(session *types.Session, message types.QueuedMessage) error {
	// Just report status - message removal is now handled by processor
	return sr.ReportMessageStatus(session, message, true)
}

// ReportMessageFailure reports a failed message send
func (sr *StatusReporter) ReportMessageFailure(session *types.Session, message types.QueuedMessage) error {
	// Just report status - message removal is now handled by processor
	return sr.ReportMessageStatus(session, message, false)
}

// ReportBatchStatus reports status for multiple messages
func (sr *StatusReporter) ReportBatchStatus(session *types.Session, results []MessageSendResult) {
	for _, result := range results {
		var err error
		if result.Success {
			err = sr.ReportMessageSuccess(session, result.Message)
		} else {
			err = sr.ReportMessageFailure(session, result.Message)
		}

		if err != nil {
			sr.logger.WithError(err).WithFields(logrus.Fields{
				"session_id": session.ID,
				"message_id": result.Message.ID,
				"success":    result.Success,
			}).Error("Failed to report message status in batch")
		}
	}
}

// MessageSendResult represents the result of a message send attempt
type MessageSendResult struct {
	Message types.QueuedMessage `json:"message"`
	Success bool                `json:"success"`
	Error   error               `json:"error,omitempty"`
}
