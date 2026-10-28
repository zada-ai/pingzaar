package whatsapp

import (
	"context"
	"sync"
	"time"

	"github.com/sirupsen/logrus"

	"whatsapp-server/pkg/types"
)

// EventDrivenProcessor handles event-driven message processing with one-by-one sending
type EventDrivenProcessor struct {
	sessionMgr      SessionManagerInterface
	messageSender   MessageSenderInterface
	statusReporter  StatusReporterInterface
	processingQueue chan types.SessionEvent
	droppedSessions sync.Map // Track sessions with dropped events for recovery
	logger          *logrus.Logger
	ctx             context.Context
	cancel          context.CancelFunc
}

// NewEventDrivenProcessor creates a new event-driven processor
func NewEventDrivenProcessor(sessionMgr *SessionManager, logger *logrus.Logger) *EventDrivenProcessor {
	ctx, cancel := context.WithCancel(context.Background())

	processor := &EventDrivenProcessor{
		sessionMgr:      sessionMgr,
		messageSender:   NewMessageSender(sessionMgr, logger),
		statusReporter:  NewStatusReporter(sessionMgr, logger),
		processingQueue: make(chan types.SessionEvent, 20000), // Buffer for events (increased for high-load scenarios)
		logger:          logger,
		ctx:             ctx,
		cancel:          cancel,
	}

	return processor
}

// NewEventDrivenProcessorWithDeps creates a new event-driven processor with injectable dependencies.
// This constructor is primarily used for testing with mock implementations.
func NewEventDrivenProcessorWithDeps(
	sessionMgr SessionManagerInterface,
	messageSender MessageSenderInterface,
	statusReporter StatusReporterInterface,
	logger *logrus.Logger,
) *EventDrivenProcessor {
	ctx, cancel := context.WithCancel(context.Background())

	return &EventDrivenProcessor{
		sessionMgr:      sessionMgr,
		messageSender:   messageSender,
		statusReporter:  statusReporter,
		processingQueue: make(chan types.SessionEvent, 20000),
		logger:          logger,
		ctx:             ctx,
		cancel:          cancel,
	}
}

// Start begins the event-driven processing
func (edp *EventDrivenProcessor) Start() {
	edp.logger.Info("Starting event-driven message processor")

	// Start the main processor goroutine
	SafeGo(edp.logger, "event_processor", edp.processEvents)

	// Start periodic cleanup for any missed messages (safety net)
	SafeGo(edp.logger, "periodic_cleanup", edp.startPeriodicCleanup)

	edp.logger.Info("Event-driven message processor started successfully")
}

// Stop stops the event-driven processing
func (edp *EventDrivenProcessor) Stop() {
	edp.logger.Info("Stopping event-driven message processor")
	edp.cancel()
	edp.logger.Info("Event-driven message processor stopped")
}

// processEvents is the main event processing loop
func (edp *EventDrivenProcessor) processEvents() {
	for {
		select {
		case <-edp.ctx.Done():
			edp.logger.Info("Event processor context cancelled, stopping")
			return

		case event := <-edp.processingQueue:
			SafeGo(edp.logger, "session_event_handler", func() {
				edp.handleSessionEvent(event)
			})
		}
	}
}

// handleSessionEvent processes a single session event
func (edp *EventDrivenProcessor) handleSessionEvent(event types.SessionEvent) {
	session, exists := edp.sessionMgr.GetSession(event.SessionID)
	if !exists {
		edp.logger.WithFields(logrus.Fields{
			"session_id": event.SessionID,
			"event_type": event.EventType,
		}).Debug("Session not found for event, skipping")
		return
	}

	// Only process connected sessions
	if session.Status != types.StatusConnected {
		edp.logger.WithFields(logrus.Fields{
			"session_id": event.SessionID,
			"event_type": event.EventType,
			"status":     session.Status,
		}).Debug("Session not connected, skipping event")
		return
	}

	// Check if enough time has passed since last send
	if !edp.canSendNow(session) {
		// Schedule retry after required delay
		SafeGo(edp.logger, "schedule_retry", func() {
			edp.scheduleRetry(session)
		})
		return
	}

	// Process ONE message for this session
	edp.processSingleMessage(session)
}

