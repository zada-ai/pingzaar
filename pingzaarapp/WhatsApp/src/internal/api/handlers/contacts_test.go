package handlers

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gin-gonic/gin"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"github.com/stretchr/testify/suite"
	_ "modernc.org/sqlite" // SQLite driver

	"whatsapp-server/internal/config"
	"whatsapp-server/internal/whatsapp"
	"whatsapp-server/tests/helpers"
)

type ContactHandlerTestSuite struct {
	suite.Suite
	ctx        context.Context
	tempDir    string
	config     *config.Config
	sessionMgr *whatsapp.SessionManager
	handler    *ContactHandler
	router     *gin.Engine
}

func (suite *ContactHandlerTestSuite) SetupTest() {
	helpers.GinTestMode()
	suite.ctx = context.Background()
	suite.tempDir = suite.T().TempDir()

	suite.config = helpers.TestConfig(suite.T())

	logger := helpers.TestLogger()
	var err error
	suite.sessionMgr, err = whatsapp.NewSessionManager(suite.config, logger)
	require.NoError(suite.T(), err)

	suite.handler = NewContactHandler(suite.config, suite.sessionMgr, logger)
	suite.router = gin.New()

	// Setup routes
	suite.router.GET("/api/:secret/groups/:site_unique/:unique", suite.handler.GetGroups)
	suite.router.GET("/api/:secret/validate/:site_unique/:unique/:address", suite.handler.ValidateNumber)
}

func (suite *ContactHandlerTestSuite) TestNewContactHandler() {
	logger := helpers.TestLogger()
	handler := NewContactHandler(suite.config, suite.sessionMgr, logger)

	assert.NotNil(suite.T(), handler)
	assert.Equal(suite.T(), suite.config, handler.config)
	assert.Equal(suite.T(), suite.sessionMgr, handler.sessionMgr)
	assert.NotNil(suite.T(), handler.contactSvc)
	assert.Equal(suite.T(), logger, handler.logger)
}

func (suite *ContactHandlerTestSuite) TestGetGroups_InvalidSecret() {
	req := httptest.NewRequest("GET", "/api/wrong-secret/groups/site1/user1", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), float64(401), response["status"])
	assert.Equal(suite.T(), "Unauthorized", response["message"])
}

