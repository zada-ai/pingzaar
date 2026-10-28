package handlers

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
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

type AccountHandlerTestSuite struct {
	suite.Suite
	ctx        context.Context
	tempDir    string
	config     *config.Config
	sessionMgr *whatsapp.SessionManager
	handler    *AccountHandler
	router     *gin.Engine
}

func (suite *AccountHandlerTestSuite) SetupTest() {
	helpers.GinTestMode()
	suite.ctx = context.Background()
	suite.tempDir = suite.T().TempDir()

	suite.config = helpers.TestConfig(suite.T())

	logger := helpers.TestLogger()
	var err error
	suite.sessionMgr, err = whatsapp.NewSessionManager(suite.config, logger)
	require.NoError(suite.T(), err)

	suite.handler = NewAccountHandler(suite.config, suite.sessionMgr, logger)
	suite.router = gin.New()

	// Setup routes
	suite.router.POST("/api/:secret/session", suite.handler.CreateSession)
	suite.router.GET("/api/:secret/session/:site_unique/:unique", suite.handler.GetSessionStatus)
	suite.router.DELETE("/api/:secret/session/:site_unique/:unique", suite.handler.DeleteSession)
	suite.router.GET("/api/:secret/sessions/total", suite.handler.GetTotalSessions)
	suite.router.PUT("/api/:secret/session/:site_unique/:unique", suite.handler.UpdateSession)
}

func (suite *AccountHandlerTestSuite) TestNewAccountHandler() {
	handler := NewAccountHandler(suite.config, suite.sessionMgr, helpers.TestLogger())

	assert.NotNil(suite.T(), handler)
	assert.Equal(suite.T(), suite.config, handler.config)
	assert.Equal(suite.T(), suite.sessionMgr, handler.sessionMgr)
	assert.NotNil(suite.T(), handler.logger)
}

func (suite *AccountHandlerTestSuite) TestCreateSession_InvalidSecret() {
	form := url.Values{
		"system_token": {"token123"},
		"site_unique":  {"site1"},
		"site_url":     {"https://example.com"},
		"api_token":    {"api123"},
		"wsid":         {"ws123"},
		"os":           {"linux"},
		"unique":       {"user1"},
		"uid":          {"uid123"},
		"hash":         {"hash123"},
	}

	req := httptest.NewRequest("POST", "/api/wrong-secret/session", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), float64(401), response["status"])
	assert.Equal(suite.T(), "Unauthorized", response["message"])
	assert.Equal(suite.T(), false, response["data"])
}

func (suite *AccountHandlerTestSuite) TestCreateSession_InvalidFormData() {
	// Missing required fields
	form := url.Values{
		"system_token": {"token123"},
		// Missing site_unique and other required fields
	}

	req := httptest.NewRequest("POST", "/api/test-secret/session", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusBadRequest, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), float64(400), response["status"])
	assert.Equal(suite.T(), "Invalid form data", response["message"])
}

func (suite *AccountHandlerTestSuite) TestCreateSession_Success() {
	form := url.Values{
		"system_token": {"token123"},
		"site_unique":  {"site1"},
		"site_url":     {"https://example.com"},
		"api_token":    {"api123"},
		"wsid":         {"ws123"},
		"os":           {"linux"},
		"unique":       {"user1"},
		"uid":          {"uid123"},
		"hash":         {"hash123"},
	}

	req := httptest.NewRequest("POST", "/api/test-secret/session", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), float64(200), response["status"])
	assert.Equal(suite.T(), false, response["message"])

	// Should return QR code initially
	data := response["data"].(map[string]interface{})
	assert.Contains(suite.T(), data, "qr")
}

func (suite *AccountHandlerTestSuite) TestGetSessionStatus_InvalidSecret() {
	req := httptest.NewRequest("GET", "/api/wrong-secret/session/site1/user1", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)
}

func (suite *AccountHandlerTestSuite) TestGetSessionStatus_SessionNotFound() {
	req := httptest.NewRequest("GET", "/api/test-secret/session/nonexistent/user", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusNotFound, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Session not found", response["message"])
}

func (suite *AccountHandlerTestSuite) TestGetSessionStatus_Success() {
	// Create a session first
	cache := helpers.TestSessionCache()
	_, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("GET", "/api/test-secret/session/"+cache.SiteUnique+"/"+cache.Unique, nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), float64(200), response["status"])
	assert.Equal(suite.T(), false, response["message"])

	// Should return session status as string
	assert.IsType(suite.T(), "", response["data"])
}

