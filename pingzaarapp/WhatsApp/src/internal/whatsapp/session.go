package whatsapp

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/sirupsen/logrus"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waCompanionReg"
	"go.mau.fi/whatsmeow/store"
	"go.mau.fi/whatsmeow/store/sqlstore"
	waLog "go.mau.fi/whatsmeow/util/log"
	"google.golang.org/protobuf/proto"

	"whatsapp-server/internal/config"
	"whatsapp-server/pkg/types"
)

// devicePropsOnce ensures device props are set only once to avoid race conditions
var devicePropsOnce sync.Once

// SessionManager manages multiple WhatsApp sessions
type SessionManager struct {
	sessions       sync.Map            // map[string]*types.Session
	container      *sqlstore.Container // shared container for "shared" strategy
	containers     sync.Map            // map[string]*sqlstore.Container for "per_session" strategy
	config         *config.Config
	logger         *logrus.Logger
	eventHandler   *EventHandler
	webhookService *WebhookService
	eventProcessor *EventDrivenProcessor // Event-driven message processor
	linkNotifier   *LinkNotifier         // Account connection notifier

	// shutdownCtx is shared with every whatsmeow.Client as BackgroundEventCtx.
	// Cancelling it on Shutdown propagates into auto-reconnect goroutines so
	// they exit promptly instead of surviving past server shutdown.
	shutdownCtx    context.Context
	shutdownCancel context.CancelFunc
}

// NewSessionManager creates a new session manager
func NewSessionManager(cfg *config.Config, logger *logrus.Logger) (*SessionManager, error) {
	ctx, cancel := context.WithCancel(context.Background())
	sm := &SessionManager{
		config:         cfg,
		logger:         logger,
		shutdownCtx:    ctx,
		shutdownCancel: cancel,
	}

	// Create sessions directory for per-session databases
	if err := os.MkdirAll("./sessions", 0755); err != nil {
		cancel() // release context resources on early exit
		return nil, fmt.Errorf("failed to create sessions directory: %w", err)
	}

	sm.eventHandler = NewEventHandler(sm, logger)
	sm.webhookService = NewWebhookService(sm, logger)
	sm.linkNotifier = NewLinkNotifier(sm, logger)
	sm.eventProcessor = NewEventDrivenProcessor(sm, logger)

	// Start the event-driven message processor
	sm.eventProcessor.Start()

	sm.logger.Info("Session manager initialized with event-driven processor and link notifier")

	return sm, nil
}

// CreateSession creates a new WhatsApp session
func (sm *SessionManager) CreateSession(ctx context.Context, cache *types.SessionCache) (*types.Session, error) {
	sessionID := fmt.Sprintf("%s_%s", cache.Unique, cache.SiteUnique)

	// Check if session already exists
	if _, exists := sm.sessions.Load(sessionID); exists {
		return nil, fmt.Errorf("session %s already exists", sessionID)
	}

	// Set default account settings if not provided
	if cache.ReceiveChats == 0 {
		cache.ReceiveChats = 2 // Default: receive chats enabled
	}
	if cache.RandomSend == 0 {
		cache.RandomSend = 1 // Default: random delay
	}
	if cache.RandomMin == 0 {
		cache.RandomMin = 1 // Default: 1 second minimum
	}
	if cache.RandomMax == 0 {
		cache.RandomMax = 5 // Default: 5 seconds maximum
	}

	// Get database container for this session
	container, dbPath, err := sm.getDatabaseContainer(sessionID)
	if err != nil {
		return nil, fmt.Errorf("failed to get database container: %w", err)
	}

	// Initialize message queue table in database (auto-creates if not exists)
	if err := initMessageQueueTable(dbPath, sm.logger); err != nil {
		sm.logger.WithError(err).WithField("session_id", sessionID).
			Warn("Failed to initialize message queue table")
		// Continue anyway - non-critical for new sessions
	}

	// Get first device store for this session (reuses existing device if available)
	deviceStore, err := container.GetFirstDevice(ctx)
	if err != nil {
		return nil, fmt.Errorf("failed to get device store: %w", err)
	}

	// Configure custom device name (Method 3: Desktop platform type)
	// Use sync.Once to avoid race conditions when creating multiple sessions concurrently
	devicePropsOnce.Do(func() {
		store.DeviceProps.PlatformType = waCompanionReg.DeviceProps_DESKTOP.Enum()
		store.DeviceProps.Os = proto.String("Windows")
	})

	// Create WhatsApp client with dedicated device store
	clientLog := NewQuietClientLog("Client", "ERROR", true)
	client := whatsmeow.NewClient(deviceStore, clientLog)

	// Apply reliability flags + shutdown-aware context
	sm.configureClient(client, sessionID)

	// Configure proxy if enabled
	if err := sm.configureProxy(client); err != nil {
		sm.logger.WithError(err).WithField("session_id", sessionID).
			Warn("Failed to configure proxy, continuing without proxy")
		// Don't fail session creation, just log warning
	}

	session := &types.Session{
		ID:           sessionID,
		Client:       client,
		DeviceStore:  deviceStore,
		DeviceJID:    deviceStore.ID, // Track device JID for recovery
		Cache:        cache,
		Status:       types.StatusDisconnected,
		CreatedAt:    time.Now(),
		LastActivity: time.Now(),
		DBPath:       dbPath, // Store DB path for session tracking

		// Initialize message queue and sending state
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),

		// Initialize campaign pause state tracking
		PausedCampaigns: make(map[int]bool),
	}

	// Open persistent database connection for message queue operations (connection pooling)
	queueDB, err := openQueueDB(dbPath, sm.logger)
	if err != nil {
		sm.logger.WithError(err).WithField("session_id", sessionID).
			Warn("Failed to open queue database, will use fallback connections")
		// Don't fail session creation - functions will fallback to opening new connections
	} else {
		session.QueueDB = queueDB
	}

	// Create session-specific event handler with session context
	eventHandlerID := client.AddEventHandler(func(evt interface{}) {
		sm.eventHandler.HandleWithSession(evt, sessionID)
	})
	session.EventHandlerID = eventHandlerID

	// Store session
	sm.sessions.Store(sessionID, session)

	// Save session cache for recovery
	if err := sm.SaveSessionCache(sessionID, cache); err != nil {
		sm.logger.WithError(err).WithField("session_id", sessionID).
			Error("Failed to save session cache")
		// Don't fail session creation, just log the error
	}

	sm.logger.WithFields(logrus.Fields{
		"session_id": sessionID,
		"db_path":    dbPath,
		"strategy":   "per_session",
		"site_url":   cache.SiteURL,
	}).Info("Session created with unique device store")

	return session, nil
}

