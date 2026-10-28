package whatsapp

import (
	"fmt"
	"io"
	"net/http"
	"net/url"
	"sync"
	"time"

	"github.com/sirupsen/logrus"

	"whatsapp-server/pkg/types"
)

// LinkNotifier handles notifying server about account connection/disconnection events
type LinkNotifier struct {
	sessionMgr            *SessionManager
	logger                *logrus.Logger
	httpClient            *http.Client
	pendingDisconnections sync.Map      // map[sessionID]*time.Timer
	disconnectGracePeriod time.Duration // Grace period before notifying disconnection (45 seconds)
}

// NewLinkNotifier creates a new link notification service
func NewLinkNotifier(sessionMgr *SessionManager, logger *logrus.Logger) *LinkNotifier {
	return &LinkNotifier{
		sessionMgr: sessionMgr,
		logger:     logger,
		httpClient: &http.Client{
			Timeout: 30 * time.Second, // Increased for link notifications
		},
		disconnectGracePeriod: 45 * time.Second, // 45-second grace period for reconnection
	}
}

// NotifyAccountLinked notifies server when WhatsApp account is successfully connected
func (ln *LinkNotifier) NotifyAccountLinked(session *types.Session, whatsappID string) error {
	// Build request URL for success notification
	linkURL := fmt.Sprintf("%s/whatsapp/link/success",
		trimSuffix(session.Cache.SiteURL, "/"))

	// Use system token if api_token is "none" (default from PHP library)
	apiToken := session.Cache.APIToken
	if apiToken == "none" || apiToken == "" {
		apiToken = session.Cache.SystemToken
	}

	params := url.Values{
		"system_token": {session.Cache.SystemToken},
		"api_token":    {apiToken},
		"wsid":         {session.Cache.WSID},
		"uid":          {session.Cache.UID},
		"wid":          {whatsappID}, // WhatsApp device ID in "phone:device" format
		"unique":       {session.Cache.Unique},
	}

	// Create full URL with query parameters
	fullURL := fmt.Sprintf("%s?%s", linkURL, params.Encode())

	ln.logger.WithFields(logrus.Fields{
		"session_id":  session.ID,
		"whatsapp_id": whatsappID,
		"url":         fullURL,
		"site_url":    session.Cache.SiteURL,
	}).Info("Notifying server about successful account link")

	// Send notification with retry logic
	return ln.notifyWithRetry(fullURL, session.ID, "success")
}

// NotifyAccountUnlinked notifies server when WhatsApp account is disconnected/logged out
func (ln *LinkNotifier) NotifyAccountUnlinked(session *types.Session) error {
	// Build request URL for failure notification
	linkURL := fmt.Sprintf("%s/whatsapp/link/failed",
		trimSuffix(session.Cache.SiteURL, "/"))

	params := url.Values{
		"system_token": {session.Cache.SystemToken},
		"uid":          {session.Cache.UID},
		"hash":         {session.Cache.Hash},
		"unique":       {session.Cache.Unique},
	}

	// Create full URL with query parameters
	fullURL := fmt.Sprintf("%s?%s", linkURL, params.Encode())

	ln.logger.WithFields(logrus.Fields{
		"session_id": session.ID,
		"url":        fullURL,
		"site_url":   session.Cache.SiteURL,
	}).Info("Notifying server about account unlink/disconnection")

	// Send notification with retry logic
	return ln.notifyWithRetry(fullURL, session.ID, "failed")
}

