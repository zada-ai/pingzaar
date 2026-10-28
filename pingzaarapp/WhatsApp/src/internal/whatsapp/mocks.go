package whatsapp

import (
	"bytes"
	"context"
	"io"
	"net/http"
	"sync"
	"time"

	"go.mau.fi/whatsmeow/types/events"

	"whatsapp-server/pkg/types"
)

// MockHTTPClient is a mock implementation of HTTPClient for testing.
type MockHTTPClient struct {
	DoFunc func(req *http.Request) (*http.Response, error)
	Calls  []http.Request
	mu     sync.Mutex
}

// Do executes the mock HTTP request.
func (m *MockHTTPClient) Do(req *http.Request) (*http.Response, error) {
	m.mu.Lock()
	m.Calls = append(m.Calls, *req)
	m.mu.Unlock()

	if m.DoFunc != nil {
		return m.DoFunc(req)
	}
	// Default: return empty 200 OK response
	return &http.Response{
		StatusCode: http.StatusOK,
		Body:       io.NopCloser(bytes.NewReader([]byte{})),
	}, nil
}

// Reset clears all recorded calls.
func (m *MockHTTPClient) Reset() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.Calls = nil
}

// CallCount returns the number of calls made.
func (m *MockHTTPClient) CallCount() int {
	m.mu.Lock()
	defer m.mu.Unlock()
	return len(m.Calls)
}

// MockMessageDeduplicator is a mock implementation of MessageDeduplicatorInterface for testing.
type MockMessageDeduplicator struct {
	processed map[string]time.Time
	mu        sync.Mutex
}

// NewMockMessageDeduplicator creates a new mock deduplicator.
func NewMockMessageDeduplicator() *MockMessageDeduplicator {
	return &MockMessageDeduplicator{
		processed: make(map[string]time.Time),
	}
}

// IsProcessed checks if a message key has been processed.
func (m *MockMessageDeduplicator) IsProcessed(messageKey string) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	_, exists := m.processed[messageKey]
	return exists
}

// MarkProcessed marks a message key as processed.
func (m *MockMessageDeduplicator) MarkProcessed(messageKey string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.processed[messageKey] = time.Now()
}

// Reset clears all processed messages.
func (m *MockMessageDeduplicator) Reset() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.processed = make(map[string]time.Time)
}

// Stop is a no-op for the mock.
func (m *MockMessageDeduplicator) Stop() {}

// ProcessedCount returns the number of processed messages.
func (m *MockMessageDeduplicator) ProcessedCount() int {
	m.mu.Lock()
	defer m.mu.Unlock()
	return len(m.processed)
}

// MockSessionManager is a mock implementation of SessionManagerInterface for testing.
type MockSessionManager struct {
	Sessions                  map[string]*types.Session
	CreateSessionFunc         func(ctx context.Context, cache *types.SessionCache) (*types.Session, error)
	GetSessionFunc            func(sessionID string) (*types.Session, bool)
	GetSessionByParamsFunc    func(siteUnique, unique string) (*types.Session, bool)
	DeleteSessionFunc         func(ctx context.Context, sessionID string) error
	IsSessionConnectedFunc    func(sessionID string) bool
	GetSessionStatusFunc      func(sessionID string) string
	OnMessagesAddedFunc       func(sessionID string)
	TriggerMessageProcFunc    func(sessionID, eventType string)
	NotifyAccountLinkedFunc   func(sessionID, whatsappID string)
	NotifyAccountUnlinkedFunc func(sessionID string)
	mu                        sync.RWMutex
}

// NewMockSessionManager creates a new mock session manager.
func NewMockSessionManager() *MockSessionManager {
	return &MockSessionManager{
		Sessions: make(map[string]*types.Session),
	}
}

// CreateSession creates a mock session.
func (m *MockSessionManager) CreateSession(ctx context.Context, cache *types.SessionCache) (*types.Session, error) {
	if m.CreateSessionFunc != nil {
		return m.CreateSessionFunc(ctx, cache)
	}
	session := &types.Session{
		ID:           cache.Unique + "_" + cache.SiteUnique,
		Cache:        cache,
		Status:       types.StatusDisconnected,
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}
	m.mu.Lock()
	m.Sessions[session.ID] = session
	m.mu.Unlock()
	return session, nil
}