// GetSession retrieves a session by ID
func (sm *SessionManager) GetSession(sessionID string) (*types.Session, bool) {
	if s, exists := sm.sessions.Load(sessionID); exists {
		return s.(*types.Session), true
	}
	return nil, false
}

// GetSessionByParams retrieves a session by site_unique and unique
func (sm *SessionManager) GetSessionByParams(siteUnique, unique string) (*types.Session, bool) {
	sessionID := fmt.Sprintf("%s_%s", unique, siteUnique)
	return sm.GetSession(sessionID)
}

// cleanupSessionFiles removes all files associated with a session
func (sm *SessionManager) cleanupSessionFiles(sessionID string) error {
	var errors []error

	// Clean up session database file
	dbPath := filepath.Join("./sessions", sessionID+".db")
	if err := os.Remove(dbPath); err != nil && !os.IsNotExist(err) {
		errors = append(errors, fmt.Errorf("failed to remove database file %s: %w", dbPath, err))
	} else if err == nil {
		sm.logger.WithField("file", dbPath).Debug("Removed session database file")
	}

	// Clean up SQLite WAL and SHM files (may not exist, ignore errors)
	walPath := dbPath + "-wal"
	shmPath := dbPath + "-shm"
	os.Remove(walPath) // Ignore errors, WAL mode files may not exist
	os.Remove(shmPath)

	// Clean up session cache file
	cachePath := filepath.Join("./storage", "cache", sessionID+".json")
	if err := os.Remove(cachePath); err != nil && !os.IsNotExist(err) {
		errors = append(errors, fmt.Errorf("failed to remove cache file %s: %w", cachePath, err))
	} else if err == nil {
		sm.logger.WithField("file", cachePath).Debug("Removed session cache file")
	}

	// Clean up any additional session-related files in storage directory
	sessionStoragePath := filepath.Join("./storage", sessionID)
	if err := os.RemoveAll(sessionStoragePath); err != nil && !os.IsNotExist(err) {
		errors = append(errors, fmt.Errorf("failed to remove session storage directory %s: %w", sessionStoragePath, err))
	} else if err == nil {
		sm.logger.WithField("directory", sessionStoragePath).Debug("Removed session storage directory")
	}

	if len(errors) > 0 {
		return fmt.Errorf("cleanup errors: %v", errors)
	}

	return nil
}

// closeContainer closes the sqlstore container for a session (releases database lock)
func (sm *SessionManager) closeContainer(sessionID string) {
	if containerInterface, exists := sm.containers.LoadAndDelete(sessionID); exists {
		container := containerInterface.(*sqlstore.Container)
		container.Close()
		sm.logger.WithField("session_id", sessionID).Debug("Closed database container")
	}
}

// forceDeleteDeviceStore calls DeviceStore.Delete with a bounded timeout and
// logs failures. Safe to call with a nil session or nil DeviceStore - returns
// nil in both cases. Used as the fallback when whatsmeow Logout fails or as a
// proactive cleanup for abandoned partial-pair sessions.
func forceDeleteDeviceStore(ctx context.Context, session *types.Session, logger *logrus.Logger) error {
	if session == nil || session.DeviceStore == nil {
		return nil
	}

	deleteCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	if err := session.DeviceStore.Delete(deleteCtx); err != nil {
		logger.WithError(err).WithField("session_id", session.ID).
			Error("Failed to force-delete device store")
		return err
	}

	logger.WithField("session_id", session.ID).
		Info("Device store force-deleted")
	return nil
}

