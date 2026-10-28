package whatsapp

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"github.com/stretchr/testify/suite"
	"go.mau.fi/whatsmeow"
	_ "modernc.org/sqlite" // SQLite driver

	"whatsapp-server/internal/config"
	goTypes "whatsapp-server/pkg/types"
	"whatsapp-server/tests/helpers"
)

type SessionManagerTestSuite struct {
	suite.Suite
	ctx              context.Context
	tempDir          string
	sharedConfig     *config.Config
	perSessionConfig *config.Config
}

func (suite *SessionManagerTestSuite) SetupTest() {
	helpers.GinTestMode()
	suite.ctx = context.Background()
	suite.tempDir = suite.T().TempDir()

	// Create test config (always per-session strategy)
	suite.sharedConfig = &config.Config{}
	suite.sharedConfig.Server.Secret = "test-secret"
	suite.sharedConfig.Server.AutoRead = true
	suite.sharedConfig.Server.Host = "127.0.0.1"
	suite.sharedConfig.Server.Port = 8899

	// Use same config for both (per-session strategy is hardcoded)
	suite.perSessionConfig = suite.sharedConfig
}

func (suite *SessionManagerTestSuite) TearDownTest() {
	os.RemoveAll(suite.tempDir)
}

func (suite *SessionManagerTestSuite) TestNewSessionManager_SharedStrategy() {
	logger := helpers.TestLogger()

	sm, err := NewSessionManager(suite.sharedConfig, logger)

	require.NoError(suite.T(), err)
	assert.NotNil(suite.T(), sm)
	// Strategy is always per_session now, so shared container is not used
	assert.Nil(suite.T(), sm.container)
	assert.NotNil(suite.T(), sm.eventHandler)
	assert.NotNil(suite.T(), sm.webhookService)
	assert.NotNil(suite.T(), sm.linkNotifier)
	assert.NotNil(suite.T(), sm.eventProcessor)
}

func (suite *SessionManagerTestSuite) TestNewSessionManager_PerSessionStrategy() {
	logger := helpers.TestLogger()

	sm, err := NewSessionManager(suite.perSessionConfig, logger)

	require.NoError(suite.T(), err)
	assert.NotNil(suite.T(), sm)
	assert.Nil(suite.T(), sm.container) // No shared container for per-session strategy
	assert.NotNil(suite.T(), sm.eventHandler)
	assert.NotNil(suite.T(), sm.webhookService)
	// Strategy is always per_session now

	// Should create sessions directory
	assert.DirExists(suite.T(), "./sessions")
}

func (suite *SessionManagerTestSuite) TestCreateSession_Success() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	cache := helpers.TestSessionCache()

	session, err := sm.CreateSession(suite.ctx, cache)

	require.NoError(suite.T(), err)
	assert.NotNil(suite.T(), session)
	assert.Equal(suite.T(), "test-unique_test-site", session.ID)
	assert.Equal(suite.T(), cache, session.Cache)
	assert.Equal(suite.T(), goTypes.StatusDisconnected, session.Status)
	assert.NotNil(suite.T(), session.Client)
	assert.NotNil(suite.T(), session.DeviceStore)
	assert.NotEmpty(suite.T(), session.DBPath)

	// Session should be stored in manager
	retrievedSession, exists := sm.GetSession(session.ID)
	assert.True(suite.T(), exists)
	assert.Equal(suite.T(), session.ID, retrievedSession.ID)
}

func (suite *SessionManagerTestSuite) TestCreateSession_Duplicate() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	cache := helpers.TestSessionCache()

	// Create first session
	_, err = sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Try to create duplicate
	_, err = sm.CreateSession(suite.ctx, cache)
	assert.Error(suite.T(), err)
	assert.Contains(suite.T(), err.Error(), "already exists")
}

