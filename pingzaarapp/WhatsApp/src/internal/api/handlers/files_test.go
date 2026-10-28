package handlers

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
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

type FileHandlerTestSuite struct {
	suite.Suite
	ctx        context.Context
	tempDir    string
	config     *config.Config
	sessionMgr *whatsapp.SessionManager
	handler    *FileHandler
	router     *gin.Engine
}

func (suite *FileHandlerTestSuite) SetupTest() {
	helpers.GinTestMode()
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
			Host:     "localhost",
			Port:     8080,
			Debug:    true,
			Secret:   "test-secret",
			AutoRead: true,
		},
	}

	logger := helpers.TestLogger()
	var err error
	suite.sessionMgr, err = whatsapp.NewSessionManager(suite.config, logger)
	require.NoError(suite.T(), err)

	suite.handler = NewFileHandler(suite.config, suite.sessionMgr, logger)
	suite.router = gin.New()

	// Setup routes
	suite.router.GET("/api/:secret/files/:site_unique/:unique/:file", suite.handler.Download)
	suite.router.DELETE("/api/:secret/files/gc/:days", suite.handler.GarbageCollect)
}

func (suite *FileHandlerTestSuite) TestNewFileHandler() {
	logger := helpers.TestLogger()
	handler := NewFileHandler(suite.config, suite.sessionMgr, logger)

	assert.NotNil(suite.T(), handler)
	assert.Equal(suite.T(), suite.config, handler.config)
	assert.Equal(suite.T(), suite.sessionMgr, handler.sessionMgr)
	assert.NotNil(suite.T(), handler.fileSvc)
	assert.Equal(suite.T(), logger, handler.logger)
}

func (suite *FileHandlerTestSuite) TestDownload_InvalidSecret() {
	req := httptest.NewRequest("GET", "/api/wrong-secret/files/site1/user1/test.jpg", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusUnauthorized, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), float64(401), response["status"])
	assert.Equal(suite.T(), "Unauthorized", response["message"])
}

func (suite *FileHandlerTestSuite) TestDownload_SessionNotFound() {
	req := httptest.NewRequest("GET", "/api/test-secret/files/nonexistent/user/test.jpg", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusNotFound, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Session not found", response["message"])
}

func (suite *FileHandlerTestSuite) TestDownload_FileNotFound() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("GET", "/api/test-secret/files/"+cache.SiteUnique+"/"+cache.Unique+"/nonexistent.jpg", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusNotFound, w.Code)

	var response map[string]interface{}
	err = json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "File not found", response["message"])

	_ = session // Mark as used
}

func (suite *FileHandlerTestSuite) TestDownload_Success() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Create a test file in the expected directory structure
	// FileService looks for files in: ./storage/media/sessionID/filename where sessionID = unique_siteUnique
	sessionID := fmt.Sprintf("%s_%s", cache.Unique, cache.SiteUnique)
	mediaDir := filepath.Join("./storage", "media", sessionID)
	err = os.MkdirAll(mediaDir, 0755)
	require.NoError(suite.T(), err)
	defer os.RemoveAll("./storage") // Clean up after test

	testFile := filepath.Join(mediaDir, "test.jpg")
	testContent := []byte("fake image content")
	err = os.WriteFile(testFile, testContent, 0644)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("GET", "/api/test-secret/files/"+cache.SiteUnique+"/"+cache.Unique+"/test.jpg", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)
	assert.Equal(suite.T(), "application/octet-stream", w.Header().Get("Content-Type"))
	assert.Equal(suite.T(), "attachment; filename=\"test.jpg\"", w.Header().Get("Content-Disposition"))
	assert.Equal(suite.T(), strconv.Itoa(len(testContent)), w.Header().Get("Content-Length"))
	assert.Equal(suite.T(), testContent, w.Body.Bytes())

	_ = session // Mark as used
}