// DeleteSession deletes a session
func (sm *SessionManager) DeleteSession(ctx context.Context, sessionID string) error {
	sessionInterface, exists := sm.sessions.LoadAndDelete(sessionID)
	if !exists {
		return fmt.Errorf("session %s not found", sessionID)
	}

	session := sessionInterface.(*types.Session)

	// Stage 1: Logout from WhatsApp (unpairs device, disconnects, deletes store automatically)
	if session.Client != nil && session.Client.IsLoggedIn() {
		sm.logger.WithField("session_id", sessionID).Info("Logging out from WhatsApp to unpair device")

		// Create context with timeout for logout operation
		logoutCtx, logoutCancel := context.WithTimeout(ctx, 30*time.Second)
		defer logoutCancel()

		if err := session.Client.Logout(logoutCtx); err != nil {
			sm.logger.WithError(err).WithField("session_id", sessionID).
				Warn("Logout failed, forcing manual cleanup")

			// Stage 2: Force cleanup if logout fails.
			// Disconnect the WebSocket, then explicitly delete the device
			// store so stale identity data doesn't linger.
			session.Client.Disconnect()
			_ = forceDeleteDeviceStore(ctx, session, sm.logger)
		} else {
			sm.logger.WithField("session_id", sessionID).Info("Logout successful - device unpaired from WhatsApp")
		}
	} else if session.Client != nil {
		// Not logged in, just disconnect.
		sm.logger.WithField("session_id", sessionID).Debug("Session not logged in, disconnecting only")
		session.Client.Disconnect()

		// If the device was partially paired (Store.ID set but never logged in),
		// force-delete so the abandoned device entry doesn't persist.
		if session.DeviceStore != nil && session.DeviceStore.ID != nil {
			_ = forceDeleteDeviceStore(ctx, session, sm.logger)
		}
	}

	// Stage 3: Close persistent database connection (connection pooling cleanup)
	closeQueueDB(session, sm.logger)

	// Stage 3.5: Close sqlstore container (releases database lock for file deletion)
	sm.closeContainer(sessionID)

	// Stage 4: Clean up all related files
	if err := sm.cleanupSessionFiles(sessionID); err != nil {
		sm.logger.WithError(err).WithField("session_id", sessionID).
			Error("Failed to cleanup session files")
		// Don't return error, just log it - session removal is more important
	}

	sm.logger.WithField("session_id", sessionID).Info("Session deleted and files cleaned up")

	return nil
}

// GetTotalSessions returns the total number of active sessions
func (sm *SessionManager) GetTotalSessions() int {
	count := 0
	sm.sessions.Range(func(key, value interface{}) bool {
		count++
		return true
	})
	return count
}

// RangeSessions iterates over all sessions. The callback receives session ID and session.
// Return false from the callback to stop iteration.
func (sm *SessionManager) RangeSessions(fn func(sessionID string, session *types.Session) bool) {
	sm.sessions.Range(func(key, value interface{}) bool {
		sessionID := key.(string)
		session := value.(*types.Session)
		return fn(sessionID, session)
	})
}

// ConnectSession connects a session to WhatsApp
func (sm *SessionManager) ConnectSession(ctx context.Context, sessionID string) (*QRResult, error) {
	session, exists := sm.GetSession(sessionID)
	if !exists {
		return nil, fmt.Errorf("session %s not found", sessionID)
	}

	session.Status = types.StatusConnecting

	if session.Client.Store.ID == nil {
		// First time login - need QR code
		return sm.handleQRLogin(ctx, session)
	}

	// Already logged in, just connect (use shutdown-aware context)
	if err := session.Client.ConnectContext(sm.shutdownCtx); err != nil {
		session.Status = types.StatusDisconnected
		return nil, fmt.Errorf("failed to connect: %w", err)
	}

	// Status will be updated by Connected event handler (handleConnected -> ProcessConnectionEvent)
	// This prevents race condition where status is set before event fires
	return &QRResult{Event: "success"}, nil
}

// QRResult represents the result of QR code generation
type QRResult struct {
	Event string `json:"event"`
	Code  string `json:"code,omitempty"`
}

func (sm *SessionManager) handleQRLogin(ctx context.Context, session *types.Session) (*QRResult, error) {
	// Use background context for QR channel to avoid timeouts
	qrChan, err := session.Client.GetQRChannel(context.Background())
	if err != nil {
		session.Status = types.StatusDisconnected
		return nil, fmt.Errorf("failed to get QR channel: %w", err)
	}

	if err := session.Client.ConnectContext(sm.shutdownCtx); err != nil {
		session.Status = types.StatusDisconnected
		return nil, fmt.Errorf("failed to connect for QR: %w", err)
	}

	return sm.handleQRChannel(session, qrChan)
}

// handleQRChannel consumes whatsmeow QR channel items until the pairing
// completes, times out, or hits a terminal error. Extracted as a pure function
// (no I/O beyond channel read + logging) so the state machine is unit-testable.
func (sm *SessionManager) handleQRChannel(session *types.Session, qrChan <-chan whatsmeow.QRChannelItem) (*QRResult, error) {
	for evt := range qrChan {
		switch evt.Event {
		case "code":
			return &QRResult{Event: "code", Code: evt.Code}, nil

		case "success":
			// Status will be updated by PairSuccess event handler (handlePairSuccess -> ProcessConnectionEvent)
			// This prevents race condition where status is set before event fires
			return &QRResult{Event: "success"}, nil

		case "timeout":
			session.Status = types.StatusDisconnected
			sm.scheduleAbandonedQRCleanup(session.ID)
			return nil, fmt.Errorf("QR code timeout")

		// whatsmeow terminal error events - surface each as a distinct error so
		// the PHP-side caller can display an actionable message.
		case "err-client-outdated":
			session.Status = types.StatusDisconnected
			return nil, fmt.Errorf("whatsmeow client outdated, update required (run 'make update')")

		case "err-scanned-without-multidevice":
			session.Status = types.StatusDisconnected
			return nil, fmt.Errorf("multi-device not enabled on phone; enable it in WhatsApp settings and scan again")

		case "err-unexpected-state":
			session.Status = types.StatusDisconnected
			return nil, fmt.Errorf("unexpected QR channel state; session may already be paired elsewhere")

		default:
			// Log other events but continue waiting
			sm.logger.WithFields(logrus.Fields{
				"session_id": session.ID,
				"event":      evt.Event,
			}).Debug("QR login event received")
		}
	}

	// QR channel closed without success
	return nil, fmt.Errorf("QR channel closed unexpectedly")
}

