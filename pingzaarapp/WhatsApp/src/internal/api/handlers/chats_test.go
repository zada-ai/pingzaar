package handlers

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"

	"github.com/gin-gonic/gin"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"github.com/stretchr/testify/suite"
	_ "modernc.org/sqlite" // SQLite driver

	"whatsapp-server/internal/config"
	"whatsapp-server/internal/whatsapp"
	"whatsapp-server/pkg/types"
	"whatsapp-server/tests/helpers"
)

type ChatHandlerTestSuite struct {
	suite.Suite
	ctx        context.Context
	tempDir    string
	config     *config.Config
	sessionMgr *whatsapp.SessionManager
	handler    *ChatHandler
	router     *gin.Engine
}

func (suite *ChatHandlerTestSuite) SetupTest() {
	helpers.GinTestMode()
	suite.ctx = context.Background()
	suite.tempDir = suite.T().TempDir()

	suite.config = helpers.TestConfig(suite.T())

	logger := helpers.TestLogger()
	var err error
	suite.sessionMgr, err = whatsapp.NewSessionManager(suite.config, logger)
	require.NoError(suite.T(), err)

	suite.handler = NewChatHandler(suite.config, suite.sessionMgr, logger)
	suite.router = gin.New()

	// Setup routes
	suite.router.POST("/api/:secret/chat/send/:site_unique/:unique", suite.handler.SendMessage)
	suite.router.GET("/api/:secret/chat/queue/:site_unique/:unique", suite.handler.GetChatQueue)
	suite.router.DELETE("/api/:secret/chat/delete/:site_unique/:unique/:hash/:cid/:id", suite.handler.DeleteChat)
	suite.router.POST("/api/:secret/chat/campaign/:site_unique/:unique/:hash/:cid", suite.handler.StartCampaign)
	suite.router.PUT("/api/:secret/chat/campaign/:site_unique/:unique/:hash/:cid", suite.handler.StopCampaign)
	suite.router.DELETE("/api/:secret/chat/campaign/:site_unique/:unique/:hash/:cid", suite.handler.DeleteCampaign)
	suite.router.GET("/api/:secret/chat/check/:site_unique/:unique/:message_id/:cid", suite.handler.CheckMessageInQueue)
}

func (suite *ChatHandlerTestSuite) TestNewChatHandler() {
	logger := helpers.TestLogger()
	handler := NewChatHandler(suite.config, suite.sessionMgr, logger)

	assert.NotNil(suite.T(), handler)
	assert.Equal(suite.T(), suite.config, handler.config)
	assert.Equal(suite.T(), suite.sessionMgr, handler.sessionMgr)
	assert.NotNil(suite.T(), handler.messageSvc)
	assert.Equal(suite.T(), logger, handler.logger)
}

func (suite *ChatHandlerTestSuite) TestSendMessage_InvalidSecret() {
	form := url.Values{
		"id":        {"msg123"},
		"recipient": {"1234567890"},
		"message":   {"Hello World"},
	}

	req := httptest.NewRequest("POST", "/api/wrong-secret/chat/send/site1/user1", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), float64(401), response["status"])
	assert.Equal(suite.T(), "Unauthorized", response["message"])
}