// GetSession retrieves a session by ID.
func (m *MockSessionManager) GetSession(sessionID string) (*types.Session, bool) {
	if m.GetSessionFunc != nil {
		return m.GetSessionFunc(sessionID)
	}
	m.mu.RLock()
	defer m.mu.RUnlock()
	session, exists := m.Sessions[sessionID]
	return session, exists
}

// GetSessionByParams retrieves a session by site unique and unique.
func (m *MockSessionManager) GetSessionByParams(siteUnique, unique string) (*types.Session, bool) {
	if m.GetSessionByParamsFunc != nil {
		return m.GetSessionByParamsFunc(siteUnique, unique)
	}
	sessionID := unique + "_" + siteUnique
	return m.GetSession(sessionID)
}

// DeleteSession deletes a session.
func (m *MockSessionManager) DeleteSession(ctx context.Context, sessionID string) error {
	if m.DeleteSessionFunc != nil {
		return m.DeleteSessionFunc(ctx, sessionID)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	delete(m.Sessions, sessionID)
	return nil
}

// GetTotalSessions returns the total number of sessions.
func (m *MockSessionManager) GetTotalSessions() int {
	m.mu.RLock()
	defer m.mu.RUnlock()
	return len(m.Sessions)
}

// ConnectSession returns a mock QR result.
func (m *MockSessionManager) ConnectSession(ctx context.Context, sessionID string) (*QRResult, error) {
	return &QRResult{Event: "qr", Code: "mock-qr-code"}, nil
}

// IsSessionConnected checks if a session is connected.
func (m *MockSessionManager) IsSessionConnected(sessionID string) bool {
	if m.IsSessionConnectedFunc != nil {
		return m.IsSessionConnectedFunc(sessionID)
	}
	m.mu.RLock()
	defer m.mu.RUnlock()
	session, exists := m.Sessions[sessionID]
	if !exists {
		return false
	}
	return session.Status == types.StatusConnected
}

// GetSessionStatus returns the session status as a string.
func (m *MockSessionManager) GetSessionStatus(sessionID string) string {
	if m.GetSessionStatusFunc != nil {
		return m.GetSessionStatusFunc(sessionID)
	}
	m.mu.RLock()
	defer m.mu.RUnlock()
	session, exists := m.Sessions[sessionID]
	if !exists {
		return "not_found"
	}
	// Convert status to string like the real implementation
	switch session.Status {
	case types.StatusConnected:
		return "connected"
	case types.StatusConnecting:
		return "connecting"
	case types.StatusLoggedOut:
		return "logged_out"
	default:
		return "disconnected"
	}
}

// RecoverSessions is a no-op for the mock.
func (m *MockSessionManager) RecoverSessions(ctx context.Context) error {
	return nil
}

// SaveSessionCache saves session cache.
func (m *MockSessionManager) SaveSessionCache(sessionID string, cache *types.SessionCache) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if session, exists := m.Sessions[sessionID]; exists {
		session.Cache = cache
	}
	return nil
}

// OnMessagesAdded triggers message processing.
func (m *MockSessionManager) OnMessagesAdded(sessionID string) {
	if m.OnMessagesAddedFunc != nil {
		m.OnMessagesAddedFunc(sessionID)
	}
}

// NotifyAccountLinked notifies account linked.
func (m *MockSessionManager) NotifyAccountLinked(sessionID, whatsappID string) {
	if m.NotifyAccountLinkedFunc != nil {
		m.NotifyAccountLinkedFunc(sessionID, whatsappID)
	}
}

// NotifyAccountUnlinked notifies account unlinked.
func (m *MockSessionManager) NotifyAccountUnlinked(sessionID string) {
	if m.NotifyAccountUnlinkedFunc != nil {
		m.NotifyAccountUnlinkedFunc(sessionID)
	}
}

// NotifyAccountConnected notifies account connected.
func (m *MockSessionManager) NotifyAccountConnected(sessionID string) {}

// NotifyAccountLoggedOut notifies account logged out.
func (m *MockSessionManager) NotifyAccountLoggedOut(sessionID string) {}