// notifyWithRetry sends link notification with exponential backoff retry logic
func (ln *LinkNotifier) notifyWithRetry(fullURL, sessionID, notificationType string) error {
	maxRetries := 3

	for attempt := 1; attempt <= maxRetries; attempt++ {
		startTime := time.Now()

		// Create HTTP request
		req, err := http.NewRequest("GET", fullURL, nil)
		if err != nil {
			ln.logger.WithError(err).WithFields(logrus.Fields{
				"session_id":        sessionID,
				"notification_type": notificationType,
				"attempt":           attempt,
			}).Error("Failed to create link notification request")
			return fmt.Errorf("failed to create request: %w", err)
		}

		// Set headers to match PHP library expectations
		req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36")
		req.Header.Set("ngrok-skip-browser-warning", generateUniqID())
		req.Header.Set("Bypass-Tunnel-Reminder", generateUniqID())
		req.Header.Set("X-Pinggy-No-Screen", generateUniqID())

		// Execute request
		resp, err := ln.httpClient.Do(req)
		duration := time.Since(startTime)

		if err != nil {
			ln.logger.WithError(err).WithFields(logrus.Fields{
				"session_id":        sessionID,
				"notification_type": notificationType,
				"attempt":           attempt,
				"duration":          duration,
			}).Error("Link notification request failed with network error")
		} else {
			// Read response body for debugging
			var responseBody string
			if resp.Body != nil {
				bodyBytes, readErr := io.ReadAll(resp.Body)
				resp.Body.Close()
				if readErr != nil {
					responseBody = fmt.Sprintf("Error reading response body: %v", readErr)
				} else {
					responseBody = string(bodyBytes)
				}
			}

			logFields := logrus.Fields{
				"session_id":        sessionID,
				"notification_type": notificationType,
				"attempt":           attempt,
				"status_code":       resp.StatusCode,
				"response_body":     responseBody,
				"duration":          duration,
			}

			if resp.StatusCode == 200 {
				ln.logger.WithFields(logFields).Info("Link notification sent successfully to server")
				return nil
			} else {
				ln.logger.WithFields(logFields).Warn("Server returned non-200 status for link notification")
			}
		}

		// Exponential backoff before retry
		if attempt < maxRetries {
			delay := time.Duration(attempt*attempt) * time.Second
			ln.logger.WithFields(logrus.Fields{
				"session_id":        sessionID,
				"notification_type": notificationType,
				"delay":             delay,
				"attempt":           attempt,
				"next_attempt":      attempt + 1,
			}).Debug("Retrying link notification after delay")
			time.Sleep(delay)
		}
	}

	ln.logger.WithFields(logrus.Fields{
		"session_id":        sessionID,
		"notification_type": notificationType,
		"max_retries":       maxRetries,
	}).Error("Link notification permanently failed after all retry attempts")

	return fmt.Errorf("link notification failed after %d attempts", maxRetries)
}

// NotifyAccountLinkedBackground sends success notification in background (non-blocking)
func (ln *LinkNotifier) NotifyAccountLinkedBackground(session *types.Session, whatsappID string) {
	SafeGo(ln.logger, "notify_linked", func() {
		if err := ln.NotifyAccountLinked(session, whatsappID); err != nil {
			ln.logger.WithError(err).WithFields(logrus.Fields{
				"session_id":  session.ID,
				"whatsapp_id": whatsappID,
			}).Error("Background account linked notification failed")
		}
	})
}

// NotifyAccountUnlinkedBackground sends failure notification in background (non-blocking)
func (ln *LinkNotifier) NotifyAccountUnlinkedBackground(session *types.Session) {
	SafeGo(ln.logger, "notify_unlinked", func() {
		if err := ln.NotifyAccountUnlinked(session); err != nil {
			ln.logger.WithError(err).WithField("session_id", session.ID).
				Error("Background account unlinked notification failed")
		}
	})
}