func (suite *ContactHandlerTestSuite) TestGetGroups_SessionNotFound() {
	req := httptest.NewRequest("GET", "/api/test-secret/groups/nonexistent/user", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusNotFound, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Session not found", response["message"])
}

func (suite *ContactHandlerTestSuite) TestGetGroups_Success() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("GET", "/api/test-secret/groups/"+cache.SiteUnique+"/"+cache.Unique, nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	// Should fail because session is not connected and no real client
	assert.Equal(suite.T(), http.StatusInternalServerError, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Failed to get groups", response["message"])

	_ = session // Mark as used
}

func (suite *ContactHandlerTestSuite) TestValidateNumber_InvalidSecret() {
	req := httptest.NewRequest("GET", "/api/wrong-secret/validate/site1/user1/1234567890", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)
}

func (suite *ContactHandlerTestSuite) TestValidateNumber_SessionNotFound() {
	req := httptest.NewRequest("GET", "/api/test-secret/validate/nonexistent/user/1234567890", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusNotFound, w.Code)
}

func (suite *ContactHandlerTestSuite) TestValidateNumber_Success() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("GET", "/api/test-secret/validate/"+cache.SiteUnique+"/"+cache.Unique+"/1234567890", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	// Should fail because session is not connected and no real client
	assert.Equal(suite.T(), http.StatusNotFound, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Phone number not on WhatsApp", response["message"])

	_ = session // Mark as used
}

func (suite *ContactHandlerTestSuite) TestValidateNumber_InternationalFormat() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Test various international phone number formats
	phoneNumbers := []string{
		"+1234567890",
		"1-234-567-8900",
		"%28123%29%20456-7890",   // URL-encoded "(123) 456-7890"
		"+44%2020%207946%200958", // URL-encoded "+44 20 7946 0958"
	}

	for _, phone := range phoneNumbers {
		req := httptest.NewRequest("GET", "/api/test-secret/validate/"+cache.SiteUnique+"/"+cache.Unique+"/"+phone, nil)
		w := httptest.NewRecorder()

		suite.router.ServeHTTP(w, req)

		// All should fail at validation stage but pass URL parsing
		assert.Equal(suite.T(), http.StatusNotFound, w.Code)
	}

	_ = session // Mark as used
}

func (suite *ContactHandlerTestSuite) TestConcurrentGroupOperations() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	done := make(chan bool, 6)

	// Get groups concurrently
	for i := 0; i < 3; i++ {
		go func() {
			defer func() { done <- true }()

			req := httptest.NewRequest("GET", "/api/test-secret/groups/"+cache.SiteUnique+"/"+cache.Unique, nil)
			w := httptest.NewRecorder()

			suite.router.ServeHTTP(w, req)
			assert.Equal(suite.T(), http.StatusInternalServerError, w.Code)
		}()
	}

	// Validate numbers concurrently
	for i := 0; i < 3; i++ {
		go func(index int) {
			defer func() { done <- true }()

			req := httptest.NewRequest("GET", "/api/test-secret/validate/"+cache.SiteUnique+"/"+cache.Unique+"/123456789"+string(rune(index+'0')), nil)
			w := httptest.NewRecorder()

			suite.router.ServeHTTP(w, req)
			assert.Equal(suite.T(), http.StatusNotFound, w.Code)
		}(i)
	}

	// Wait for all operations
	for i := 0; i < 6; i++ {
		<-done
	}

	_ = session // Mark as used
}

func (suite *ContactHandlerTestSuite) TestURLParameterEdgeCases() {
	// Create a session with special characters
	cache := helpers.TestSessionCache()
	cache.SiteUnique = "site-test"
	cache.Unique = "user.test"
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Test with phone number containing special characters
	req := httptest.NewRequest("GET", "/api/test-secret/validate/site-test/user.test/+123@456", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	// Should pass URL parsing but fail at validation level
	assert.Equal(suite.T(), http.StatusNotFound, w.Code)

	_ = session // Mark as used
}

func TestContactHandlerTestSuite(t *testing.T) {
	suite.Run(t, new(ContactHandlerTestSuite))
}

// Additional unit tests for edge cases
func TestContactHandler_EdgeCases(t *testing.T) {
	helpers.GinTestMode()

	config := helpers.TestConfig(t)
	logger := helpers.TestLogger()
	sessionMgr, err := whatsapp.NewSessionManager(config, logger)
	require.NoError(t, err)

	handler := NewContactHandler(config, sessionMgr, logger)
	router := gin.New()
	router.GET("/api/:secret/validate/:site_unique/:unique/:address", handler.ValidateNumber)

	t.Run("Empty phone number", func(t *testing.T) {
		// Create session
		cache := helpers.TestSessionCache()
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		// URL with trailing slash causes redirect (301) in Gin
		req := httptest.NewRequest("GET", "/api/test-secret/validate/"+cache.SiteUnique+"/"+cache.Unique+"/", nil)
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		// Gin returns 301 redirect for trailing slash
		assert.Equal(t, http.StatusMovedPermanently, w.Code)

		_ = session // Mark as used
	})

	t.Run("WhatsApp group JID", func(t *testing.T) {
		// Create session
		cache := helpers.TestSessionCache()
		cache.Unique = "group-test-user"
		cache.SiteUnique = "group-test-site"
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		// Test with group JID format
		groupJID := "120363123456789@g.us"
		req := httptest.NewRequest("GET", "/api/test-secret/validate/"+cache.SiteUnique+"/"+cache.Unique+"/"+groupJID, nil)
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		// Should pass JID parsing but fail at validation
		assert.Equal(t, http.StatusNotFound, w.Code)

		_ = session // Mark as used
	})

	t.Run("Very long group ID", func(t *testing.T) {
		// Create session
		cache := helpers.TestSessionCache()
		cache.Unique = "long-group-user"
		cache.SiteUnique = "long-group-site"
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		// Test with very long group ID
		longGroupID := strings.Repeat("1234567890", 10) + "@g.us"
		req := httptest.NewRequest("GET", "/api/test-secret/validate/"+cache.SiteUnique+"/"+cache.Unique+"/"+longGroupID, nil)
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		// Should still process long IDs
		assert.Equal(t, http.StatusNotFound, w.Code)

		_ = session // Mark as used
	})
}