func (suite *FileHandlerTestSuite) TestDownload_SpecialCharactersInFilename() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Create a test file with special characters (URL encoded)
	// FileService looks for files in: ./storage/media/sessionID/filename where sessionID = unique_siteUnique
	sessionID := fmt.Sprintf("%s_%s", cache.Unique, cache.SiteUnique)
	mediaDir := filepath.Join("./storage", "media", sessionID)
	err = os.MkdirAll(mediaDir, 0755)
	require.NoError(suite.T(), err)
	defer os.RemoveAll("./storage") // Clean up after test

	// File with spaces and special chars
	fileName := "test file with spaces & special chars.jpg"
	testFile := filepath.Join(mediaDir, fileName)
	testContent := []byte("fake image content")
	err = os.WriteFile(testFile, testContent, 0644)
	require.NoError(suite.T(), err)

	// URL encode the filename for the request
	encodedFilename := strings.ReplaceAll(fileName, " ", "%20")
	encodedFilename = strings.ReplaceAll(encodedFilename, "&", "%26")

	req := httptest.NewRequest("GET", "/api/test-secret/files/"+cache.SiteUnique+"/"+cache.Unique+"/"+encodedFilename, nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)
	assert.Contains(suite.T(), w.Header().Get("Content-Disposition"), fileName)

	_ = session // Mark as used
}

