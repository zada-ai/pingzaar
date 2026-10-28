package whatsapp

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"time"

	"github.com/sirupsen/logrus"

	"whatsapp-server/pkg/types"
)

// MessageFetcher handles fetching pending messages from server
type MessageFetcher struct {
	sessionMgr *SessionManager
	logger     *logrus.Logger
	httpClient *http.Client
}

// NewMessageFetcher creates a new message fetcher service
func NewMessageFetcher(sessionMgr *SessionManager, logger *logrus.Logger) *MessageFetcher {
	return &MessageFetcher{
		sessionMgr: sessionMgr,
		logger:     logger,
		httpClient: &http.Client{
			Timeout: 15 * time.Second, // Match PHP timeout
		},
	}
}

// FetchMessagesOnDemand fetches pending messages for a session from server
// This is called when the /chats/send endpoint is triggered
func (mf *MessageFetcher) FetchMessagesOnDemand(session *types.Session) error {
	// Try to acquire fetch lock (non-blocking)
	// If another fetch is already in progress, skip this request
	if !session.FetchMutex.TryLock() {
		mf.logger.WithField("session_id", session.ID).
			Debug("Message fetch already in progress, skipping concurrent request")
		return nil // Return success - another fetch will handle messages
	}
	defer session.FetchMutex.Unlock()

	// Calculate how many messages to fetch using thread-safe method
	// This gets available capacity atomically to prevent race conditions
	availableCapacity := session.MessageQueue.AvailableCapacity()

	if availableCapacity <= 0 {
		mf.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"queue_size": session.MessageQueue.Size(),
			"max_size":   session.MessageQueue.GetMaxSize(),
		}).Debug("Message queue is full, skipping fetch")
		return nil // Queue is full
	}

	mf.logger.WithFields(logrus.Fields{
		"session_id":         session.ID,
		"available_capacity": availableCapacity,
		"site_url":           session.Cache.SiteURL,
	}).Debug("Fetching messages from server")

	// Build request URL with query parameters
	fetchURL := fmt.Sprintf("%s/whatsapp/messages",
		trimSuffix(session.Cache.SiteURL, "/"))

	params := url.Values{
		"system_token": {session.Cache.SystemToken},
		"uid":          {session.Cache.UID},
		"hash":         {session.Cache.Hash},
		"unique":       {session.Cache.Unique},
		"diff":         {fmt.Sprintf("%d", availableCapacity)},
	}

	// Create request with query parameters
	fullURL := fmt.Sprintf("%s?%s", fetchURL, params.Encode())

	mf.logger.WithFields(logrus.Fields{
		"session_id": session.ID,
		"url":        fullURL,
		"method":     "GET",
	}).Debug("Sending fetch request to server")

	// Make HTTP request
	req, err := http.NewRequest("GET", fullURL, nil)
	if err != nil {
		mf.logger.WithError(err).WithField("session_id", session.ID).
			Error("Failed to create fetch request")
		return fmt.Errorf("failed to create fetch request: %w", err)
	}

	// Set headers to match PHP library expectations
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36")
	req.Header.Set("ngrok-skip-browser-warning", generateUniqID())
	req.Header.Set("Bypass-Tunnel-Reminder", generateUniqID())
	req.Header.Set("X-Pinggy-No-Screen", generateUniqID())

	// Execute request
	startTime := time.Now()
	resp, err := mf.httpClient.Do(req)
	duration := time.Since(startTime)

	if err != nil {
		mf.logger.WithError(err).WithFields(logrus.Fields{
			"session_id": session.ID,
			"url":        fullURL,
			"duration":   duration,
		}).Error("Failed to fetch messages from server")
		return fmt.Errorf("failed to fetch messages: %w", err)
	}
	defer resp.Body.Close()

	// Read response body
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		mf.logger.WithError(err).WithField("session_id", session.ID).
			Error("Failed to read fetch response body")
		return fmt.Errorf("failed to read response body: %w", err)
	}

	mf.logger.WithFields(logrus.Fields{
		"session_id":    session.ID,
		"status_code":   resp.StatusCode,
		"response_body": string(body),
		"duration":      duration,
	}).Debug("Received fetch response from server")

	// Check HTTP status
	if resp.StatusCode != 200 {
		mf.logger.WithFields(logrus.Fields{
			"session_id":    session.ID,
			"status_code":   resp.StatusCode,
			"response_body": string(body),
		}).Warn("server returned non-200 status for message fetch")
		return fmt.Errorf("server returned status %d", resp.StatusCode)
	}

	// Parse PHP response (first pass - structure only)
	var phpResponse types.PHPMessagesResponse
	if err := json.Unmarshal(body, &phpResponse); err != nil {
		mf.logger.WithError(err).WithFields(logrus.Fields{
			"session_id":    session.ID,
			"response_body": string(body),
		}).Error("Failed to parse PHP messages response")
		return fmt.Errorf("failed to parse PHP response: %w", err)
	}

	// Check if PHP response indicates success (HTTP 2xx status codes)
	// This MUST be checked before trying to parse the data field
	if phpResponse.Status < 200 || phpResponse.Status >= 300 {
		mf.logger.WithFields(logrus.Fields{
			"session_id":    session.ID,
			"php_status":    phpResponse.Status,
			"response_body": string(body),
		}).Warn("server returned unsuccessful status for message fetch")
		return fmt.Errorf("server returned unsuccessful status: %d", phpResponse.Status)
	}

	// Parse data field (second pass - only if status is successful)
	var data types.PHPMessagesData
	if err := json.Unmarshal(phpResponse.Data, &data); err != nil {
		mf.logger.WithError(err).WithFields(logrus.Fields{
			"session_id":    session.ID,
			"response_body": string(body),
		}).Error("Failed to parse PHP response data field")
		return fmt.Errorf("failed to parse PHP response data: %w", err)
	}

	// Update account settings from PHP response (must be done before logging)
	mf.updateAccountSettings(session, &data)

	// Filter messages - only add running campaigns (cstatus == 1)
	// This is the first line of defense against paused campaigns
	validMessages := []types.QueuedMessage{}
	filteredCount := 0

	for _, msg := range data.Messages {
		// Skip paused campaigns (cstatus != 1)
		if msg.CID > 0 && msg.CStatus != 1 {
			mf.logger.WithFields(logrus.Fields{
				"session_id": session.ID,
				"message_id": msg.ID,
				"cid":        msg.CID,
				"cstatus":    msg.CStatus,
			}).Debug("Skipping message from paused campaign during fetch")
			filteredCount++
			continue
		}
		validMessages = append(validMessages, msg)
	}

	// Add only valid messages to queue
	messagesAdded := session.MessageQueue.AddBatch(validMessages)

	// Persist messages to database for crash recovery
	if messagesAdded > 0 {
		if err := saveMessagesToDB(session, validMessages, mf.logger); err != nil {
			mf.logger.WithError(err).WithField("session_id", session.ID).
				Warn("Failed to persist messages to database")
			// Continue anyway - messages are in RAM queue
		}
	}

	mf.logger.WithFields(logrus.Fields{
		"session_id":        session.ID,
		"messages_fetched":  len(data.Messages),
		"messages_filtered": filteredCount,
		"messages_added":    messagesAdded,
		"queue_size_after":  session.MessageQueue.Size(),
		"site_name":         data.SiteName,
		"receive_chats":     session.Cache.ReceiveChats, // Use converted int from cache
		"random_send":       session.Cache.RandomSend,   // Use converted int from cache
		"random_min":        session.Cache.RandomMin,    // Use converted int from cache
		"random_max":        session.Cache.RandomMax,    // Use converted int from cache
	}).Info("Successfully fetched and queued messages from server")

	// Trigger message processing if messages were added
	if messagesAdded > 0 {
		mf.sessionMgr.OnMessagesAdded(session.ID)
		mf.logger.WithFields(logrus.Fields{
			"session_id":     session.ID,
			"messages_added": messagesAdded,
		}).Debug("Triggered event-driven processing for fetched messages")
	}

	return nil
}