// scheduleAbandonedQRCleanup deletes the session 1h after a QR timeout if the
// user never completed pairing. Extracted from handleQRLogin so handleQRChannel
// stays focused on the state machine.
func (sm *SessionManager) scheduleAbandonedQRCleanup(sessionID string) {
	SafeGo(sm.logger, "qr_timeout_cleanup", func() {
		time.Sleep(1 * time.Hour)

		existingSession, exists := sm.GetSession(sessionID)
		if !exists {
			return
		}

		existingSession.Mu.RLock()
		neverPaired := existingSession.Client.Store.ID == nil
		existingSession.Mu.RUnlock()

		if !neverPaired {
			return
		}

		sm.logger.WithField("session_id", sessionID).
			Info("Auto-cleaning abandoned QR session after timeout")
		if err := sm.DeleteSession(context.Background(), sessionID); err != nil {
			sm.logger.WithError(err).WithField("session_id", sessionID).
				Error("Failed to auto-cleanup abandoned QR session")
		}
	})
}

// IsSessionConnected checks if a session is connected
func (sm *SessionManager) IsSessionConnected(sessionID string) bool {
	session, exists := sm.GetSession(sessionID)
	if !exists {
		return false
	}

	session.Mu.RLock()
	status := session.Status
	session.Mu.RUnlock()

	return status == types.StatusConnected && session.Client.IsConnected()
}

// GetSessionStatus gets the status of a session
func (sm *SessionManager) GetSessionStatus(sessionID string) string {
	session, exists := sm.GetSession(sessionID)
	if !exists {
		return "not_found"
	}

	session.Mu.RLock()
	status := session.Status
	session.Mu.RUnlock()

	if status == types.StatusConnected && session.Client.IsConnected() {
		return "connected"
	}

	switch status {
	case types.StatusConnecting:
		return "connecting"
	case types.StatusLoggedOut:
		return "logged_out"
	default:
		return "disconnected"
	}
}

// RecoverSessions recovers existing sessions from database on startup
func (sm *SessionManager) RecoverSessions(ctx context.Context) error {
	return sm.recoverFromPerSessionDatabases(ctx)
}

// recoverFromPerSessionDatabases recovers sessions from individual database files
func (sm *SessionManager) recoverFromPerSessionDatabases(ctx context.Context) error {
	// Check if sessions directory exists
	if _, err := os.Stat("./sessions"); os.IsNotExist(err) {
		sm.logger.Info("Sessions directory doesn't exist, no sessions to recover")
		return nil
	}

	// Read all database files
	files, err := filepath.Glob(filepath.Join("./sessions", "*.db"))
	if err != nil {
		return fmt.Errorf("failed to scan database files: %w", err)
	}

	sm.logger.WithField("db_files", len(files)).Info("Starting per-session database recovery")

	recovered := 0
	sessionIndex := 0 // Track session index for staggered reconnection delays
	for _, dbFile := range files {
		// Extract session ID from filename
		baseName := filepath.Base(dbFile)
		sessionID := strings.TrimSuffix(baseName, ".db")

		// Keep session ID format with underscores to match cache files

		// Create container for this database with WAL mode for concurrent access
		dbLog := waLog.Stdout("Database", "ERROR", true)
		container, err := sqlstore.New(
			ctx,
			"sqlite",
			fmt.Sprintf("file:%s?_pragma=foreign_keys(1)&_pragma=busy_timeout(5000)&_pragma=journal_mode(WAL)&cache=shared", dbFile),
			dbLog,
		)
		if err != nil {
			sm.logger.WithError(err).WithField("db_file", dbFile).
				Warn("Failed to create container for database, skipping")
			continue
		}

		// Store container
		sm.containers.Store(sessionID, container)

		// Get devices from this database
		devices, err := container.GetAllDevices(ctx)
		if err != nil {
			sm.logger.WithError(err).WithField("db_file", dbFile).
				Warn("Failed to get devices from database, skipping")
			// Clean up container to prevent resource leak
			container.Close()
			sm.containers.Delete(sessionID)
			continue
		}

		// Recover sessions from this database
		for _, device := range devices {
			if device.ID == nil {
				continue
			}

			if sm.recoverSingleSession(ctx, sessionID, device, dbFile, sessionIndex) {
				recovered++
			}
			sessionIndex++ // Increment for staggered delays
		}
	}

	sm.logger.WithField("recovered", recovered).Info("Per-session database recovery completed")
	return nil
}