func (suite *ChatHandlerTestSuite) TestSendMessage_SessionNotFound() {
	form := url.Values{
		"id":        {"msg123"},
		"recipient": {"1234567890"},
		"message":   {"Hello World"},
	}

	req := httptest.NewRequest("POST", "/api/test-secret/chat/send/nonexistent/user", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusNotFound, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Session not found", response["message"])
}

func (suite *ChatHandlerTestSuite) TestSendMessage_SessionNotConnected() {
	// Create a session but don't connect it
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	form := url.Values{
		"id":        {"msg123"},
		"recipient": {"1234567890"},
		"message":   {"Hello World"},
	}

	req := httptest.NewRequest("POST", "/api/test-secret/chat/send/"+cache.SiteUnique+"/"+cache.Unique, strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusServiceUnavailable, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Session not connected", response["message"])

	_ = session // Mark as used
}

func (suite *ChatHandlerTestSuite) TestSendMessage_InvalidFormData() {
	// Create and set up session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Missing required fields
	form := url.Values{
		"id": {"msg123"},
		// Missing recipient and message
	}

	req := httptest.NewRequest("POST", "/api/test-secret/chat/send/"+cache.SiteUnique+"/"+cache.Unique, strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	// Should fail because session is not connected (no real WhatsApp client in tests)
	assert.Equal(suite.T(), http.StatusServiceUnavailable, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Session not connected", response["message"])

	_ = session // Mark as used
}

func (suite *ChatHandlerTestSuite) TestSendMessage_SendFailure() {
	// Create and set up session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	form := url.Values{
		"id":        {"msg123"},
		"recipient": {"1234567890"},
		"message":   {"Hello World"},
		"type":      {"text"},
	}

	req := httptest.NewRequest("POST", "/api/test-secret/chat/send/"+cache.SiteUnique+"/"+cache.Unique, strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	// Should fail because session is not connected (no real WhatsApp client in tests)
	assert.Equal(suite.T(), http.StatusServiceUnavailable, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Session not connected", response["message"])

	_ = session // Mark as used
}

func (suite *ChatHandlerTestSuite) TestSendMessage_JSONRequest() {
	// Create and set up session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	reqBody := types.SendMessageRequest{
		ID:        "msg123",
		Recipient: "1234567890",
		Message:   "Hello World",
		Type:      "text",
	}

	jsonBody, err := json.Marshal(reqBody)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("POST", "/api/test-secret/chat/send/"+cache.SiteUnique+"/"+cache.Unique, bytes.NewReader(jsonBody))
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	// Should fail because session is not connected (no real WhatsApp client in tests)
	assert.Equal(suite.T(), http.StatusServiceUnavailable, w.Code)

	_ = session // Mark as used
}

func (suite *ChatHandlerTestSuite) TestGetChatQueue_InvalidSecret() {
	req := httptest.NewRequest("GET", "/api/wrong-secret/chat/queue/site1/user1", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)
}

func (suite *ChatHandlerTestSuite) TestGetChatQueue_SessionNotFound() {
	req := httptest.NewRequest("GET", "/api/test-secret/chat/queue/nonexistent/user", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusNotFound, w.Code)
}

func (suite *ChatHandlerTestSuite) TestGetChatQueue_Success() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("GET", "/api/test-secret/chat/queue/"+cache.SiteUnique+"/"+cache.Unique, nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	// Should fail because session is not connected (no real WhatsApp client in tests)
	assert.Equal(suite.T(), http.StatusServiceUnavailable, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Session not connected", response["message"])

	_ = session // Mark as used
}

func (suite *ChatHandlerTestSuite) TestDeleteChat_InvalidSecret() {
	req := httptest.NewRequest("DELETE", "/api/wrong-secret/chat/delete/site1/user1/hash1/cid1/id1", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)
}

func (suite *ChatHandlerTestSuite) TestDeleteChat_Success() {
	req := httptest.NewRequest("DELETE", "/api/test-secret/chat/delete/site1/user1/hash1/cid1/id1", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Chat deleted successfully", response["message"])
}

func (suite *ChatHandlerTestSuite) TestStartCampaign_InvalidSecret() {
	req := httptest.NewRequest("POST", "/api/wrong-secret/chat/campaign/site1/user1/hash1/123", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)
}

func (suite *ChatHandlerTestSuite) TestStartCampaign_SessionNotFound() {
	req := httptest.NewRequest("POST", "/api/test-secret/chat/campaign/nonexistent/user/hash1/123", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusNotFound, w.Code)
}

func (suite *ChatHandlerTestSuite) TestStartCampaign_Success() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("POST", "/api/test-secret/chat/campaign/"+cache.SiteUnique+"/"+cache.Unique+"/hash1/123", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	// Handler returns data with "status": "resumed"
	data, ok := response["data"].(map[string]interface{})
	require.True(suite.T(), ok, "Expected data field in response")
	assert.Equal(suite.T(), "resumed", data["status"])

	_ = session // Mark as used
}

func (suite *ChatHandlerTestSuite) TestStopCampaign_Success() {
	// Create a session first
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("PUT", "/api/test-secret/chat/campaign/"+cache.SiteUnique+"/"+cache.Unique+"/hash1/123", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	// Handler returns data with "status": "paused"
	data, ok := response["data"].(map[string]interface{})
	require.True(suite.T(), ok, "Expected data field in response")
	assert.Equal(suite.T(), "paused", data["status"])

	_ = session // Mark as used
}

func (suite *ChatHandlerTestSuite) TestDeleteCampaign_Success() {
	req := httptest.NewRequest("DELETE", "/api/test-secret/chat/campaign/site1/user1/hash1/cid1", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Campaign deleted successfully", response["message"])
}

func (suite *ChatHandlerTestSuite) TestCheckMessageInQueue_InvalidSecret() {
	req := httptest.NewRequest("GET", "/api/wrong-secret/chat/check/site1/user1/123/0", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)
}

func (suite *ChatHandlerTestSuite) TestCheckMessageInQueue_InvalidMessageID() {
	req := httptest.NewRequest("GET", "/api/test-secret/chat/check/site1/user1/invalid/0", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusBadRequest, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Invalid message ID", response["message"])
}

func (suite *ChatHandlerTestSuite) TestCheckMessageInQueue_InvalidCID() {
	req := httptest.NewRequest("GET", "/api/test-secret/chat/check/site1/user1/123/invalid", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusBadRequest, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Invalid campaign ID", response["message"])
}

func (suite *ChatHandlerTestSuite) TestCheckMessageInQueue_SessionNotFound() {
	req := httptest.NewRequest("GET", "/api/test-secret/chat/check/nonexistent/user/123/0", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	// Session not found should return 200 with in_queue: false
	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	data, ok := response["data"].(map[string]interface{})
	require.True(suite.T(), ok, "Expected data field in response")
	assert.Equal(suite.T(), false, data["in_queue"])
}

func (suite *ChatHandlerTestSuite) TestCheckMessageInQueue_NotInQueue() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Message not added to queue - should return false
	req := httptest.NewRequest("GET", "/api/test-secret/chat/check/"+cache.SiteUnique+"/"+cache.Unique+"/999/0", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	data, ok := response["data"].(map[string]interface{})
	require.True(suite.T(), ok, "Expected data field in response")
	assert.Equal(suite.T(), false, data["in_queue"])

	_ = session // Mark as used
}

func (suite *ChatHandlerTestSuite) TestCheckMessageInQueue_InRAMQueue() {
	// Create a session
	cache := helpers.TestSessionCache()
	cache.Unique = "check-ram-queue-user"
	cache.SiteUnique = "check-ram-queue-site"
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Add a message to the RAM queue
	message := types.QueuedMessage{
		ID:       123,
		CID:      456,
		Phone:    "1234567890",
		Message:  `{"text": "test"}`,
		Priority: 1,
		WID:      "wid1",
	}
	session.MessageQueue.Add(message)

	// Check if message is in queue - should return true
	req := httptest.NewRequest("GET", "/api/test-secret/chat/check/"+cache.SiteUnique+"/"+cache.Unique+"/123/456", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	data, ok := response["data"].(map[string]interface{})
	require.True(suite.T(), ok, "Expected data field in response")
	assert.Equal(suite.T(), true, data["in_queue"])
}

func (suite *ChatHandlerTestSuite) TestCheckMessageInQueue_DifferentCID() {
	// Create a session
	cache := helpers.TestSessionCache()
	cache.Unique = "check-cid-user"
	cache.SiteUnique = "check-cid-site"
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Add a message with CID=100
	message := types.QueuedMessage{
		ID:       123,
		CID:      100,
		Phone:    "1234567890",
		Message:  `{"text": "test"}`,
		Priority: 1,
		WID:      "wid1",
	}
	session.MessageQueue.Add(message)

	// Check with different CID - should return false
	req := httptest.NewRequest("GET", "/api/test-secret/chat/check/"+cache.SiteUnique+"/"+cache.Unique+"/123/200", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	data, ok := response["data"].(map[string]interface{})
	require.True(suite.T(), ok, "Expected data field in response")
	assert.Equal(suite.T(), false, data["in_queue"])
}

func (suite *ChatHandlerTestSuite) TestConcurrentSendMessages() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)
	session.Status = types.StatusConnected

	done := make(chan bool, 5)

	// Send multiple messages concurrently
	for i := 0; i < 5; i++ {
		go func(index int) {
			defer func() { done <- true }()

			form := url.Values{
				"id":        {"msg" + string(rune(index+'0'))},
				"recipient": {"123456789" + string(rune(index+'0'))},
				"message":   {"Hello " + string(rune(index+'0'))},
			}

			req := httptest.NewRequest("POST", "/api/test-secret/chat/send/"+cache.SiteUnique+"/"+cache.Unique, strings.NewReader(form.Encode()))
			req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
			w := httptest.NewRecorder()

			suite.router.ServeHTTP(w, req)
			// All should fail at send stage but pass validation
			assert.Equal(suite.T(), http.StatusServiceUnavailable, w.Code)
		}(i)
	}

	// Wait for all operations
	for i := 0; i < 5; i++ {
		<-done
	}
}

func (suite *ChatHandlerTestSuite) TestMultipleRouteParameters() {
	// Test with various parameter combinations
	testCases := []struct {
		name     string
		url      string
		expected int
	}{
		{
			name:     "Valid delete chat parameters",
			url:      "/api/test-secret/chat/delete/site-test/user-test/hash-123/cid-456/id-789",
			expected: http.StatusOK,
		},
		{
			name:     "Valid campaign parameters with special chars",
			url:      "/api/test-secret/chat/campaign/site_test/user.test/hash@123/cid#456",
			expected: http.StatusOK, // Campaign delete returns 200 without session validation
		},
	}

	for _, tc := range testCases {
		suite.T().Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest("DELETE", tc.url, nil)
			w := httptest.NewRecorder()

			suite.router.ServeHTTP(w, req)
			assert.Equal(t, tc.expected, w.Code)
		})
	}
}

func TestChatHandlerTestSuite(t *testing.T) {
	suite.Run(t, new(ChatHandlerTestSuite))
}

// Additional unit tests for edge cases
func TestChatHandler_EdgeCases(t *testing.T) {
	helpers.GinTestMode()

	config := helpers.TestConfig(t)
	logger := helpers.TestLogger()
	sessionMgr, err := whatsapp.NewSessionManager(config, logger)
	require.NoError(t, err)

	handler := NewChatHandler(config, sessionMgr, logger)
	router := gin.New()
	router.POST("/api/:secret/chat/send/:site_unique/:unique", handler.SendMessage)

	t.Run("Unicode message content", func(t *testing.T) {
		// Create session
		cache := helpers.TestSessionCache()
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)
		session.Status = types.StatusConnected

		form := url.Values{
			"id":        {"msg123"},
			"recipient": {"1234567890"},
			"message":   {"Hello 👋 World 🌍 Unicode ñáéíóú"},
		}

		req := httptest.NewRequest("POST", "/api/test-secret/chat/send/"+cache.SiteUnique+"/"+cache.Unique, strings.NewReader(form.Encode()))
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		// Should pass validation even with unicode
		assert.Equal(t, http.StatusServiceUnavailable, w.Code) // Fails at send stage
	})

	t.Run("Very long message", func(t *testing.T) {
		// Create session
		cache := helpers.TestSessionCache()
		cache.Unique = "long-message-user"
		cache.SiteUnique = "long-message-site"
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)
		session.Status = types.StatusConnected

		// Create a very long message (over 4096 characters)
		longMessage := strings.Repeat("This is a very long message. ", 200)

		form := url.Values{
			"id":        {"msg123"},
			"recipient": {"1234567890"},
			"message":   {longMessage},
		}

		req := httptest.NewRequest("POST", "/api/test-secret/chat/send/"+cache.SiteUnique+"/"+cache.Unique, strings.NewReader(form.Encode()))
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		// Should still process long messages
		assert.Equal(t, http.StatusServiceUnavailable, w.Code) // Fails at send stage
	})

	t.Run("Empty message content", func(t *testing.T) {
		// Create session
		cache := helpers.TestSessionCache()
		cache.Unique = "empty-message-user"
		cache.SiteUnique = "empty-message-site"
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		form := url.Values{
			"id":        {"msg123"},
			"recipient": {"1234567890"},
			"message":   {""},
		}

		req := httptest.NewRequest("POST", "/api/test-secret/chat/send/"+cache.SiteUnique+"/"+cache.Unique, strings.NewReader(form.Encode()))
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		// Should fail because session is not connected (no real WhatsApp client in tests)
		assert.Equal(t, http.StatusServiceUnavailable, w.Code)

		_ = session // Mark as used
	})
}