func (suite *FileHandlerTestSuite) TestGarbageCollect_InvalidDays() {
	req := httptest.NewRequest("DELETE", "/api/test-secret/files/gc/invalid", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusBadRequest, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Invalid days parameter", response["message"])
}

func (suite *FileHandlerTestSuite) TestGarbageCollect_ZeroDays() {
	req := httptest.NewRequest("DELETE", "/api/test-secret/files/gc/0", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusBadRequest, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Invalid days parameter", response["message"])
}

func (suite *FileHandlerTestSuite) TestGarbageCollect_NegativeDays() {
	req := httptest.NewRequest("DELETE", "/api/test-secret/files/gc/-5", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusBadRequest, w.Code)
}

func (suite *FileHandlerTestSuite) TestGarbageCollect_Success() {
	req := httptest.NewRequest("DELETE", "/api/test-secret/files/gc/30", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)

	var response map[string]interface{}
	err := json.Unmarshal(w.Body.Bytes(), &response)
	require.NoError(suite.T(), err)

	assert.Equal(suite.T(), "Cleanup completed", response["message"])
}

func (suite *FileHandlerTestSuite) TestGarbageCollect_NoSecretValidation() {
	// Test that garbage collect doesn't validate secret (per PHP implementation)
	req := httptest.NewRequest("DELETE", "/api/any-secret/files/gc/30", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	// Should still work without proper secret validation
	assert.Equal(suite.T(), http.StatusOK, w.Code)
}

func (suite *FileHandlerTestSuite) TestConcurrentDownloads() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Create multiple test files
	// FileService looks for files in: ./storage/media/sessionID/filename where sessionID = unique_siteUnique
	sessionID := fmt.Sprintf("%s_%s", cache.Unique, cache.SiteUnique)
	mediaDir := filepath.Join("./storage", "media", sessionID)
	err = os.MkdirAll(mediaDir, 0755)
	require.NoError(suite.T(), err)
	defer os.RemoveAll("./storage") // Clean up after test

	for i := 0; i < 3; i++ {
		testFile := filepath.Join(mediaDir, "test"+strconv.Itoa(i)+".jpg")
		testContent := []byte("fake content " + strconv.Itoa(i))
		err = os.WriteFile(testFile, testContent, 0644)
		require.NoError(suite.T(), err)
	}

	done := make(chan bool, 3)

	// Download files concurrently
	for i := 0; i < 3; i++ {
		go func(index int) {
			defer func() { done <- true }()

			req := httptest.NewRequest("GET", "/api/test-secret/files/"+cache.SiteUnique+"/"+cache.Unique+"/test"+strconv.Itoa(index)+".jpg", nil)
			w := httptest.NewRecorder()

			suite.router.ServeHTTP(w, req)
			assert.Equal(suite.T(), http.StatusOK, w.Code)
		}(i)
	}

	// Wait for all downloads
	for i := 0; i < 3; i++ {
		<-done
	}

	_ = session // Mark as used
}

func (suite *FileHandlerTestSuite) TestDownload_EmptyFile() {
	// Create a session
	cache := helpers.TestSessionCache()
	session, err := suite.sessionMgr.CreateSession(suite.ctx, cache)
	require.NoError(suite.T(), err)

	// Create an empty test file
	// FileService looks for files in: ./storage/media/sessionID/filename where sessionID = unique_siteUnique
	sessionID := fmt.Sprintf("%s_%s", cache.Unique, cache.SiteUnique)
	mediaDir := filepath.Join("./storage", "media", sessionID)
	err = os.MkdirAll(mediaDir, 0755)
	require.NoError(suite.T(), err)
	defer os.RemoveAll("./storage") // Clean up after test

	testFile := filepath.Join(mediaDir, "empty.txt")
	err = os.WriteFile(testFile, []byte{}, 0644)
	require.NoError(suite.T(), err)

	req := httptest.NewRequest("GET", "/api/test-secret/files/"+cache.SiteUnique+"/"+cache.Unique+"/empty.txt", nil)
	w := httptest.NewRecorder()

	suite.router.ServeHTTP(w, req)

	assert.Equal(suite.T(), http.StatusOK, w.Code)
	assert.Equal(suite.T(), "0", w.Header().Get("Content-Length"))
	assert.Empty(suite.T(), w.Body.Bytes())

	_ = session // Mark as used
}

func TestFileHandlerTestSuite(t *testing.T) {
	suite.Run(t, new(FileHandlerTestSuite))
}

// Additional unit tests for edge cases
func TestFileHandler_EdgeCases(t *testing.T) {
	helpers.GinTestMode()

	config := &config.Config{
		Server: struct {
			Host     string `mapstructure:"host"`
			Port     int    `mapstructure:"port"`
			Debug    bool   `mapstructure:"debug"`
			Secret   string `mapstructure:"secret"`
			AutoRead bool   `mapstructure:"autoread"`
		}{
			Host:     "localhost",
			Port:     8080,
			Debug:    true,
			Secret:   "test-secret",
			AutoRead: true,
		},
	}

	logger := helpers.TestLogger()
	sessionMgr, err := whatsapp.NewSessionManager(config, logger)
	require.NoError(t, err)

	handler := NewFileHandler(config, sessionMgr, logger)
	router := gin.New()
	router.GET("/api/:secret/files/:site_unique/:unique/:file", handler.Download)

	t.Run("Very long filename", func(t *testing.T) {
		// Create session
		cache := helpers.TestSessionCache()
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		longFilename := strings.Repeat("a", 200) + ".txt"
		req := httptest.NewRequest("GET", "/api/test-secret/files/"+cache.SiteUnique+"/"+cache.Unique+"/"+longFilename, nil)
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		// Should fail to find file but handle long filename
		assert.Equal(t, http.StatusNotFound, w.Code)

		_ = session // Mark as used
	})

	t.Run("Filename with path traversal attempt", func(t *testing.T) {
		// Create session
		cache := helpers.TestSessionCache()
		cache.Unique = "traversal-user"
		cache.SiteUnique = "traversal-site"
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		// Attempt path traversal
		maliciousFilename := "../../../etc/passwd"
		req := httptest.NewRequest("GET", "/api/test-secret/files/"+cache.SiteUnique+"/"+cache.Unique+"/"+maliciousFilename, nil)
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		// Should fail to find file (and hopefully not traverse)
		assert.Equal(t, http.StatusNotFound, w.Code)

		_ = session // Mark as used
	})

	t.Run("Binary file content", func(t *testing.T) {
		// Create session
		cache := helpers.TestSessionCache()
		cache.Unique = "binary-user"
		cache.SiteUnique = "binary-site"
		session, err := sessionMgr.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		// Create a test file with binary content
		// FileService looks for files in: ./storage/media/sessionID/filename where sessionID = unique_siteUnique
		sessionID := fmt.Sprintf("%s_%s", cache.Unique, cache.SiteUnique)
		mediaDir := filepath.Join("./storage", "media", sessionID)
		err = os.MkdirAll(mediaDir, 0755)
		require.NoError(t, err)
		defer os.RemoveAll("./storage") // Clean up after test

		testFile := filepath.Join(mediaDir, "binary.bin")
		binaryContent := []byte{0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A} // PNG header
		err = os.WriteFile(testFile, binaryContent, 0644)
		require.NoError(t, err)

		req := httptest.NewRequest("GET", "/api/test-secret/files/"+cache.SiteUnique+"/"+cache.Unique+"/binary.bin", nil)
		w := httptest.NewRecorder()

		router.ServeHTTP(w, req)

		assert.Equal(t, http.StatusOK, w.Code)
		assert.Equal(t, binaryContent, w.Body.Bytes())

		_ = session // Mark as used
	})
}
