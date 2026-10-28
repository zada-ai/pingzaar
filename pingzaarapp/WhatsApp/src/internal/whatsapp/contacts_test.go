package whatsapp

import (
	"context"
	"fmt"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"github.com/stretchr/testify/suite"
	_ "modernc.org/sqlite" // SQLite driver

	"whatsapp-server/internal/config"
	"whatsapp-server/pkg/types"
	"whatsapp-server/tests/helpers"
)

type ContactServiceTestSuite struct {
	suite.Suite
	ctx            context.Context
	tempDir        string
	config         *config.Config
	sessionMgr     *SessionManager
	contactService *ContactService
}

func (suite *ContactServiceTestSuite) SetupTest() {
	suite.ctx = context.Background()
	suite.tempDir = suite.T().TempDir()

	suite.config = &config.Config{
		Server: struct {
			Host     string `mapstructure:"host"`
			Port     int    `mapstructure:"port"`
			Debug    bool   `mapstructure:"debug"`
			Secret   string `mapstructure:"secret"`
			AutoRead bool   `mapstructure:"autoread"`
		}{
			Host:     "127.0.0.1",
			Port:     8899,
			Debug:    true,
			Secret:   "test-secret",
			AutoRead: true,
		},
	}

	logger := helpers.TestLogger()
	var err error
	suite.sessionMgr, err = NewSessionManager(suite.config, logger)
	require.NoError(suite.T(), err)

	suite.contactService = NewContactService(suite.sessionMgr, logger)
}

func (suite *ContactServiceTestSuite) TestNewContactService() {
	logger := helpers.TestLogger()
	service := NewContactService(suite.sessionMgr, logger)

	assert.NotNil(suite.T(), service)
	assert.Equal(suite.T(), suite.sessionMgr, service.sessionMgr)
	assert.Equal(suite.T(), logger, service.logger)
}

func (suite *ContactServiceTestSuite) TestGetGroups_SessionNotFound() {
	groups, err := suite.contactService.GetGroups("nonexistent-session")

	assert.Error(suite.T(), err)
	assert.Nil(suite.T(), groups)
	assert.Contains(suite.T(), err.Error(), "session not found")
}

func (suite *ContactServiceTestSuite) TestGetGroups_ClientNotInitialized() {
	// Create a session without client
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	groups, err := suite.contactService.GetGroups(session.ID)

	assert.Error(suite.T(), err)
	assert.Nil(suite.T(), groups)
	assert.Contains(suite.T(), err.Error(), "websocket")
}

func (suite *ContactServiceTestSuite) TestGetGroups_ClientError() {
	// Create a session with connected status but no real client
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Set connected status but client will still be nil
	session.Status = types.StatusConnected

	groups, err := suite.contactService.GetGroups(session.ID)

	// Should still fail because client is nil
	assert.Error(suite.T(), err)
	assert.Nil(suite.T(), groups)
	assert.Contains(suite.T(), err.Error(), "websocket")
}

func (suite *ContactServiceTestSuite) TestValidateNumber_SessionNotFound() {
	result, err := suite.contactService.ValidateNumber("nonexistent-session", "+1234567890")

	assert.Error(suite.T(), err)
	assert.Nil(suite.T(), result)
	assert.Contains(suite.T(), err.Error(), "session not found")
}

func (suite *ContactServiceTestSuite) TestValidateNumber_ClientNotInitialized() {
	// Create a session without client
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	result, err := suite.contactService.ValidateNumber(session.ID, "+1234567890")

	assert.Error(suite.T(), err)
	assert.Nil(suite.T(), result)
	assert.Contains(suite.T(), err.Error(), "websocket")
}

func (suite *ContactServiceTestSuite) TestValidateNumber_PhoneProcessing() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Set connected status but client will still be nil
	session.Status = types.StatusConnected

	// Test various phone number formats (all will fail at client level but test processing)
	testCases := []string{
		"+1234567890",
		"1234567890",
		"+44 20 7946 0958",
		"+1-555-123-4567",
	}

	for _, phone := range testCases {
		result, err := suite.contactService.ValidateNumber(session.ID, phone)

		// Should fail because client is nil
		assert.Error(suite.T(), err)
		assert.Nil(suite.T(), result)
		assert.Contains(suite.T(), err.Error(), "websocket")
	}
}