// TriggerMessageProcessing triggers message processing.
func (m *MockSessionManager) TriggerMessageProcessing(sessionID, eventType string) {
	if m.TriggerMessageProcFunc != nil {
		m.TriggerMessageProcFunc(sessionID, eventType)
	}
}

// Shutdown is a no-op for the mock.
func (m *MockSessionManager) Shutdown() {}

// RangeSessions iterates over all sessions.
func (m *MockSessionManager) RangeSessions(fn func(sessionID string, session *types.Session) bool) {
	m.mu.RLock()
	// Copy to avoid holding lock during callback
	sessions := make(map[string]*types.Session)
	for k, v := range m.Sessions {
		sessions[k] = v
	}
	m.mu.RUnlock()

	for sessionID, session := range sessions {
		if !fn(sessionID, session) {
			break
		}
	}
}

// AddSession adds a session to the mock manager.
func (m *MockSessionManager) AddSession(session *types.Session) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.Sessions[session.ID] = session
}

// MockMessageSender is a mock implementation of MessageSenderInterface for testing.
type MockMessageSender struct {
	SendFunc  func(session *types.Session, message types.QueuedMessage) bool
	SendCalls []SendCall
	mu        sync.Mutex
}

// SendCall records a call to SendWhatsAppMessage.
type SendCall struct {
	Session *types.Session
	Message types.QueuedMessage
}

// SendWhatsAppMessage sends a mock message.
func (m *MockMessageSender) SendWhatsAppMessage(session *types.Session, message types.QueuedMessage) bool {
	m.mu.Lock()
	m.SendCalls = append(m.SendCalls, SendCall{Session: session, Message: message})
	m.mu.Unlock()

	if m.SendFunc != nil {
		return m.SendFunc(session, message)
	}
	return true
}

// Reset clears all recorded calls.
func (m *MockMessageSender) Reset() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.SendCalls = nil
}

// CallCount returns the number of calls made.
func (m *MockMessageSender) CallCount() int {
	m.mu.Lock()
	defer m.mu.Unlock()
	return len(m.SendCalls)
}

// MockStatusReporter is a mock implementation of StatusReporterInterface for testing.
type MockStatusReporter struct {
	QueueFunc   func(session *types.Session, message types.QueuedMessage, success bool) error
	QueueError  error // Error to return from QueueStatusReport
	ReportCalls []StatusReportCall
	mu          sync.Mutex
}

// StatusReportCall records a call to QueueStatusReport.
type StatusReportCall struct {
	SessionID string
	Message   types.QueuedMessage
	Success   bool
}

// QueueStatusReport queues a mock status report.
func (m *MockStatusReporter) QueueStatusReport(session *types.Session, message types.QueuedMessage, success bool) error {
	m.mu.Lock()
	m.ReportCalls = append(m.ReportCalls, StatusReportCall{
		SessionID: session.ID,
		Message:   message,
		Success:   success,
	})
	queueErr := m.QueueError
	m.mu.Unlock()

	if m.QueueFunc != nil {
		return m.QueueFunc(session, message, success)
	}
	return queueErr
}

// Shutdown is a no-op for the mock.
func (m *MockStatusReporter) Shutdown() {}

// Reset clears all recorded calls.
func (m *MockStatusReporter) Reset() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.ReportCalls = nil
}

// CallCount returns the number of calls made.
func (m *MockStatusReporter) CallCount() int {
	m.mu.Lock()
	defer m.mu.Unlock()
	return len(m.ReportCalls)
}

// MockWebhookService is a mock implementation of WebhookServiceInterface for testing.
type MockWebhookService struct {
	SendFunc  func(evt *events.Message, session *types.Session) error
	SendCalls []WebhookSendCall
	mu        sync.Mutex
}

// WebhookSendCall records a call to SendMessageReceived.
type WebhookSendCall struct {
	Event   *events.Message
	Session *types.Session
}

// SendMessageReceived sends a mock webhook.
func (m *MockWebhookService) SendMessageReceived(evt *events.Message, session *types.Session) error {
	m.mu.Lock()
	m.SendCalls = append(m.SendCalls, WebhookSendCall{Event: evt, Session: session})
	m.mu.Unlock()

	if m.SendFunc != nil {
		return m.SendFunc(evt, session)
	}
	return nil
}