// recoverSingleSession recovers a single session from device data
func (sm *SessionManager) recoverSingleSession(ctx context.Context, sessionID string, device *store.Device, dbPath string, sessionIndex int) bool {
	// Try to load session cache
	cache, err := sm.loadSessionCache(sessionID)
	if err != nil {
		sm.logger.WithError(err).WithField("session_id", sessionID).
			Warn("Failed to load session cache, skipping")
		return false
	}

	// Create client with existing device
	clientLog := NewQuietClientLog("Client", "ERROR", true)
	client := whatsmeow.NewClient(device, clientLog)

	// Apply reliability flags + shutdown-aware context (shared with CreateSession path)
	sm.configureClient(client, sessionID)

	// Configure proxy for recovered session
	if err := sm.configureProxy(client); err != nil {
		sm.logger.WithError(err).WithField("session_id", sessionID).
			Warn("Failed to configure proxy for recovered session")
		// Don't fail recovery, just log warning
	}

	// Initialize message queue table in database (auto-creates if not exists)
	if err := initMessageQueueTable(dbPath, sm.logger); err != nil {
		sm.logger.WithError(err).WithField("session_id", sessionID).
			Warn("Failed to initialize message queue table")
		// Continue anyway - table might already exist
	}

	session := &types.Session{
		ID:           sessionID,
		Client:       client,
		DeviceStore:  device,
		DeviceJID:    device.ID,
		Cache:        cache,
		Status:       types.StatusDisconnected,
		CreatedAt:    time.Now(),
		LastActivity: time.Now(),
		DBPath:       dbPath,

		// Initialize message queue and sending state for recovered session
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),

		// Initialize campaign pause state tracking
		PausedCampaigns: make(map[int]bool),
	}

	// Open persistent database connection for message queue operations (connection pooling)
	queueDB, err := openQueueDB(dbPath, sm.logger)
	if err != nil {
		sm.logger.WithError(err).WithField("session_id", sessionID).
			Warn("Failed to open queue database for recovered session, will use fallback connections")
	} else {
		session.QueueDB = queueDB
	}

	// Load queued messages from database (persistence recovery)
	if messages, err := loadMessagesFromDB(session, sm.logger); err != nil {
		sm.logger.WithError(err).WithField("session_id", sessionID).
			Warn("Failed to load messages from database")
	} else if len(messages) > 0 {
		session.MessageQueue.AddBatch(messages)
		sm.logger.WithFields(logrus.Fields{
			"session_id": sessionID,
			"messages":   len(messages),
		}).Info("Recovered queued messages from database")
	}

	// Create session-specific event handler
	eventHandlerID := client.AddEventHandler(func(evt interface{}) {
		sm.eventHandler.HandleWithSession(evt, sessionID)
	})
	session.EventHandlerID = eventHandlerID

	// Store in memory
	sm.sessions.Store(sessionID, session)

	// Try to reconnect in background with staggered delay to prevent mass reconnection.
	// Stagger reconnections: 5s base + (sessionIndex * 2s).
	// This prevents WhatsApp's anti-abuse system from triggering on server restart.
	SafeGo(sm.logger, "session_reconnect", func() {
		// Calculate staggered delay based on session index
		baseDelay := 5 * time.Second
		staggerDelay := time.Duration(sessionIndex) * 2 * time.Second
		totalDelay := baseDelay + staggerDelay

		sm.logger.WithFields(logrus.Fields{
			"session":       session.ID,
			"delay":         totalDelay,
			"session_index": sessionIndex,
		}).Info("Scheduling staggered reconnection")

		// Use a timer + shutdownCtx select so graceful shutdown can cancel the
		// delay instead of blocking on an uninterruptible Sleep.
		timer := time.NewTimer(totalDelay)
		defer timer.Stop()
		select {
		case <-timer.C:
			// fall through to reconnect attempt
		case <-sm.shutdownCtx.Done():
			sm.logger.WithField("session", session.ID).
				Info("Staggered reconnection cancelled by shutdown")
			return
		}

		sm.logger.WithField("session", session.ID).Info("Attempting reconnection after delay")

		if err := session.Client.ConnectContext(sm.shutdownCtx); err != nil {
			sm.logger.WithError(err).WithField("session", session.ID).
				Warn("Failed to reconnect session after delay")
			session.Status = types.StatusDisconnected
		} else {
			// Status will be updated by Connected event handler (handleConnected -> ProcessConnectionEvent)
			// This prevents race condition where status is set before event fires
			sm.logger.WithFields(logrus.Fields{
				"session": session.ID,
				"db_path": session.DBPath,
			}).Info("Session reconnect initiated, waiting for Connected event")
		}
	})

	return true
}

// SaveSessionCache saves session metadata to file storage
func (sm *SessionManager) SaveSessionCache(sessionID string, cache *types.SessionCache) error {
	// Create cache directory if it doesn't exist
	cacheDir := filepath.Join("./storage", "cache")
	if err := os.MkdirAll(cacheDir, 0755); err != nil {
		return fmt.Errorf("failed to create cache directory: %w", err)
	}

	// Save cache as JSON file
	cacheFile := filepath.Join(cacheDir, fmt.Sprintf("%s.json", sessionID))
	data, err := json.MarshalIndent(cache, "", "  ")
	if err != nil {
		return fmt.Errorf("failed to marshal cache: %w", err)
	}

	if err := os.WriteFile(cacheFile, data, 0644); err != nil {
		return fmt.Errorf("failed to write cache file: %w", err)
	}

	sm.logger.WithFields(logrus.Fields{
		"session_id": sessionID,
		"cache_file": cacheFile,
		"site_url":   cache.SiteURL,
	}).Debug("Session cache saved successfully")

	return nil
}

// loadSessionCache loads session metadata from file storage
func (sm *SessionManager) loadSessionCache(sessionID string) (*types.SessionCache, error) {
	// Try to load cache from JSON file
	cacheFile := filepath.Join("./storage", "cache", fmt.Sprintf("%s.json", sessionID))

	data, err := os.ReadFile(cacheFile)
	if err != nil {
		if os.IsNotExist(err) {
			sm.logger.WithField("session_id", sessionID).Warn("Session cache file not found")
			return nil, fmt.Errorf("session cache not found for %s", sessionID)
		}
		return nil, fmt.Errorf("failed to read cache file: %w", err)
	}

	var cache types.SessionCache
	if err := json.Unmarshal(data, &cache); err != nil {
		return nil, fmt.Errorf("failed to unmarshal cache: %w", err)
	}

	sm.logger.WithFields(logrus.Fields{
		"session_id": sessionID,
		"site_url":   cache.SiteURL,
		"uid":        cache.UID,
	}).Debug("Session cache loaded successfully")

	return &cache, nil
}