// canSendNow checks if enough time has passed since last send based on account settings
func (edp *EventDrivenProcessor) canSendNow(session *types.Session) bool {
	// Atomically initialize baseline time for first message (thread-safe)
	if session.MessageState.InitializeBaseline() {
		// Initialize delay for first message
		session.MessageState.InitializeNextDelay(
			session.Cache.RandomSend,
			session.Cache.RandomMin,
			session.Cache.RandomMax,
		)
		// First queued message should also respect delay
		return false // Will retry after configured delay
	}

	// Initialize delay if not already done (happens once per message cycle)
	requiredDelay := session.MessageState.GetNextDelay()
	timeSinceLastSend := time.Since(session.MessageState.LastSentTime)

	canSend := timeSinceLastSend >= requiredDelay

	edp.logger.WithFields(logrus.Fields{
		"session_id":      session.ID,
		"time_since_last": timeSinceLastSend,
		"required_delay":  requiredDelay,
		"can_send":        canSend,
		"random_send":     session.Cache.RandomSend,
		"random_min":      session.Cache.RandomMin,
		"random_max":      session.Cache.RandomMax,
	}).Debug("Checking if session can send now")

	return canSend
}

// processSingleMessage processes ONE message for a session (one-by-one rule)
func (edp *EventDrivenProcessor) processSingleMessage(session *types.Session) {
	// Atomically try to mark session as sending (prevents race conditions)
	if !session.MessageState.TrySetSending() {
		edp.logger.WithField("session_id", session.ID).
			Debug("Session already sending message, skipping")
		return
	}

	defer func() {
		// Always mark as not sending when done, even on panic
		session.MessageState.SetSending(false)

		// Recover from any panics to prevent session lockup
		if r := recover(); r != nil {
			edp.logger.WithFields(logrus.Fields{
				"session_id": session.ID,
				"panic":      r,
			}).Error("Panic recovered in processSingleMessage - session unlocked")
		}
	}()

	// PEEK at next message without removing it (prevents duplicates during send window)
	message := session.MessageQueue.PeekNext()
	if message == nil {
		edp.logger.WithField("session_id", session.ID).Debug("No messages in queue")
		return
	}

	// Check if campaign is paused using atomic session state (prevents race condition)
	// This checks the current campaign status, not the stale CStatus from queue
	if message.CID > 0 && session.IsCampaignPaused(message.CID) {
		edp.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": message.ID,
			"cid":        message.CID,
		}).Info("Skipping message from paused campaign during processing")

		// Move paused message to back of queue (rotation)
		// This prevents blocking active campaigns
		removed := session.MessageQueue.GetNext()
		if removed != nil && session.IsCampaignPaused(removed.CID) {
			session.MessageQueue.Add(*removed)
			edp.logger.WithFields(logrus.Fields{
				"session_id": session.ID,
				"message_id": removed.ID,
				"cid":        removed.CID,
			}).Debug("Rotated paused message to back of queue")
		}

		// Schedule next message processing
		if !session.MessageQueue.IsEmpty() {
			SafeGo(edp.logger, "schedule_next_after_skip", func() {
				edp.scheduleNextMessage(session)
			})
		}
		return
	}

	edp.logger.WithFields(logrus.Fields{
		"session_id": session.ID,
		"message_id": message.ID,
		"cid":        message.CID,
		"phone":      message.Phone,
		"priority":   message.Priority,
	}).Info("Processing single message for session")

	// Send the message via WhatsApp
	success := edp.messageSender.SendWhatsAppMessage(session, *message)

	// Queue status report (async, non-blocking)
	// IMPORTANT: Message was already sent to WhatsApp above, so we MUST remove it
	// from the queue regardless of whether the status report succeeds
	reportErr := edp.statusReporter.QueueStatusReport(session, *message, success)

	if reportErr != nil {
		// Report queue full - but message WAS sent, so we must still remove it
		// to prevent duplicate sends. The status report is lost but that's better
		// than sending duplicate messages to the recipient.
		edp.logger.WithError(reportErr).WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": message.ID,
			"success":    success,
		}).Error("Status report queue full - report dropped but message was sent (removing to prevent duplicates)")
	}

	// ALWAYS remove message after send attempt (whether report succeeded or not)
	// The WhatsApp send already happened - keeping the message would cause duplicates
	session.MessageQueue.RemoveMessage(*message)

	// Delete message from database (persistence cleanup)
	if err := deleteMessageFromDB(session, message.ID, message.CID, edp.logger); err != nil {
		edp.logger.WithError(err).WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": message.ID,
			"cid":        message.CID,
		}).Warn("Failed to delete message from database")
		// Continue anyway - message already removed from RAM queue
	}

	// Update last sent time (thread-safe)
	session.MessageState.SetLastSentTime(time.Now())

	// Reset delay for next message cycle (calculate fresh delay for next message)
	session.MessageState.ResetDelayForNextCycle()

	// If there are more messages, schedule next processing
	if !session.MessageQueue.IsEmpty() {
		// Pre-calculate delay for the next message (stable for entire cycle)
		session.MessageState.InitializeNextDelay(
			session.Cache.RandomSend,
			session.Cache.RandomMin,
			session.Cache.RandomMax,
		)
		SafeGo(edp.logger, "schedule_next", func() {
			edp.scheduleNextMessage(session)
		})
	}

	edp.logger.WithFields(logrus.Fields{
		"session_id":      session.ID,
		"message_id":      message.ID,
		"success":         success,
		"remaining_queue": session.MessageQueue.Size(),
	}).Info("Completed processing single message")
}