func (suite *SessionManagerTestSuite) TestGetSession() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	cache := helpers.TestSessionCache()
	session, err := sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Test GetSession
	retrievedSession, exists := sm.GetSession(session.ID)
	assert.True(suite.T(), exists)
	assert.Equal(suite.T(), session.ID, retrievedSession.ID)

	// Test GetSessionByParams
	retrievedSession2, exists2 := sm.GetSessionByParams(cache.SiteUnique, cache.Unique)
	assert.True(suite.T(), exists2)
	assert.Equal(suite.T(), session.ID, retrievedSession2.ID)

	// Test non-existent session
	_, exists3 := sm.GetSession("nonexistent")
	assert.False(suite.T(), exists3)
}

func (suite *SessionManagerTestSuite) TestDeleteSession() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	cache := helpers.TestSessionCache()
	session, err := sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Delete session
	err = sm.DeleteSession(suite.ctx, session.ID)
	assert.NoError(suite.T(), err)

	// Session should be removed
	_, exists := sm.GetSession(session.ID)
	assert.False(suite.T(), exists)
}

func (suite *SessionManagerTestSuite) TestDeleteSession_NotFound() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	err = sm.DeleteSession(suite.ctx, "nonexistent")
	assert.Error(suite.T(), err)
	assert.Contains(suite.T(), err.Error(), "not found")
}

func (suite *SessionManagerTestSuite) TestGetTotalSessions() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), 0, sm.GetTotalSessions())

	// Create sessions
	cache1 := helpers.TestSessionCache()
	cache2 := helpers.TestSessionCache()
	cache2.Unique = "test-unique-2"
	cache2.SiteUnique = "test-site-2"

	_, err = sm.CreateSession(suite.ctx, cache1)
	require.NoError(suite.T(), err)
	assert.Equal(suite.T(), 1, sm.GetTotalSessions())

	_, err = sm.CreateSession(suite.ctx, cache2)
	require.NoError(suite.T(), err)
	assert.Equal(suite.T(), 2, sm.GetTotalSessions())

	// Delete one session
	sm.DeleteSession(suite.ctx, "test-unique_test-site")
	assert.Equal(suite.T(), 1, sm.GetTotalSessions())
}

func (suite *SessionManagerTestSuite) TestConnectSession_NotFound() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	_, err = sm.ConnectSession(suite.ctx, "nonexistent")
	assert.Error(suite.T(), err)
	assert.Contains(suite.T(), err.Error(), "not found")
}

func (suite *SessionManagerTestSuite) TestConnectSession_FirstTimeLogin() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	cache := helpers.TestSessionCache()
	session, err := sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// For first-time login, the client won't have a device ID
	// This will trigger QR code flow in real implementation
	assert.Equal(suite.T(), goTypes.StatusDisconnected, session.Status)
	assert.NotNil(suite.T(), session.Client)
	assert.Nil(suite.T(), session.Client.Store.ID)
}

func (suite *SessionManagerTestSuite) TestIsSessionConnected() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	// Test non-existent session
	assert.False(suite.T(), sm.IsSessionConnected("nonexistent"))

	// Create session
	cache := helpers.TestSessionCache()
	session, err := sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Test disconnected session
	session.Status = goTypes.StatusDisconnected
	assert.False(suite.T(), sm.IsSessionConnected(session.ID))

	// Since the real client won't be connected in tests,
	// we primarily test the session lookup logic
	assert.NotNil(suite.T(), session.Client)
}

func (suite *SessionManagerTestSuite) TestGetSessionStatus() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	// Test non-existent session
	assert.Equal(suite.T(), "not_found", sm.GetSessionStatus("nonexistent"))

	// Create session
	cache := helpers.TestSessionCache()
	session, err := sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Test different statuses
	session.Status = goTypes.StatusConnecting
	assert.Equal(suite.T(), "connecting", sm.GetSessionStatus(session.ID))

	session.Status = goTypes.StatusLoggedOut
	assert.Equal(suite.T(), "logged_out", sm.GetSessionStatus(session.ID))

	session.Status = goTypes.StatusDisconnected
	assert.Equal(suite.T(), "disconnected", sm.GetSessionStatus(session.ID))

	// Note: Testing actual connected status requires a real WhatsApp connection
	// which is not feasible in unit tests
}