func (suite *ContactServiceTestSuite) TestContactService_ConcurrentOperations() {
	// Create multiple sessions
	sessions := make([]*types.Session, 3)
	for i := 0; i < 3; i++ {
		cache := helpers.TestSessionCache()
		cache.Unique = fmt.Sprintf("user%d", i)
		cache.SiteUnique = fmt.Sprintf("site%d", i)

		session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
		require.NoError(suite.T(), err)
		sessions[i] = session
	}

	done := make(chan bool, 6)

	// Concurrent GetGroups operations
	for i := 0; i < 3; i++ {
		go func(sessionID string) {
			defer func() { done <- true }()

			groups, err := suite.contactService.GetGroups(sessionID)
			assert.Error(suite.T(), err) // Will fail because no real client
			assert.Nil(suite.T(), groups)
		}(sessions[i].ID)
	}

	// Concurrent ValidateNumber operations
	for i := 0; i < 3; i++ {
		go func(sessionID string, phone string) {
			defer func() { done <- true }()

			result, err := suite.contactService.ValidateNumber(sessionID, phone)
			assert.Error(suite.T(), err) // Will fail because no real client
			assert.Nil(suite.T(), result)
		}(sessions[i].ID, fmt.Sprintf("+123456789%d", i))
	}

	// Wait for all operations
	for i := 0; i < 6; i++ {
		<-done
	}
}

func (suite *ContactServiceTestSuite) TestContactService_StructTypes() {
	// Test struct types used by the service
	groupInfo := GroupInfo{
		ID:      "123456789@g.us",
		Subject: "Test Group",
	}
	assert.Equal(suite.T(), "123456789@g.us", groupInfo.ID)
	assert.Equal(suite.T(), "Test Group", groupInfo.Subject)

	validationResult := ValidationResult{
		JID:   "1234567890@s.whatsapp.net",
		Phone: "+1234567890",
	}
	assert.Equal(suite.T(), "1234567890@s.whatsapp.net", validationResult.JID)
	assert.Equal(suite.T(), "+1234567890", validationResult.Phone)
}

func TestContactServiceTestSuite(t *testing.T) {
	suite.Run(t, new(ContactServiceTestSuite))
}

// Additional unit tests for edge cases
func TestContactService_EdgeCases(t *testing.T) {
	_ = t.TempDir() // Create temp dir but don't use it since we removed Storage config

	config := &config.Config{
		Server: struct {
			Host     string `mapstructure:"host"`
			Port     int    `mapstructure:"port"`
			Debug    bool   `mapstructure:"debug"`
			Secret   string `mapstructure:"secret"`
			AutoRead bool   `mapstructure:"autoread"`
		}{
			Host:     "127.0.0.1",
			Port:     8899,
			Debug:    true,
			Secret:   "test-secret",
			AutoRead: true,
		},
	}

	logger := helpers.TestLogger()
	sessionMgr, err := NewSessionManager(config, logger)
	require.NoError(t, err)

	service := NewContactService(sessionMgr, logger)

	t.Run("Empty session ID", func(t *testing.T) {
		groups, err := service.GetGroups("")
		assert.Error(t, err)
		assert.Nil(t, groups)
		assert.Contains(t, err.Error(), "session not found")

		result, err := service.ValidateNumber("", "+1234567890")
		assert.Error(t, err)
		assert.Nil(t, result)
		assert.Contains(t, err.Error(), "session not found")
	})

	t.Run("Empty phone number", func(t *testing.T) {
		// Create a session
		cache := helpers.TestSessionCache()
		cache.Unique = "empty-phone-user"
		cache.SiteUnique = "empty-phone-site"
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		result, err := service.ValidateNumber(session.ID, "")
		assert.Error(t, err)
		assert.Nil(t, result)
		// Will fail at client level first, but tests the flow
	})

	t.Run("International phone number formats", func(t *testing.T) {
		// Create a session
		cache := helpers.TestSessionCache()
		cache.Unique = "intl-phone-user"
		cache.SiteUnique = "intl-phone-site"
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		phoneNumbers := []string{
			"+1 (555) 123-4567",
			"+44 20 7946 0958",
			"+81 3-1234-5678",
			"+33 1 42 86 83 26",
			"001234567890", // Without plus
		}

		for _, phone := range phoneNumbers {
			result, err := service.ValidateNumber(session.ID, phone)
			assert.Error(t, err) // Will fail because client is nil
			assert.Nil(t, result)
			assert.Contains(t, err.Error(), "websocket")
		}
	})
}