// CleanupSessions removes inactive sessions and cleans up resources
func (sm *SessionManager) CleanupSessions() {
	now := time.Now()
	cleanupThreshold := time.Hour * 24 // 24 hours inactive

	var toDelete []string

	sm.sessions.Range(func(key, value interface{}) bool {
		sessionID := key.(string)
		session := value.(*types.Session)

		session.Mu.RLock()
		lastActivity := session.LastActivity
		session.Mu.RUnlock()

		if now.Sub(lastActivity) > cleanupThreshold {
			toDelete = append(toDelete, sessionID)
		}
		return true
	})

	for _, sessionID := range toDelete {
		sm.logger.WithField("session_id", sessionID).Info("Cleaning up inactive session")
		sm.DeleteSession(context.Background(), sessionID)
	}

	if len(toDelete) > 0 {
		sm.logger.WithField("cleaned", len(toDelete)).Info("Session cleanup completed")
	}
}

// StartupCleanup removes stale session files and orphaned files before session recovery
func (sm *SessionManager) StartupCleanup(ctx context.Context) error {
	sm.logger.Info("Starting startup cleanup (checking for stale sessions and orphaned files)")

	threshold := 24 * time.Hour
	now := time.Now()

	var staleCount, orphanedWALCount, orphanedSHMCount, orphanedCacheCount, orphanedStorageCount int
	var errors []error

	// Step 1: Scan all .db files and check for stale sessions
	dbFiles, err := filepath.Glob("./sessions/*.db")
	if err != nil {
		sm.logger.WithError(err).Warn("Failed to scan session database files")
		errors = append(errors, err)
	} else {
		for _, dbPath := range dbFiles {
			// Check for context cancellation
			select {
			case <-ctx.Done():
				sm.logger.Warn("Startup cleanup cancelled by context")
				return ctx.Err()
			default:
			}

			sessionID := strings.TrimSuffix(filepath.Base(dbPath), ".db")

			// Check database file modification time
			dbInfo, err := os.Stat(dbPath)
			if err != nil {
				sm.logger.WithError(err).WithField("file", dbPath).Debug("Failed to stat db file")
				continue
			}

			// Check if database file is stale (>24h old)
			if now.Sub(dbInfo.ModTime()) > threshold {
				// Only delete if cache file is MISSING (truly orphaned)
				// Do NOT delete based on file age alone - file mtime != session validity
				// A session can be valid but inactive (no file writes in 24h)
				cachePath := filepath.Join("./storage", "cache", sessionID+".json")
				_, err := os.Stat(cachePath)

				if os.IsNotExist(err) {
					// Cache file doesn't exist = orphaned db file, safe to delete
					sm.logger.WithField("session_id", sessionID).Debug("Found orphaned db file (no cache)")
					sm.logger.WithField("session_id", sessionID).Info("Startup cleanup: removing orphaned session")
					if err := sm.cleanupSessionFiles(sessionID); err != nil {
						sm.logger.WithError(err).WithField("session_id", sessionID).Warn("Failed to cleanup orphaned session")
						errors = append(errors, err)
					} else {
						staleCount++
					}
				}
				// If cache exists (even if old or unreadable), the session may still be valid - DO NOT DELETE
			}
		}
	}

	// Step 2: Clean orphaned WAL files (no matching .db file)
	walFiles, err := filepath.Glob("./sessions/*.db-wal")
	if err != nil {
		sm.logger.WithError(err).Warn("Failed to scan WAL files")
	} else {
		for _, walPath := range walFiles {
			// Check for context cancellation
			select {
			case <-ctx.Done():
				sm.logger.Warn("Startup cleanup cancelled by context during WAL cleanup")
				return ctx.Err()
			default:
			}

			dbPath := strings.TrimSuffix(walPath, "-wal")
			if _, err := os.Stat(dbPath); os.IsNotExist(err) {
				sm.logger.WithField("file", walPath).Debug("Removing orphaned WAL file")
				if err := os.Remove(walPath); err != nil {
					sm.logger.WithError(err).WithField("file", walPath).Warn("Failed to remove orphaned WAL file")
				} else {
					orphanedWALCount++
				}
			}
		}
	}

	// Step 3: Clean orphaned SHM files (no matching .db file)
	shmFiles, err := filepath.Glob("./sessions/*.db-shm")
	if err != nil {
		sm.logger.WithError(err).Warn("Failed to scan SHM files")
	} else {
		for _, shmPath := range shmFiles {
			// Check for context cancellation
			select {
			case <-ctx.Done():
				sm.logger.Warn("Startup cleanup cancelled by context during SHM cleanup")
				return ctx.Err()
			default:
			}

			dbPath := strings.TrimSuffix(shmPath, "-shm")
			if _, err := os.Stat(dbPath); os.IsNotExist(err) {
				sm.logger.WithField("file", shmPath).Debug("Removing orphaned SHM file")
				if err := os.Remove(shmPath); err != nil {
					sm.logger.WithError(err).WithField("file", shmPath).Warn("Failed to remove orphaned SHM file")
				} else {
					orphanedSHMCount++
				}
			}
		}
	}

	// Step 4: Clean orphaned cache files (no matching .db file)
	cacheFiles, err := filepath.Glob("./storage/cache/*.json")
	if err != nil {
		sm.logger.WithError(err).Warn("Failed to scan cache files")
	} else {
		for _, cachePath := range cacheFiles {
			// Check for context cancellation
			select {
			case <-ctx.Done():
				sm.logger.Warn("Startup cleanup cancelled by context during cache cleanup")
				return ctx.Err()
			default:
			}

			sessionID := strings.TrimSuffix(filepath.Base(cachePath), ".json")
			dbPath := filepath.Join("./sessions", sessionID+".db")
			if _, err := os.Stat(dbPath); os.IsNotExist(err) {
				sm.logger.WithField("session_id", sessionID).Debug("Removing orphaned cache file")
				if err := os.Remove(cachePath); err != nil {
					sm.logger.WithError(err).WithField("file", cachePath).Warn("Failed to remove orphaned cache file")
				} else {
					orphanedCacheCount++
				}
			}
		}
	}

	// Step 5: Clean orphaned storage directories (no matching .db file)
	// Check for session storage directories
	sessionStorageDirs, err := filepath.Glob("./storage/*")
	if err != nil {
		sm.logger.WithError(err).Warn("Failed to scan storage directories")
	} else {
		for _, storagePath := range sessionStorageDirs {
			// Check for context cancellation
			select {
			case <-ctx.Done():
				sm.logger.Warn("Startup cleanup cancelled by context during storage cleanup")
				return ctx.Err()
			default:
			}

			// Skip the cache directory itself
			if filepath.Base(storagePath) == "cache" {
				continue
			}

			// Check if this is a directory
			if info, err := os.Stat(storagePath); err != nil || !info.IsDir() {
				continue
			}

			// Extract session ID from directory name
			sessionID := filepath.Base(storagePath)

			// Check if corresponding .db file exists
			dbPath := filepath.Join("./sessions", sessionID+".db")
			if _, err := os.Stat(dbPath); os.IsNotExist(err) {
				sm.logger.WithField("session_id", sessionID).Debug("Removing orphaned storage directory")
				if err := os.RemoveAll(storagePath); err != nil {
					sm.logger.WithError(err).WithField("directory", storagePath).Warn("Failed to remove orphaned storage directory")
				} else {
					orphanedStorageCount++
				}
			}
		}
	}

	// Log summary
	sm.logger.WithFields(logrus.Fields{
		"stale_sessions":   staleCount,
		"orphaned_wal":     orphanedWALCount,
		"orphaned_shm":     orphanedSHMCount,
		"orphaned_cache":   orphanedCacheCount,
		"orphaned_storage": orphanedStorageCount,
		"errors":           len(errors),
	}).Info("Startup cleanup completed")

	if len(errors) > 0 {
		return fmt.Errorf("startup cleanup had %d errors", len(errors))
	}

	return nil
}

