package whatsapp

import (
	"context"
	"io"
	"net/http"

	"go.mau.fi/whatsmeow/types/events"

	"whatsapp-server/pkg/types"
)

// HTTPClient abstracts HTTP operations for testability.
// This interface allows injecting mock HTTP clients in tests.
type HTTPClient interface {
	Do(req *http.Request) (*http.Response, error)
}

// SessionManagerInterface defines session management operations.
// Implemented by SessionManager for production use.
type SessionManagerInterface interface {
	CreateSession(ctx context.Context, cache *types.SessionCache) (*types.Session, error)
	GetSession(sessionID string) (*types.Session, bool)
	GetSessionByParams(siteUnique, unique string) (*types.Session, bool)
	DeleteSession(ctx context.Context, sessionID string) error
	GetTotalSessions() int
	ConnectSession(ctx context.Context, sessionID string) (*QRResult, error)
	IsSessionConnected(sessionID string) bool
	GetSessionStatus(sessionID string) string
	RecoverSessions(ctx context.Context) error
	SaveSessionCache(sessionID string, cache *types.SessionCache) error
	OnMessagesAdded(sessionID string)
	NotifyAccountLinked(sessionID, whatsappID string)
	NotifyAccountUnlinked(sessionID string)
	NotifyAccountConnected(sessionID string)
	NotifyAccountLoggedOut(sessionID string)
	TriggerMessageProcessing(sessionID, eventType string)
	// RangeSessions iterates over all sessions. The callback receives session ID and session.
	// Return false from the callback to stop iteration.
	RangeSessions(fn func(sessionID string, session *types.Session) bool)
	Shutdown()
}

// MessageSenderInterface defines message sending operations.
// Implemented by MessageSender for production use.
type MessageSenderInterface interface {
	SendWhatsAppMessage(session *types.Session, message types.QueuedMessage) bool
}

// WebhookServiceInterface defines webhook delivery operations.
// Implemented by WebhookService for production use.
type WebhookServiceInterface interface {
	SendMessageReceived(evt *events.Message, session *types.Session) error
}

// MessageDeduplicatorInterface abstracts message deduplication.
// This replaces the global state in webhook.go for better testability.
type MessageDeduplicatorInterface interface {
	// IsProcessed checks if a message has already been processed
	IsProcessed(messageKey string) bool
	// MarkProcessed marks a message as processed
	MarkProcessed(messageKey string)
	// Reset clears all processed messages (used in tests)
	Reset()
	// Stop stops any background cleanup goroutines
	Stop()
}

// StatusReporterInterface defines status reporting operations.
// Implemented by StatusReporter for production use.
type StatusReporterInterface interface {
	QueueStatusReport(session *types.Session, message types.QueuedMessage, success bool) error
	Shutdown()
}

// MessageFetcherInterface defines message fetching operations.
// Implemented by MessageFetcher for production use.
type MessageFetcherInterface interface {
	FetchMessagesOnDemand(session *types.Session) error
}

// LinkNotifierInterface defines link notification operations.
// Implemented by LinkNotifier for production use.
type LinkNotifierInterface interface {
	NotifyAccountLinked(session *types.Session, whatsappID string) error
	NotifyAccountUnlinked(session *types.Session) error
	ProcessConnectionEvent(session *types.Session, eventType string, whatsappID string)
	NotifyAccountLinkedBackground(session *types.Session, whatsappID string)
	NotifyAccountUnlinkedBackground(session *types.Session)
}

// ContactServiceInterface defines contact operations.
// Implemented by ContactService for production use.
type ContactServiceInterface interface {
	GetGroups(sessionID string) ([]GroupInfo, error)
	ValidateNumber(sessionID, phone string) (*ValidationResult, error)
}

// FileServiceInterface defines file operations.
// Implemented by FileService for production use.
type FileServiceInterface interface {
	DownloadMedia(siteUnique, unique, filename string) (io.ReadSeeker, int64, error)
	CleanupOldFiles(days int) error
}

// MessageServiceInterface defines message service operations.
// Implemented by MessageService for production use.
type MessageServiceInterface interface {
	SendTextMessage(ctx context.Context, sessionID, to, message string) error
}

// EventHandlerInterface defines event handling operations.
// Implemented by EventHandler for production use.
type EventHandlerInterface interface {
	Handle(evt interface{})
	HandleWithSession(evt interface{}, sessionID string)
}

// EventDrivenProcessorInterface defines event processor operations.
// Implemented by EventDrivenProcessor for production use.
type EventDrivenProcessorInterface interface {
	Start()
	Stop()
	TriggerProcessing(sessionID, eventType string)
	OnMessagesAdded(sessionID string)
}