// scheduleNextMessage schedules processing of the next message after delay
func (edp *EventDrivenProcessor) scheduleNextMessage(session *types.Session) {
	// Use pre-calculated delay (stable for this message cycle)
	delay := session.MessageState.GetNextDelay()

	edp.logger.WithFields(logrus.Fields{
		"session_id": session.ID,
		"delay":      delay,
		"queue_size": session.MessageQueue.Size(),
	}).Debug("Scheduling next message processing")

	select {
	case <-edp.ctx.Done():
		return // Processor is stopping
	case <-time.After(delay):
		// Trigger processing of next message
		edp.TriggerProcessing(session.ID, "scheduled_send")
	}
}

// scheduleRetry schedules a retry for a session that couldn't send yet
func (edp *EventDrivenProcessor) scheduleRetry(session *types.Session) {
	// Use pre-calculated delay (stable for this message cycle)
	requiredDelay := session.MessageState.GetNextDelay()
	timeSinceLastSend := time.Since(session.MessageState.LastSentTime)
	remainingDelay := requiredDelay - timeSinceLastSend

	if remainingDelay <= 0 {
		remainingDelay = 100 * time.Millisecond // Minimum delay
	}

	edp.logger.WithFields(logrus.Fields{
		"session_id":      session.ID,
		"remaining_delay": remainingDelay,
		"queue_size":      session.MessageQueue.Size(),
	}).Debug("Scheduling retry processing")

	select {
	case <-edp.ctx.Done():
		return // Processor is stopping
	case <-time.After(remainingDelay):
		// Retry processing
		edp.TriggerProcessing(session.ID, "retry_send")
	}
}

// startPeriodicCleanup runs periodic cleanup to catch any missed messages
func (edp *EventDrivenProcessor) startPeriodicCleanup() {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-edp.ctx.Done():
			return

		case <-ticker.C:
			edp.runPeriodicCleanup()
		}
	}
}