// getDatabaseContainer returns the appropriate container and DB path for a session
func (sm *SessionManager) getDatabaseContainer(sessionID string) (*sqlstore.Container, string, error) {
	// Always use per-session database strategy
	dbPath := sm.getDatabasePath(sessionID)

	// Check if container already exists
	if containerInterface, exists := sm.containers.Load(sessionID); exists {
		return containerInterface.(*sqlstore.Container), dbPath, nil
	}

	// Create new container for this session with WAL mode for concurrent access
	dbLog := waLog.Stdout("Database", "ERROR", true)
	container, err := sqlstore.New(
		context.Background(),
		"sqlite",
		fmt.Sprintf("file:%s?_pragma=foreign_keys(1)&_pragma=busy_timeout(5000)&_pragma=journal_mode(WAL)&cache=shared", dbPath),
		dbLog,
	)
	if err != nil {
		return nil, "", fmt.Errorf("failed to create session container: %w", err)
	}

	// Store container for reuse
	sm.containers.Store(sessionID, container)

	sm.logger.WithFields(logrus.Fields{
		"session_id": sessionID,
		"db_path":    dbPath,
	}).Debug("Created new database container for session")

	return container, dbPath, nil
}

// getDatabasePath generates the database file path for a session
func (sm *SessionManager) getDatabasePath(sessionID string) string {
	// Sanitize session ID for filesystem
	safeName := strings.ReplaceAll(sessionID, "/", "_")
	safeName = strings.ReplaceAll(safeName, "\\", "_")
	safeName = strings.ReplaceAll(safeName, ":", "_")

	return filepath.Join("./sessions", fmt.Sprintf("%s.db", safeName))
}

// OnMessagesAdded triggers message processing when messages are added to a session queue
func (sm *SessionManager) OnMessagesAdded(sessionID string) {
	if sm.eventProcessor != nil {
		sm.eventProcessor.OnMessagesAdded(sessionID)
		sm.logger.WithField("session_id", sessionID).Debug("Triggered message processing for added messages")
	}
}

// NotifyAccountLinked notifies server when account is connected
func (sm *SessionManager) NotifyAccountLinked(sessionID, whatsappID string) {
	session, exists := sm.GetSession(sessionID)
	if !exists {
		sm.logger.WithField("session_id", sessionID).Warn("Session not found for account linked notification")
		return
	}

	if sm.linkNotifier != nil {
		sm.linkNotifier.ProcessConnectionEvent(session, "connected", whatsappID)
	}
}

// NotifyAccountUnlinked notifies server when account is disconnected
func (sm *SessionManager) NotifyAccountUnlinked(sessionID string) {
	session, exists := sm.GetSession(sessionID)
	if !exists {
		sm.logger.WithField("session_id", sessionID).Warn("Session not found for account unlinked notification")
		return
	}

	if sm.linkNotifier != nil {
		sm.linkNotifier.ProcessConnectionEvent(session, "disconnected", "")
	}
}

// NotifyAccountConnected notifies server when account is connected
func (sm *SessionManager) NotifyAccountConnected(sessionID string) {
	session, exists := sm.GetSession(sessionID)
	if !exists {
		sm.logger.WithField("session_id", sessionID).Warn("Session not found for account connection notification")
		return
	}

	if sm.linkNotifier != nil {
		sm.linkNotifier.ProcessConnectionEvent(session, "connected", "")
	}
}