func (suite *SessionManagerTestSuite) TestGetDatabasePath() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	// Test normal session ID
	path := sm.getDatabasePath("user123_site456")
	expected := filepath.Join("./sessions", "user123_site456.db")
	assert.Equal(suite.T(), expected, path)

	// Test session ID with special characters
	path = sm.getDatabasePath("user/with\\slashes:colons")
	expected = filepath.Join("./sessions", "user_with_slashes_colons.db")
	assert.Equal(suite.T(), expected, path)
}

func (suite *SessionManagerTestSuite) TestCleanupSessions() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	// Create sessions with different activity times
	cache1 := helpers.TestSessionCache()
	cache1.Unique = "active"
	session1, err := sm.CreateSession(suite.ctx, cache1)
	require.NoError(suite.T(), err)

	cache2 := helpers.TestSessionCache()
	cache2.Unique = "inactive"
	cache2.SiteUnique = "inactive"
	session2, err := sm.CreateSession(suite.ctx, cache2)
	require.NoError(suite.T(), err)

	// Set last activity to make session2 inactive
	session1.LastActivity = time.Now()
	session2.LastActivity = time.Now().Add(-25 * time.Hour) // More than 24 hours ago

	assert.Equal(suite.T(), 2, sm.GetTotalSessions())

	// Run cleanup
	sm.CleanupSessions()

	// Only active session should remain
	assert.Equal(suite.T(), 1, sm.GetTotalSessions())
	_, exists1 := sm.GetSession(session1.ID)
	assert.True(suite.T(), exists1)
	_, exists2 := sm.GetSession(session2.ID)
	assert.False(suite.T(), exists2)
}

func (suite *SessionManagerTestSuite) TestRecoverSessions_NoSessionsDirectory() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	// Remove sessions directory
	os.RemoveAll("./sessions")

	err = sm.RecoverSessions(suite.ctx)
	assert.NoError(suite.T(), err) // Should not error when directory doesn't exist
}

func (suite *SessionManagerTestSuite) TestRecoverSessions_PerSessionStrategy() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	// Create a fake database file
	dbFile := filepath.Join("./sessions", "test_session.db")
	err = os.WriteFile(dbFile, []byte{}, 0644)
	require.NoError(suite.T(), err)

	err = sm.RecoverSessions(suite.ctx)
	// This test primarily verifies the function doesn't panic and handles file scanning
	// Full database recovery would require more complex mocking of sqlstore
	assert.NoError(suite.T(), err)
}

// Benchmark tests
func (suite *SessionManagerTestSuite) TestSessionManager_Performance() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.perSessionConfig, logger)
	require.NoError(suite.T(), err)

	// Create multiple sessions rapidly
	const numSessions = 100
	start := time.Now()

	for i := 0; i < numSessions; i++ {
		cache := helpers.TestSessionCache()
		cache.Unique = fmt.Sprintf("user%d", i)
		cache.SiteUnique = fmt.Sprintf("site%d", i)

		_, err := sm.CreateSession(suite.ctx, cache)
		require.NoError(suite.T(), err)
	}

	duration := time.Since(start)
	suite.T().Logf("Created %d sessions in %v (%.2f sessions/second)",
		numSessions, duration, float64(numSessions)/duration.Seconds())

	// Verify all sessions were created
	assert.Equal(suite.T(), numSessions, sm.GetTotalSessions())

	// Test concurrent access
	start = time.Now()
	for i := 0; i < numSessions; i++ {
		sessionID := fmt.Sprintf("user%d_site%d", i, i)
		_, exists := sm.GetSession(sessionID)
		assert.True(suite.T(), exists)
	}
	duration = time.Since(start)
	suite.T().Logf("Retrieved %d sessions in %v", numSessions, duration)
}