// updateAccountSettings updates session cache with account settings from PHP response
func (mf *MessageFetcher) updateAccountSettings(session *types.Session, data *types.PHPMessagesData) {
	// Update account settings in session cache
	oldSettings := map[string]int{
		"receive_chats": session.Cache.ReceiveChats,
		"random_send":   session.Cache.RandomSend,
		"random_min":    session.Cache.RandomMin,
		"random_max":    session.Cache.RandomMax,
	}

	session.Cache.ReceiveChats = parseIntSafe(data.ReceiveChats, 2) // Default: 2 (matches session.go:74)
	session.Cache.RandomSend = parseIntSafe(data.RandomSend, 1)     // Default: 1 (matches session.go:77)
	session.Cache.RandomMin = parseIntSafe(data.RandomMin, 1)       // Default: 1 (matches session.go:80)
	session.Cache.RandomMax = parseIntSafe(data.RandomMax, 5)       // Default: 5 (matches session.go:83)

	newSettings := map[string]int{
		"receive_chats": session.Cache.ReceiveChats,
		"random_send":   session.Cache.RandomSend,
		"random_min":    session.Cache.RandomMin,
		"random_max":    session.Cache.RandomMax,
	}

	// Log settings changes
	mf.logger.WithFields(logrus.Fields{
		"session_id":   session.ID,
		"old_settings": oldSettings,
		"new_settings": newSettings,
		"site_name":    data.SiteName,
	}).Debug("Updated account settings from PHP response")

	// Save updated cache to file
	if err := mf.sessionMgr.SaveSessionCache(session.ID, session.Cache); err != nil {
		mf.logger.WithError(err).WithField("session_id", session.ID).
			Warn("Failed to save updated session cache")
	}
}

// trimSuffix removes suffix from string if present
func trimSuffix(s, suffix string) string {
	if len(s) >= len(suffix) && s[len(s)-len(suffix):] == suffix {
		return s[:len(s)-len(suffix)]
	}
	return s
}

// generateUniqID generates a unique ID for headers (mimics PHP uniqid())
func generateUniqID() string {
	return fmt.Sprintf("%d%d", time.Now().UnixNano(), time.Now().Unix())
}