// ProcessConnectionEvent processes WhatsApp connection events and sends appropriate notifications
func (ln *LinkNotifier) ProcessConnectionEvent(session *types.Session, eventType string, whatsappID string) {
	switch eventType {
	case "connected":
		// Cancel any pending disconnection notification
		ln.cancelPendingDisconnection(session.ID)

		// Only notify on first successful connection or reconnection after disconnect
		session.Mu.RLock()
		currentStatus := session.Status
		session.Mu.RUnlock()

		if currentStatus != types.StatusConnected {
			ln.logger.WithFields(logrus.Fields{
				"session_id":  session.ID,
				"whatsapp_id": whatsappID,
				"old_status":  currentStatus,
				"new_status":  types.StatusConnected,
			}).Info("Processing account connection event")

			// Update session status (thread-safe)
			session.Mu.Lock()
			session.Status = types.StatusConnected
			session.Mu.Unlock()

			// Send success notification
			ln.NotifyAccountLinkedBackground(session, whatsappID)
		}

	case "disconnected":
		// Schedule delayed notification with grace period for reconnection
		session.Mu.RLock()
		currentStatus := session.Status
		session.Mu.RUnlock()

		if currentStatus == types.StatusConnected {
			ln.logger.WithFields(logrus.Fields{
				"session_id":   session.ID,
				"old_status":   currentStatus,
				"new_status":   types.StatusDisconnected,
				"grace_period": ln.disconnectGracePeriod,
			}).Info("Processing account disconnection event (grace period active)")

			// Update session status (thread-safe)
			session.Mu.Lock()
			session.Status = types.StatusDisconnected
			session.Mu.Unlock()

			// Schedule delayed failure notification
			ln.scheduleDisconnectionNotification(session)
		}

	case "logged_out":
		// Cancel any pending disconnection notification
		ln.cancelPendingDisconnection(session.ID)

		// Read current status for logging (thread-safe)
		session.Mu.RLock()
		oldStatus := session.Status
		session.Mu.RUnlock()

		// Notify immediately on logout (no grace period)
		ln.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"old_status": oldStatus,
			"new_status": types.StatusLoggedOut,
		}).Info("Processing account logout event")

		// Update session status (thread-safe)
		session.Mu.Lock()
		session.Status = types.StatusLoggedOut
		session.Mu.Unlock()

		// Send immediate failure notification (bypass grace period)
		ln.NotifyAccountUnlinkedBackground(session)

	default:
		ln.logger.WithFields(logrus.Fields{
			"session_id":  session.ID,
			"event_type":  eventType,
			"whatsapp_id": whatsappID,
		}).Debug("Ignoring unknown connection event type")
	}
}

// scheduleDisconnectionNotification schedules a delayed disconnection notification
func (ln *LinkNotifier) scheduleDisconnectionNotification(session *types.Session) {
	// Cancel any existing timer for this session
	ln.cancelPendingDisconnection(session.ID)

	// Create new timer for delayed notification
	timer := time.AfterFunc(ln.disconnectGracePeriod, func() {
		// Check if session still exists and is still disconnected
		currentSession, exists := ln.sessionMgr.GetSession(session.ID)
		if !exists {
			ln.logger.WithField("session_id", session.ID).
				Debug("Session no longer exists, skipping disconnection notification")
			return
		}

		// Only notify if still disconnected (not reconnected during grace period)
		if currentSession.Status == types.StatusDisconnected {
			ln.logger.WithFields(logrus.Fields{
				"session_id":   session.ID,
				"grace_period": ln.disconnectGracePeriod,
			}).Info("Grace period expired, sending disconnection notification")

			// Send failure notification
			if err := ln.NotifyAccountUnlinked(currentSession); err != nil {
				ln.logger.WithError(err).WithField("session_id", currentSession.ID).
					Error("Failed to send disconnection notification after grace period")
			}
		} else {
			ln.logger.WithFields(logrus.Fields{
				"session_id":     session.ID,
				"current_status": currentSession.Status,
			}).Info("Session reconnected during grace period, skipping disconnection notification")
		}

		// Remove timer from pending map
		ln.pendingDisconnections.Delete(session.ID)
	})

	// Store timer in pending map
	ln.pendingDisconnections.Store(session.ID, timer)

	ln.logger.WithFields(logrus.Fields{
		"session_id":   session.ID,
		"grace_period": ln.disconnectGracePeriod,
	}).Debug("Scheduled disconnection notification with grace period")
}

// cancelPendingDisconnection cancels any pending disconnection notification for a session
func (ln *LinkNotifier) cancelPendingDisconnection(sessionID string) {
	if timerInterface, exists := ln.pendingDisconnections.LoadAndDelete(sessionID); exists {
		timer := timerInterface.(*time.Timer)
		if timer.Stop() {
			ln.logger.WithField("session_id", sessionID).
				Info("Cancelled pending disconnection notification (session reconnected)")
		}
	}
}