func (suite *SessionManagerTestSuite) TestNewSessionManager_InitializesShutdownContext() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()

	assert.NotNil(suite.T(), sm.shutdownCtx, "shutdownCtx must be initialized in NewSessionManager")
	assert.NotNil(suite.T(), sm.shutdownCancel, "shutdownCancel must be initialized in NewSessionManager")
	assert.NoError(suite.T(), sm.shutdownCtx.Err(), "shutdownCtx must be alive after construction")
}

func (suite *SessionManagerTestSuite) TestCreateSession_SetsReliabilityFlags() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()

	cache := helpers.TestSessionCache()
	session, err := sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), session.Client)

	assert.True(suite.T(), session.Client.AutoTrustIdentity,
		"AutoTrustIdentity must be true to handle group-chat device-key rotations")
	assert.True(suite.T(), session.Client.InitialAutoReconnect,
		"InitialAutoReconnect must be true for resilient startup")
	assert.True(suite.T(), session.Client.EnableAutoReconnect,
		"EnableAutoReconnect must remain true (existing behaviour)")
	assert.Same(suite.T(), sm.shutdownCtx, session.Client.BackgroundEventCtx,
		"BackgroundEventCtx must be wired to sm.shutdownCtx")
}

func (suite *SessionManagerTestSuite) TestShutdown_CancelsShutdownContext() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)

	cache := helpers.TestSessionCache()
	session, err := sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// shutdownCtx starts alive and wired into the client
	require.NoError(suite.T(), sm.shutdownCtx.Err())
	require.Same(suite.T(), sm.shutdownCtx, session.Client.BackgroundEventCtx)

	sm.Shutdown()

	assert.ErrorIs(suite.T(), sm.shutdownCtx.Err(), context.Canceled,
		"Shutdown must cancel sm.shutdownCtx so whatsmeow auto-reconnect goroutines exit")
}

func (suite *SessionManagerTestSuite) TestHandleQRChannel_CodeEvent() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()
	session := &goTypes.Session{ID: "test", Status: goTypes.StatusConnecting}

	qrChan := make(chan whatsmeow.QRChannelItem, 1)
	qrChan <- whatsmeow.QRChannelItem{Event: "code", Code: "abc123"}

	result, err := sm.handleQRChannel(session, qrChan)
	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), result)
	assert.Equal(suite.T(), "code", result.Event)
	assert.Equal(suite.T(), "abc123", result.Code)
}

func (suite *SessionManagerTestSuite) TestHandleQRChannel_SuccessEvent() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()
	session := &goTypes.Session{ID: "test", Status: goTypes.StatusConnecting}

	qrChan := make(chan whatsmeow.QRChannelItem, 1)
	qrChan <- whatsmeow.QRChannelItem{Event: "success"}

	result, err := sm.handleQRChannel(session, qrChan)
	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), result)
	assert.Equal(suite.T(), "success", result.Event)
}

func (suite *SessionManagerTestSuite) TestHandleQRChannel_TimeoutReturnsError() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()
	session := &goTypes.Session{ID: "test", Status: goTypes.StatusConnecting}

	qrChan := make(chan whatsmeow.QRChannelItem, 1)
	qrChan <- whatsmeow.QRChannelItem{Event: "timeout"}

	result, err := sm.handleQRChannel(session, qrChan)
	require.Error(suite.T(), err)
	assert.Nil(suite.T(), result)
	assert.Contains(suite.T(), err.Error(), "timeout")
	assert.Equal(suite.T(), goTypes.StatusDisconnected, session.Status)
}