func (suite *AccountHandlerTestSuite) TestDeleteSession_InvalidSecret() {
	req := httptest.NewRequest("DELETE", "/api/wrong-secret/session/site1/user1", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)
}

func (suite *AccountHandlerTestSuite) TestDeleteSession_SessionNotFound() {
	req := httptest.NewRequest("DELETE", "/api/test-secret/session/nonexistent/user", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusNotFound, w.Code)
}

func (suite *AccountHandlerTestSuite) TestDeleteSession_Success() {
	// Create a session first
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("DELETE", "/api/test-secret/session/"+cache.SiteUnique+"/"+cache.Unique, nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Session deleted successfully", response["message"])

	// Verify session is deleted
	_, exists := suite.sessionMgr.GetSession(session.ID)
	assert.False(suite.T(), exists)
}

func (suite *AccountHandlerTestSuite) TestGetTotalSessions_InvalidSecret() {
	req := httptest.NewRequest("GET", "/api/wrong-secret/sessions/total", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)
}

func (suite *AccountHandlerTestSuite) TestGetTotalSessions_Success() {
	// Create some sessions
	for i := 0; i < 3; i++ {
		cache := helpers.TestSessionCache()
		cache.Unique = fmt.Sprintf("user%d", i)
		cache.SiteUnique = fmt.Sprintf("site%d", i)
		_, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
		require.NoError(suite.T(), err)
	}

	req := httptest.NewRequest("GET", "/api/test-secret/sessions/total", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), float64(200), response["status"])

	// Should return total count as number
	assert.Equal(suite.T(), float64(3), response["data"])
}

func (suite *AccountHandlerTestSuite) TestUpdateSession_InvalidSecret() {
	req := httptest.NewRequest("PUT", "/api/wrong-secret/session/site1/user1", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)
}

func (suite *AccountHandlerTestSuite) TestUpdateSession_SessionNotFound() {
	form := url.Values{
		"wsid":          {"new-wsid"},
		"receive_chats": {"true"},
		"random_send":   {"false"},
		"random_min":    {"1"},
		"random_max":    {"5"},
	}

	req := httptest.NewRequest("PUT", "/api/test-secret/session/nonexistent/user", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusNotFound, w.Code)
}

func (suite *AccountHandlerTestSuite) TestUpdateSession_InvalidFormData() {
	// Create a session first
	cache := helpers.TestSessionCache()
	_, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Missing required fields
	form := url.Values{
		"wsid": {"new-wsid"},
		// Missing required fields
	}

	req := httptest.NewRequest("PUT", "/api/test-secret/session/"+cache.SiteUnique+"/"+cache.Unique, strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusBadRequest, w.Code)
}

func (suite *AccountHandlerTestSuite) TestUpdateSession_Success() {
	// Create a session first
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	form := url.Values{
		"wsid":          {"new-wsid"},
		"receive_chats": {"true"},
		"random_send":   {"false"},
		"random_min":    {"1"},
		"random_max":    {"5"},
	}

	req := httptest.NewRequest("PUT", "/api/test-secret/session/"+cache.SiteUnique+"/"+cache.Unique, strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Session updated successfully", response["message"])

	// Verify session was updated
	updatedSession, exists := suite.sessionMgr.GetSession(session.ID)
	assert.True(suite.T(), exists)
	assert.Equal(suite.T(), "new-wsid", updatedSession.Cache.WSID)
}

func (suite *AccountHandlerTestSuite) TestCreateSession_JSONRequest() {
	reqBody := types.CreateSessionRequest{
		SystemToken: "token123",
		SiteUnique:  "site1",
		SiteURL:     "https://example.com",
		APIToken:    "api123",
		WSID:        "ws123",
		OS:          "linux",
		Unique:      "user1",
		UID:         "uid123",
		Hash:        "hash123",
	}

	jsonBody, err := json.Marshal(reqBody)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("POST", "/api/test-secret/session", bytes.NewReader(jsonBody))
	req.Header.Set("Content-Type", "application/json")
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), float64(200), response["status"])
}