// runPeriodicCleanup checks all sessions for missed messages
func (edp *EventDrivenProcessor) runPeriodicCleanup() {
	sessionsChecked := 0
	sessionsProcessed := 0
	stuckSessions := 0
	recoveredSessions := 0

	// Monitor queue depth for early warning
	queueDepth := len(edp.processingQueue)
	queueCapacity := cap(edp.processingQueue)
	queuePercent := float64(queueDepth) / float64(queueCapacity) * 100

	if queuePercent >= 95 {
		edp.logger.WithFields(logrus.Fields{
			"queue_depth":    queueDepth,
			"queue_capacity": queueCapacity,
			"queue_percent":  queuePercent,
		}).Error("CRITICAL: Processing queue at 95%+ capacity - events may be dropped")
	} else if queuePercent >= 80 {
		edp.logger.WithFields(logrus.Fields{
			"queue_depth":    queueDepth,
			"queue_capacity": queueCapacity,
			"queue_percent":  queuePercent,
		}).Warn("Processing queue at 80%+ capacity - approaching limit")
	}

	// Recover sessions that had dropped events (overflow recovery)
	// Only process if queue has capacity (below 50%)
	if queuePercent < 50 {
		edp.droppedSessions.Range(func(key, value interface{}) bool {
			sessionID := key.(string)
			droppedAt := value.(time.Time)

			// Remove from tracking immediately to prevent duplicate recovery
			edp.droppedSessions.Delete(sessionID)

			// Check if session still exists and needs processing
			if session, exists := edp.sessionMgr.GetSession(sessionID); exists {
				if session.Status == types.StatusConnected && !session.MessageQueue.IsEmpty() {
					recoveredSessions++
					edp.logger.WithFields(logrus.Fields{
						"session_id":     sessionID,
						"dropped_at":     droppedAt,
						"recovery_delay": time.Since(droppedAt),
					}).Info("Recovering session from dropped event")
				}
			}
			return true
		})
	}

	// Collect sessions that need processing (instead of triggering individual events)
	var sessionsToProcess []*types.Session

	edp.sessionMgr.RangeSessions(func(sessionID string, session *types.Session) bool {
		sessionsChecked++

		// Check for stuck IsSending flag (timeout protection)
		if session.MessageState.IsSending {
			sendingDuration := time.Since(session.MessageState.SendingStartTime)
			if sendingDuration > 60*time.Second {
				// IsSending stuck for over 60 seconds - force reset
				session.MessageState.SetSending(false)
				stuckSessions++
				edp.logger.WithFields(logrus.Fields{
					"session_id":       sessionID,
					"sending_duration": sendingDuration,
				}).Warn("Force-reset stuck IsSending flag after 60s timeout")
			}
		}

		// Collect connected sessions with pending messages for batch processing
		if session.Status == types.StatusConnected &&
			!session.MessageQueue.IsEmpty() &&
			!session.MessageState.IsSending {

			sessionsToProcess = append(sessionsToProcess, session)
		}

		return true
	})

	// Process collected sessions directly (avoids flooding event queue)
	// This reduces event volume from N events to direct processing
	for _, session := range sessionsToProcess {
		sess := session // Capture for goroutine
		SafeGo(edp.logger, "cleanup_process", func() {
			// Check if session can send now
			if edp.canSendNow(sess) {
				edp.processSingleMessage(sess)
			} else {
				// Schedule retry after required delay
				edp.scheduleRetry(sess)
			}
		})
		sessionsProcessed++
	}

	if sessionsProcessed > 0 || stuckSessions > 0 || recoveredSessions > 0 {
		edp.logger.WithFields(logrus.Fields{
			"sessions_checked":   sessionsChecked,
			"sessions_processed": sessionsProcessed,
			"stuck_reset":        stuckSessions,
			"recovered":          recoveredSessions,
		}).Debug("Periodic cleanup completed")
	}
}

// TriggerProcessing triggers processing for a session (public interface)
func (edp *EventDrivenProcessor) TriggerProcessing(sessionID, eventType string) {
	event := types.SessionEvent{
		SessionID: sessionID,
		EventType: eventType,
		Timestamp: time.Now(),
	}

	// Try to queue the event with retry mechanism
	for attempt := 0; attempt < 3; attempt++ {
		select {
		case edp.processingQueue <- event:
			edp.logger.WithFields(logrus.Fields{
				"session_id": sessionID,
				"event_type": eventType,
			}).Debug("Event queued for processing")
			return // Success

		default:
			// Queue is full, wait and retry
			if attempt < 2 {
				time.Sleep(time.Duration((attempt+1)*50) * time.Millisecond)
			}
		}
	}

	// Failed after all retries - track session for recovery by periodic cleanup
	edp.droppedSessions.Store(sessionID, time.Now())

	queueDepth := len(edp.processingQueue)
	edp.logger.WithFields(logrus.Fields{
		"session_id":  sessionID,
		"event_type":  eventType,
		"queue_depth": queueDepth,
	}).Error("CRITICAL: Processing queue full after retries, event dropped - session marked for recovery")
}

// OnMessagesAdded triggers processing when messages are added to a session
func (edp *EventDrivenProcessor) OnMessagesAdded(sessionID string) {
	edp.TriggerProcessing(sessionID, "messages_added")
}