// NotifyAccountLoggedOut notifies server when account is logged out
func (sm *SessionManager) NotifyAccountLoggedOut(sessionID string) {
	session, exists := sm.GetSession(sessionID)
	if !exists {
		sm.logger.WithField("session_id", sessionID).Warn("Session not found for account logout notification")
		return
	}

	if sm.linkNotifier != nil {
		sm.linkNotifier.ProcessConnectionEvent(session, "logged_out", "")
	}
}

// TriggerMessageProcessing manually triggers message processing for a session
func (sm *SessionManager) TriggerMessageProcessing(sessionID, eventType string) {
	if sm.eventProcessor != nil {
		sm.eventProcessor.TriggerProcessing(sessionID, eventType)
	}
}

// GetEventProcessor returns the event processor instance
func (sm *SessionManager) GetEventProcessor() *EventDrivenProcessor {
	return sm.eventProcessor
}

// GetLinkNotifier returns the link notifier instance
func (sm *SessionManager) GetLinkNotifier() *LinkNotifier {
	return sm.linkNotifier
}

// configureClient applies the reliability flags we require for every session.
// Must be called before client.Connect/ConnectContext.
//
//   - AutoTrustIdentity=true: required for group chats; otherwise device-key
//     rotations on peers cause persistent "identity mismatch" errors.
//   - InitialAutoReconnect=true: lets ConnectContext return nil on retryable
//     network failures and reconnect in background instead of failing fast.
//   - BackgroundEventCtx: wired to sm.shutdownCtx so Shutdown() cancels
//     whatsmeow's internal auto-reconnect goroutines.
//   - EnableAutoReconnect + AutoReconnectHook: existing behaviour - cap retries
//     at 100 (matches Node.js server).
func (sm *SessionManager) configureClient(client *whatsmeow.Client, sessionID string) {
	client.AutoTrustIdentity = true
	client.InitialAutoReconnect = true
	client.BackgroundEventCtx = sm.shutdownCtx
	client.EnableAutoReconnect = true
	client.AutoReconnectHook = func(err error) bool {
		attemptCount := client.AutoReconnectErrors
		sm.logger.WithFields(logrus.Fields{
			"session_id": sessionID,
			"error":      err,
			"attempt":    attemptCount,
		}).Info("WhatsApp auto-reconnection attempt")

		// Stop reconnecting after 100 failed attempts (matches Node.js server behavior)
		// 100 retries = ~8-16 minutes of reconnection attempts with exponential backoff
		if attemptCount >= 100 {
			sm.logger.WithFields(logrus.Fields{
				"session_id":      sessionID,
				"failed_attempts": attemptCount,
			}).Warn("Max reconnection attempts reached, stopping auto-reconnect")
			return false
		}
		return true
	}
}

// configureProxy configures proxy settings for a WhatsApp client
func (sm *SessionManager) configureProxy(client *whatsmeow.Client) error {
	if !sm.config.Proxy.Enabled {
		sm.logger.Debug("Proxy disabled, using direct connection")
		// Explicitly disable proxy (in case environment variables are set)
		client.SetProxy(nil)
		return nil
	}

	proxyDialer := NewProxyDialer(sm.config, sm.logger)
	protocol := proxyDialer.GetProtocol()

	sm.logger.WithFields(logrus.Fields{
		"protocol": protocol,
		"host":     sm.config.Proxy.Host,
		"port":     sm.config.Proxy.Port,
		"has_auth": sm.config.Proxy.Username != "",
	}).Info("Configuring WhatsApp client with proxy")

	switch protocol {
	case "socks5", "socks":
		// Use SOCKS5 proxy
		dialer, err := proxyDialer.CreateSOCKS5Dialer()
		if err != nil {
			return fmt.Errorf("failed to create SOCKS5 dialer: %w", err)
		}

		client.SetSOCKSProxy(dialer)
		sm.logger.WithField("protocol", "SOCKS5").Info("Proxy configured successfully")

	case "http", "https":
		// Use HTTP/HTTPS proxy
		proxyFunc, err := proxyDialer.CreateHTTPProxy()
		if err != nil {
			return fmt.Errorf("failed to create HTTP proxy: %w", err)
		}

		client.SetProxy(proxyFunc)
		sm.logger.WithField("protocol", protocol).Info("Proxy configured successfully")

	default:
		return fmt.Errorf("unsupported proxy protocol: %s", protocol)
	}

	return nil
}

// Shutdown gracefully shuts down the session manager and its components
func (sm *SessionManager) Shutdown() {
	sm.logger.Info("Shutting down session manager")

	// Cancel shutdown context FIRST so whatsmeow auto-reconnect goroutines
	// (which use BackgroundEventCtx) exit promptly instead of racing the
	// disconnect/close calls below.
	if sm.shutdownCancel != nil {
		sm.shutdownCancel()
	}

	// Stop the event processor
	if sm.eventProcessor != nil {
		sm.eventProcessor.Stop()
	}

	// Disconnect all sessions
	sm.sessions.Range(func(key, value interface{}) bool {
		sessionID := key.(string)
		session := value.(*types.Session)

		if session.Client != nil && session.Client.IsConnected() {
			sm.logger.WithField("session_id", sessionID).Info("Disconnecting session during shutdown")
			session.Client.Disconnect()
		}

		return true
	})

	// Close all database containers to release file locks
	sm.containers.Range(func(key, value interface{}) bool {
		sessionID := key.(string)
		container := value.(*sqlstore.Container)
		container.Close()
		sm.logger.WithField("session_id", sessionID).Debug("Closed container during shutdown")
		return true
	})

	sm.logger.Info("Session manager shutdown completed")
}