func (suite *AccountHandlerTestSuite) TestConcurrentOperations() {
	// Test concurrent session operations
	// Note: We create the first session sequentially to initialize the third-party
	// libsignal logger, which has a known race condition in its lazy initialization.
	// This avoids false positive race detection from external library code.

	// First session created sequentially (initializes libsignal logger)
	{
		form := url.Values{
			"system_token": {"token_init"},
			"site_unique":  {"site_init"},
			"site_url":     {"https://example.com"},
			"api_token":    {"api_init"},
			"wsid":         {"ws_init"},
			"os":           {"linux"},
			"unique":       {"user_init"},
			"uid":          {"uid_init"},
			"hash":         {"hash_init"},
		}
		req := httptest.NewRequest("POST", "/api/test-secret/session", strings.NewReader(form.Encode()))
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		w := httptest.NewRecorder()
		suite.router.ServeHTTP(w, req)
		assert.Equal(suite.T(), http.StatusOK, w.Code)
	}

	done := make(chan bool, 6)

	// Create additional sessions concurrently (libsignal logger already initialized)
	for i := 0; i < 3; i++ {
		go func(index int) {
			defer func() { done <- true }()

			form := url.Values{
				"system_token": {"token" + string(rune(index))},
				"site_unique":  {"site" + string(rune(index))},
				"site_url":     {"https://example.com"},
				"api_token":    {"api" + string(rune(index))},
				"wsid":         {"ws" + string(rune(index))},
				"os":           {"linux"},
				"unique":       {"user" + string(rune(index))},
				"uid":          {"uid" + string(rune(index))},
				"hash":         {"hash" + string(rune(index))},
			}

			req := httptest.NewRequest("POST", "/api/test-secret/session", strings.NewReader(form.Encode()))
			req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
			w := httptest.NewRecorder()

			suite.router.ServeHTTP(w, req)
			assert.Equal(suite.T(), http.StatusOK, w.Code)
		}(i)
	}

	// Check total sessions concurrently
	for i := 0; i < 3; i++ {
		go func() {
			defer func() { done <- true }()

			req := httptest.NewRequest("GET", "/api/test-secret/sessions/total", nil)
			w := httptest.NewRecorder()

			suite.router.ServeHTTP(w, req)
			assert.Equal(suite.T(), http.StatusOK, w.Code)
		}()
	}

	// Wait for all operations
	for i := 0; i < 6; i++ {
		<-done
	}
}

func TestAccountHandlerTestSuite(t *testing.T) {
	suite.Run(t, new(AccountHandlerTestSuite))
}

// Additional unit tests for edge cases
func TestAccountHandler_EdgeCases(t *testing.T) {
	helpers.GinTestMode()

	config := helpers.TestConfig(t)
	logger := helpers.TestLogger()
	sessionMgr, err := whatsapp.NewSessionManager(config, logger)
	require.NoError(t, err)

	handler := NewAccountHandler(config, sessionMgr, logger)
	router := gin.New()
	router.POST("/api/:secret/session", handler.CreateSession)

	t.Run("Empty secret parameter", func(t *testing.T) {
		req := httptest.NewRequest("POST", "/api//session", nil)
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		// Empty secret should return 401 Unauthorized
		assert.Equal(t, http.StatusUnauthorized, w.Code)
	})

	t.Run("Special characters in form data", func(t *testing.T) {
		form := url.Values{
			"system_token": {"token@#$%"},
			"site_unique":  {"site-unique"},
			"site_url":     {"https://example.com/path?param=value"},
			"api_token":    {"api_token_with_underscores"},
			"wsid":         {"ws.id.with.dots"},
			"os":           {"linux"},
			"unique":       {"user+special"},
			"uid":          {"uid123"},
			"hash":         {"hash#with#special"},
		}

		req := httptest.NewRequest("POST", "/api/test-secret/session", strings.NewReader(form.Encode()))
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		// Should still work with special characters
		assert.Equal(t, http.StatusOK, w.Code)
	})
}