// Reset clears all recorded calls.
func (m *MockWebhookService) Reset() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.SendCalls = nil
}

// CallCount returns the number of calls made.
func (m *MockWebhookService) CallCount() int {
	m.mu.Lock()
	defer m.mu.Unlock()
	return len(m.SendCalls)
}

// MockMessageFetcher is a mock implementation of MessageFetcherInterface for testing.
type MockMessageFetcher struct {
	FetchFunc  func(session *types.Session) error
	FetchCalls []*types.Session
	mu         sync.Mutex
}

// FetchMessagesOnDemand fetches mock messages.
func (m *MockMessageFetcher) FetchMessagesOnDemand(session *types.Session) error {
	m.mu.Lock()
	m.FetchCalls = append(m.FetchCalls, session)
	m.mu.Unlock()

	if m.FetchFunc != nil {
		return m.FetchFunc(session)
	}
	return nil
}

// Reset clears all recorded calls.
func (m *MockMessageFetcher) Reset() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.FetchCalls = nil
}

// MockEventDrivenProcessor is a mock implementation of EventDrivenProcessorInterface for testing.
type MockEventDrivenProcessor struct {
	StartCalled          bool
	StopCalled           bool
	TriggerCalls         []ProcessorTriggerCall
	OnMessagesAddedCalls []string
	mu                   sync.Mutex
}

// ProcessorTriggerCall records a call to TriggerProcessing.
type ProcessorTriggerCall struct {
	SessionID string
	EventType string
}

// Start marks the processor as started.
func (m *MockEventDrivenProcessor) Start() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.StartCalled = true
}

// Stop marks the processor as stopped.
func (m *MockEventDrivenProcessor) Stop() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.StopCalled = true
}

// TriggerProcessing records a trigger call.
func (m *MockEventDrivenProcessor) TriggerProcessing(sessionID, eventType string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.TriggerCalls = append(m.TriggerCalls, ProcessorTriggerCall{
		SessionID: sessionID,
		EventType: eventType,
	})
}

// OnMessagesAdded records a messages added call.
func (m *MockEventDrivenProcessor) OnMessagesAdded(sessionID string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.OnMessagesAddedCalls = append(m.OnMessagesAddedCalls, sessionID)
}

// Reset clears all recorded state.
func (m *MockEventDrivenProcessor) Reset() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.StartCalled = false
	m.StopCalled = false
	m.TriggerCalls = nil
	m.OnMessagesAddedCalls = nil
}

// MockLinkNotifier is a mock implementation of LinkNotifierInterface for testing.
type MockLinkNotifier struct {
	LinkedCalls   []LinkNotifierCall
	UnlinkedCalls []string
	mu            sync.Mutex
}

// LinkNotifierCall records a call to NotifyAccountLinked.
type LinkNotifierCall struct {
	Session    *types.Session
	WhatsAppID string
}

// NotifyAccountLinked records a linked notification.
func (m *MockLinkNotifier) NotifyAccountLinked(session *types.Session, whatsappID string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.LinkedCalls = append(m.LinkedCalls, LinkNotifierCall{Session: session, WhatsAppID: whatsappID})
	return nil
}

// NotifyAccountUnlinked records an unlinked notification.
func (m *MockLinkNotifier) NotifyAccountUnlinked(session *types.Session) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.UnlinkedCalls = append(m.UnlinkedCalls, session.ID)
	return nil
}

// ProcessConnectionEvent is a no-op for the mock.
func (m *MockLinkNotifier) ProcessConnectionEvent(session *types.Session, eventType string, whatsappID string) {
}

// NotifyAccountLinkedBackground is a no-op for the mock.
func (m *MockLinkNotifier) NotifyAccountLinkedBackground(session *types.Session, whatsappID string) {}

// NotifyAccountUnlinkedBackground is a no-op for the mock.
func (m *MockLinkNotifier) NotifyAccountUnlinkedBackground(session *types.Session) {}

// Reset clears all recorded calls.
func (m *MockLinkNotifier) Reset() {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.LinkedCalls = nil
	m.UnlinkedCalls = nil
}