func (suite *SessionManagerTestSuite) TestHandleQRChannel_ClientOutdatedReturnsDistinctError() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()
	session := &goTypes.Session{ID: "test", Status: goTypes.StatusConnecting}

	qrChan := make(chan whatsmeow.QRChannelItem, 1)
	qrChan <- whatsmeow.QRChannelItem{Event: "err-client-outdated"}

	result, err := sm.handleQRChannel(session, qrChan)
	require.Error(suite.T(), err)
	assert.Nil(suite.T(), result)
	assert.Contains(suite.T(), err.Error(), "outdated")
	assert.Equal(suite.T(), goTypes.StatusDisconnected, session.Status)
}

func (suite *SessionManagerTestSuite) TestHandleQRChannel_ScannedWithoutMultideviceReturnsError() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()
	session := &goTypes.Session{ID: "test", Status: goTypes.StatusConnecting}

	qrChan := make(chan whatsmeow.QRChannelItem, 1)
	qrChan <- whatsmeow.QRChannelItem{Event: "err-scanned-without-multidevice"}

	result, err := sm.handleQRChannel(session, qrChan)
	require.Error(suite.T(), err)
	assert.Nil(suite.T(), result)
	assert.Contains(suite.T(), err.Error(), "multi-device")
	assert.Equal(suite.T(), goTypes.StatusDisconnected, session.Status)
}

func (suite *SessionManagerTestSuite) TestHandleQRChannel_UnexpectedStateReturnsError() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()
	session := &goTypes.Session{ID: "test", Status: goTypes.StatusConnecting}

	qrChan := make(chan whatsmeow.QRChannelItem, 1)
	qrChan <- whatsmeow.QRChannelItem{Event: "err-unexpected-state"}

	result, err := sm.handleQRChannel(session, qrChan)
	require.Error(suite.T(), err)
	assert.Nil(suite.T(), result)
	assert.Contains(suite.T(), err.Error(), "unexpected")
	assert.Equal(suite.T(), goTypes.StatusDisconnected, session.Status)
}

func (suite *SessionManagerTestSuite) TestHandleQRChannel_ChannelClosedWithoutSuccess() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()
	session := &goTypes.Session{ID: "test", Status: goTypes.StatusConnecting}

	qrChan := make(chan whatsmeow.QRChannelItem)
	close(qrChan)

	result, err := sm.handleQRChannel(session, qrChan)
	require.Error(suite.T(), err)
	assert.Nil(suite.T(), result)
	assert.Contains(suite.T(), err.Error(), "closed unexpectedly")
}

func (suite *SessionManagerTestSuite) TestHandleQRChannel_UnknownEventContinuesWaiting() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()
	session := &goTypes.Session{ID: "test", Status: goTypes.StatusConnecting}

	qrChan := make(chan whatsmeow.QRChannelItem, 2)
	qrChan <- whatsmeow.QRChannelItem{Event: "unknown-event"}
	qrChan <- whatsmeow.QRChannelItem{Event: "code", Code: "xyz"}

	result, err := sm.handleQRChannel(session, qrChan)
	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), result)
	assert.Equal(suite.T(), "code", result.Event)
	assert.Equal(suite.T(), "xyz", result.Code)
}

func (suite *SessionManagerTestSuite) TestForceDeleteDeviceStore_NilSessionIsSafe() {
	logger := helpers.TestLogger()

	err := forceDeleteDeviceStore(suite.ctx, nil, logger)
	assert.NoError(suite.T(), err, "nil session must be a no-op")
}

func (suite *SessionManagerTestSuite) TestForceDeleteDeviceStore_NilDeviceStoreIsSafe() {
	logger := helpers.TestLogger()
	session := &goTypes.Session{ID: "test", DeviceStore: nil}

	err := forceDeleteDeviceStore(suite.ctx, session, logger)
	assert.NoError(suite.T(), err, "nil DeviceStore must be a no-op")
}

func (suite *SessionManagerTestSuite) TestForceDeleteDeviceStore_ReturnsErrorFromStore() {
	// A freshly created session has DeviceStore.ID == nil (not paired).
	// whatsmeow's Device.Delete then returns "device JID must be known";
	// the helper must surface that error rather than panic.
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()

	cache := helpers.TestSessionCache()
	session, err := sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), session.DeviceStore)
	require.Nil(suite.T(), session.DeviceStore.ID, "freshly created session should not be paired")

	delErr := forceDeleteDeviceStore(suite.ctx, session, logger)
	assert.Error(suite.T(), delErr, "Delete on unpaired device must return an error, surfaced by the helper")
}

