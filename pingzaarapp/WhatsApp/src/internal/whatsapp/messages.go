package whatsapp

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/sirupsen/logrus"
	"go.mau.fi/whatsmeow/binary/proto"
	"go.mau.fi/whatsmeow/types"

	goTypes "whatsapp-server/pkg/types"
)

// MessageService handles sending and receiving messages
type MessageService struct {
	sessionMgr *SessionManager
	logger     *logrus.Logger
}

// NewMessageService creates a new message service
func NewMessageService(sessionMgr *SessionManager, logger *logrus.Logger) *MessageService {
	return &MessageService{
		sessionMgr: sessionMgr,
		logger:     logger,
	}
}

// SendTextMessage sends a text message using the specified session with retry logic
func (ms *MessageService) SendTextMessage(ctx context.Context, sessionID, to, message string) error {
	session, exists := ms.sessionMgr.GetSession(sessionID)
	if !exists {
		return fmt.Errorf("session %s not found", sessionID)
	}

	if !ms.sessionMgr.IsSessionConnected(sessionID) {
		return fmt.Errorf("session %s is not connected", sessionID)
	}

	jid, err := ms.parseJID(to)
	if err != nil {
		return fmt.Errorf("invalid recipient: %w", err)
	}

	msg := &proto.Message{
		Conversation: &message,
	}

	// Implement retry logic with exponential backoff
	maxRetries := 3
	var lastErr error

	for attempt := 1; attempt <= maxRetries; attempt++ {
		_, err = session.Client.SendMessage(ctx, jid, msg)
		if err == nil {
			ms.logger.WithFields(logrus.Fields{
				"session_id": sessionID,
				"to":         to,
				"message":    message,
				"attempt":    attempt,
			}).Info("Text message sent successfully")
			return nil
		}

		lastErr = err

		// Don't retry on permanent errors
		if strings.Contains(err.Error(), "not registered") ||
			strings.Contains(err.Error(), "forbidden") {
			ms.logger.WithFields(logrus.Fields{
				"session_id": sessionID,
				"to":         to,
				"error":      err,
			}).Warn("Permanent error, not retrying")
			return fmt.Errorf("permanent error: %w", err)
		}

		// Exponential backoff
		if attempt < maxRetries {
			delay := time.Duration(attempt*attempt) * time.Second
			ms.logger.WithFields(logrus.Fields{
				"session_id": sessionID,
				"to":         to,
				"attempt":    attempt,
				"delay":      delay,
				"error":      err,
			}).Warn("Message send failed, retrying after delay")

			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(delay):
				// Continue with retry
			}
		}
	}

	return fmt.Errorf("failed to send message after %d attempts: %w", maxRetries, lastErr)
}

// SendMessageByParams sends a message using site_unique and unique parameters
func (ms *MessageService) SendMessageByParams(ctx context.Context, siteUnique, unique, to, message string) error {
	sessionID := fmt.Sprintf("%s_%s", unique, siteUnique)
	return ms.SendTextMessage(ctx, sessionID, to, message)
}

// CheckNumberExists checks if a WhatsApp number exists
func (ms *MessageService) CheckNumberExists(ctx context.Context, sessionID, number string) (bool, error) {
	session, exists := ms.sessionMgr.GetSession(sessionID)
	if !exists {
		return false, fmt.Errorf("session %s not found", sessionID)
	}

	if !ms.sessionMgr.IsSessionConnected(sessionID) {
		return false, fmt.Errorf("session %s is not connected", sessionID)
	}

	jid, err := ms.parseJID(number)
	if err != nil {
		return false, err
	}

	// For groups, try to get metadata
	if jid.Server == types.GroupServer {
		_, err := session.Client.GetGroupInfo(context.Background(), jid)
		return err == nil, nil
	}

	// For regular numbers, check if they're on WhatsApp
	resp, err := session.Client.IsOnWhatsApp(context.Background(), []string{jid.User})
	if err != nil {
		return false, err
	}

	if len(resp) > 0 {
		return resp[0].IsIn, nil
	}

	return false, nil
}

// parseJID converts a phone number or JID string to a proper JID.
// Handles three server types:
//   - @g.us (groups)
//   - @lid (LID addressing mode, rolling out globally)
//   - @s.whatsapp.net (traditional phone-based addressing)
//
// Any input already containing "@" is passed through ParseJID unchanged;
// bare digits are normalized and assumed to be @s.whatsapp.net phone numbers.
func (ms *MessageService) parseJID(recipient string) (types.JID, error) {
	if strings.Contains(recipient, "@") {
		return types.ParseJID(recipient)
	}

	// For phone numbers without an explicit server, default to @s.whatsapp.net.
	phoneNumber := strings.ReplaceAll(recipient, "+", "")
	phoneNumber = strings.ReplaceAll(phoneNumber, "-", "")
	phoneNumber = strings.ReplaceAll(phoneNumber, " ", "")

	return types.ParseJID(phoneNumber + "@s.whatsapp.net")
}

// GetMessageQueue returns pending messages for a session
func (ms *MessageService) GetMessageQueue(sessionID string) ([]goTypes.IncomingMessage, error) {
	session, exists := ms.sessionMgr.GetSession(sessionID)
	if !exists {
		return nil, fmt.Errorf("session %s not found", sessionID)
	}

	// Get messages from session queue
	// In a production implementation, this would be a persistent queue
	// For now, we'll return empty queue as messages are processed immediately
	ms.logger.WithFields(logrus.Fields{
		"session_id": sessionID,
		"queue_size": 0,
	}).Debug("Getting message queue")

	// Update last activity (thread-safe)
	session.Mu.Lock()
	session.LastActivity = time.Now()
	session.Mu.Unlock()

	return []goTypes.IncomingMessage{}, nil
}