func (suite *SessionManagerTestSuite) TestForceDeleteDeviceStore_RespectsTimeout() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()

	cache := helpers.TestSessionCache()
	session, err := sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Caller passes an already-cancelled context; forceDeleteDeviceStore must
	// return quickly regardless of outcome (the 10s internal timeout caps it).
	cancelledCtx, cancel := context.WithCancel(suite.ctx)
	cancel()

	done := make(chan struct{})
	go func() {
		_ = forceDeleteDeviceStore(cancelledCtx, session, logger)
		close(done)
	}()

	select {
	case <-done:
		// ok
	case <-time.After(12 * time.Second):
		suite.T().Fatal("forceDeleteDeviceStore did not return within timeout budget")
	}
}

func (suite *SessionManagerTestSuite) TestConfigureClient_AppliesAllFlags() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.sharedConfig, logger)
	require.NoError(suite.T(), err)
	defer sm.Shutdown()

	cache := helpers.TestSessionCache()
	session, err := sm.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Reset flags then re-apply via the helper to exercise configureClient directly.
	session.Client.AutoTrustIdentity = false
	session.Client.InitialAutoReconnect = false
	session.Client.EnableAutoReconnect = false
	session.Client.BackgroundEventCtx = nil
	session.Client.AutoReconnectHook = nil

	sm.configureClient(session.Client, session.ID)

	assert.True(suite.T(), session.Client.AutoTrustIdentity)
	assert.True(suite.T(), session.Client.InitialAutoReconnect)
	assert.True(suite.T(), session.Client.EnableAutoReconnect)
	assert.Same(suite.T(), sm.shutdownCtx, session.Client.BackgroundEventCtx)
	require.NotNil(suite.T(), session.Client.AutoReconnectHook)
}

func TestSessionManagerTestSuite(t *testing.T) {
	suite.Run(t, new(SessionManagerTestSuite))
}

// Additional unit tests for edge cases
func TestSessionManager_EdgeCases(t *testing.T) {
	helpers.GinTestMode()

	config := &config.Config{}
	config.Server.Secret = "test-secret"
	config.Server.AutoRead = true
	config.Server.Host = "127.0.0.1"
	config.Server.Port = 8899

	logger := helpers.TestLogger()
	sm, err := NewSessionManager(config, logger)
	require.NoError(t, err)

	t.Run("CreateSession with nil cache", func(t *testing.T) {
		// This test expects a panic since the current implementation doesn't check for nil
		assert.Panics(t, func() {
			sm.CreateSession(context.Background(), nil)
		})
	})

	t.Run("Multiple containers for same session", func(t *testing.T) {
		cache := helpers.TestSessionCache()
		session1, err := sm.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		// Delete session but keep container
		sm.DeleteSession(context.Background(), session1.ID)

		// Create same session again - should reuse container
		session2, err := sm.CreateSession(context.Background(), cache)
		require.NoError(t, err)
		assert.Equal(t, session1.DBPath, session2.DBPath)
	})

	t.Run("Database path sanitization", func(t *testing.T) {
		// Test various special characters in session IDs
		testCases := []struct {
			sessionID string
			expected  string
		}{
			{"user/site", "user_site.db"},
			{"user\\site", "user_site.db"},
			{"user:site", "user_site.db"},
			{"user/with\\multiple:chars", "user_with_multiple_chars.db"},
		}

		for _, tc := range testCases {
			path := sm.getDatabasePath(tc.sessionID)
			filename := filepath.Base(path)
			assert.Equal(t, tc.expected, filename, "Session ID: %s", tc.sessionID)
		}
	})
}
