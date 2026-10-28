package whatsapp

import (
	"context"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"github.com/stretchr/testify/suite"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/binary/proto"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	_ "modernc.org/sqlite" // SQLite driver

	"whatsapp-server/internal/config"
	goTypes "whatsapp-server/pkg/types"
	"whatsapp-server/tests/helpers"
)

type WebhookServiceTestSuite struct {
	suite.Suite
	tempDir        string
	config         *config.Config
	sessionMgr     *SessionManager
	webhookService *WebhookService
	session        *goTypes.Session
	testServer     *httptest.Server
}

func (suite *WebhookServiceTestSuite) SetupTest() {
	helpers.GinTestMode()
	suite.tempDir = suite.T().TempDir()

	// Create a test HTTP server
	suite.testServer = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("OK"))
	}))

	suite.config = &config.Config{}
	suite.config.Server.Secret = "test-secret"
	suite.config.Server.AutoRead = true
	suite.config.Server.Host = "127.0.0.1"
	suite.config.Server.Port = 8899

	logger := helpers.TestLogger()
	var err error
	suite.sessionMgr, err = NewSessionManager(suite.config, logger)
	require.NoError(suite.T(), err)

	suite.webhookService = NewWebhookService(suite.sessionMgr, logger)

	// Create a test session
	cache := helpers.TestSessionCache()
	cache.SiteURL = suite.testServer.URL // Use test server URL
	suite.session, err = suite.sessionMgr.CreateSession(suite.T().Context(), cache)
	require.NoError(suite.T(), err)
}

func (suite *WebhookServiceTestSuite) TearDownTest() {
	suite.testServer.Close()
}

func (suite *WebhookServiceTestSuite) TestNewWebhookService() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.config, logger)
	require.NoError(suite.T(), err)

	ws := NewWebhookService(sm, logger)

	assert.NotNil(suite.T(), ws)
	assert.NotNil(suite.T(), ws.sessionMgr)
	assert.Equal(suite.T(), logger, ws.logger)
	assert.NotNil(suite.T(), ws.httpClient)
	assert.NotNil(suite.T(), ws.deduplicator)
}

func (suite *WebhookServiceTestSuite) TestSendMessageReceived_TextMessage() {
	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "987654321", Server: "s.whatsapp.net"},
				Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        "msg-123",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "Hello webhook!"; return &s }(),
		},
	}

	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)
	assert.NoError(suite.T(), err)
}

func (suite *WebhookServiceTestSuite) TestSendMessageReceived_GroupMessage() {
	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "group123", Server: "g.us"},
				Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  true,
			},
			ID:        "msg-group-123",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "Group message!"; return &s }(),
		},
	}

	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)
	assert.NoError(suite.T(), err)
}

func (suite *WebhookServiceTestSuite) TestSendMessageReceived_MediaMessage() {
	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "987654321", Server: "s.whatsapp.net"},
				Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        "msg-media-123",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			ImageMessage: &proto.ImageMessage{
				Caption: func() *string { s := "Image with caption"; return &s }(),
			},
		},
	}

	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)
	assert.NoError(suite.T(), err)
}

func (suite *WebhookServiceTestSuite) TestExtractMessageContent() {
	testCases := []struct {
		name     string
		message  *proto.Message
		expected string
	}{
		{
			name: "Conversation message",
			message: &proto.Message{
				Conversation: func() *string { s := "Hello world"; return &s }(),
			},
			expected: "Hello world",
		},
		{
			name: "Extended text message",
			message: &proto.Message{
				ExtendedTextMessage: &proto.ExtendedTextMessage{
					Text: func() *string { s := "Extended text"; return &s }(),
				},
			},
			expected: "Extended text",
		},
		{
			name: "Image message with caption",
			message: &proto.Message{
				ImageMessage: &proto.ImageMessage{
					Caption: func() *string { s := "Image caption"; return &s }(),
				},
			},
			expected: "Image caption",
		},
		{
			name: "Video message with caption",
			message: &proto.Message{
				VideoMessage: &proto.VideoMessage{
					Caption: func() *string { s := "Video caption"; return &s }(),
				},
			},
			expected: "Video caption",
		},
		{
			name: "Document message with caption",
			message: &proto.Message{
				DocumentMessage: &proto.DocumentMessage{
					Caption: func() *string { s := "Document caption"; return &s }(),
				},
			},
			expected: "Document caption",
		},
		{
			name: "Location message",
			message: &proto.Message{
				LocationMessage: &proto.LocationMessage{
					DegreesLatitude:  func() *float64 { f := 40.7128; return &f }(),
					DegreesLongitude: func() *float64 { f := -74.0060; return &f }(),
				},
			},
			expected: "Location: 40.712800, -74.006000",
		},
		{
			name:     "Empty message",
			message:  &proto.Message{},
			expected: "",
		},
	}

	for _, tc := range testCases {
		suite.T().Run(tc.name, func(t *testing.T) {
			content := suite.webhookService.extractMessageContent(tc.message)
			assert.Equal(t, tc.expected, content)
		})
	}
}

func (suite *WebhookServiceTestSuite) TestHasMediaContent() {
	testCases := []struct {
		name     string
		message  *proto.Message
		expected bool
	}{
		{
			name: "Image message",
			message: &proto.Message{
				ImageMessage: &proto.ImageMessage{},
			},
			expected: true,
		},
		{
			name: "Video message",
			message: &proto.Message{
				VideoMessage: &proto.VideoMessage{},
			},
			expected: true,
		},
		{
			name: "Audio message",
			message: &proto.Message{
				AudioMessage: &proto.AudioMessage{},
			},
			expected: true,
		},
		{
			name: "Document message",
			message: &proto.Message{
				DocumentMessage: &proto.DocumentMessage{},
			},
			expected: true,
		},
		{
			name: "Text message",
			message: &proto.Message{
				Conversation: func() *string { s := "text"; return &s }(),
			},
			expected: false,
		},
		{
			name: "Location message",
			message: &proto.Message{
				LocationMessage: &proto.LocationMessage{},
			},
			expected: false,
		},
		{
			name:     "Empty message",
			message:  &proto.Message{},
			expected: false,
		},
	}

	for _, tc := range testCases {
		suite.T().Run(tc.name, func(t *testing.T) {
			hasMedia := suite.webhookService.hasMediaContent(tc.message)
			assert.Equal(t, tc.expected, hasMedia)
		})
	}
}

func (suite *WebhookServiceTestSuite) TestSendWithRetry_Success() {
	// Test server returns 200 OK
	err := suite.webhookService.sendWithRetry(suite.testServer.URL+"/webhook", nil, suite.session.ID)
	assert.NoError(suite.T(), err)
}

func (suite *WebhookServiceTestSuite) TestSendWithRetry_Failure() {
	// Create a server that always returns 500
	failServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer failServer.Close()

	err := suite.webhookService.sendWithRetry(failServer.URL+"/webhook", nil, suite.session.ID)
	assert.Error(suite.T(), err)
	assert.Contains(suite.T(), err.Error(), "failed after 1 attempt") // Changed from 3 to 1
}

func (suite *WebhookServiceTestSuite) TestSendWithRetry_EventualSuccess() {
	// With maxRetries=1 (no retries), this test now verifies first attempt success
	attempts := 0
	retryServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		attempts++
		// Always succeed on first attempt (no retries with maxRetries=1)
		w.WriteHeader(http.StatusOK)
	}))
	defer retryServer.Close()

	err := suite.webhookService.sendWithRetry(retryServer.URL+"/webhook", nil, suite.session.ID)
	assert.NoError(suite.T(), err)
	assert.Equal(suite.T(), 1, attempts) // Changed from 3 to 1 (no retries)
}

func (suite *WebhookServiceTestSuite) TestSendWithRetry_InvalidURL() {
	err := suite.webhookService.sendWithRetry("invalid-url", nil, suite.session.ID)
	assert.Error(suite.T(), err)
}

func (suite *WebhookServiceTestSuite) TestSaveMediaFile() {
	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:   types.JID{User: "987654321", Server: "s.whatsapp.net"},
				Sender: types.JID{User: "123456789", Server: "s.whatsapp.net"},
			},
			ID: "media-msg-123",
		},
		Message: &proto.Message{
			ImageMessage: &proto.ImageMessage{},
		},
	}

	// The test session is not connected, so DownloadToFile errors out and
	// saveMediaFile returns the empty string.
	filename := suite.webhookService.saveMediaFile(messageEvent, suite.session)
	assert.Equal(suite.T(), "", filename)
}

func (suite *WebhookServiceTestSuite) TestSaveMediaFile_UnwindsPartialFileOnDownloadError() {
	// Force the media path into the test temp dir so we can assert no stray
	// file is left behind on download failure.
	origDir, err := os.Getwd()
	require.NoError(suite.T(), err)
	require.NoError(suite.T(), os.Chdir(suite.tempDir))
	defer os.Chdir(origDir)

	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:   types.JID{User: "987654321", Server: "s.whatsapp.net"},
				Sender: types.JID{User: "123456789", Server: "s.whatsapp.net"},
			},
			ID: "partial-media-msg",
		},
		Message: &proto.Message{
			ImageMessage: &proto.ImageMessage{},
		},
	}

	filename := suite.webhookService.saveMediaFile(messageEvent, suite.session)
	require.Equal(suite.T(), "", filename, "failed download must return empty filename")

	// No partial file should remain for this message in the media directory.
	mediaDir := filepath.Join("storage", "media", suite.session.ID)
	entries, err := os.ReadDir(mediaDir)
	if err != nil {
		// The directory may not exist at all if MkdirAll succeeded but we removed
		// everything; that's also a valid unwound state.
		return
	}
	for _, entry := range entries {
		assert.NotContains(suite.T(), entry.Name(), "partial-media-msg",
			"download-error path must unwind the partially written file")
	}
}

// TestOsFileSatisfiesWhatsmeowFile is a compile-time assertion that *os.File
// satisfies the whatsmeow.File interface required by DownloadToFile. If the
// library ever changes the interface, this will fail to compile.
func TestOsFileSatisfiesWhatsmeowFile(t *testing.T) {
	var _ whatsmeow.File = (*os.File)(nil)
}

func (suite *WebhookServiceTestSuite) TestWebhookPayloadConstruction() {
	// Create a mock server that captures the request
	var capturedData map[string]string
	captureServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		err := r.ParseForm()
		if err == nil {
			capturedData = make(map[string]string)
			for key, values := range r.Form {
				if len(values) > 0 {
					capturedData[key] = values[0]
				}
			}
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer captureServer.Close()

	// Update session to use capture server
	suite.session.Cache.SiteURL = captureServer.URL

	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "987654321", Server: "s.whatsapp.net"},
				Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        "test-payload",
			Timestamp: time.Unix(1234567890, 0),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "Test message"; return &s }(),
		},
	}

	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)
	assert.NoError(suite.T(), err)

	// Verify payload was constructed correctly
	assert.NotNil(suite.T(), capturedData)
	assert.Equal(suite.T(), suite.session.Cache.SystemToken, capturedData["system_token"])
	assert.Equal(suite.T(), suite.session.Cache.UID, capturedData["uid"])
	assert.Equal(suite.T(), suite.session.Cache.Hash, capturedData["hash"])
	assert.Equal(suite.T(), suite.session.Cache.Unique, capturedData["unique"])
	assert.Equal(suite.T(), "123456789", capturedData["phone"])
	assert.Equal(suite.T(), "Test message", capturedData["message"])
	assert.Equal(suite.T(), "1234567890", capturedData["timestamp"])
	assert.Equal(suite.T(), "", capturedData["group"]) // Direct message: group is empty string
}

func (suite *WebhookServiceTestSuite) TestWebhookPayloadConstruction_GroupMessage() {
	// Create a mock server that captures the request
	var capturedData map[string]string
	captureServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		err := r.ParseForm()
		if err == nil {
			capturedData = make(map[string]string)
			for key, values := range r.Form {
				if len(values) > 0 {
					capturedData[key] = values[0]
				}
			}
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer captureServer.Close()

	// Update session to use capture server
	suite.session.Cache.SiteURL = captureServer.URL

	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "group123", Server: "g.us"},
				Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  true,
			},
			ID:        "group-test-payload",
			Timestamp: time.Unix(1234567890, 0),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "Group test message"; return &s }(),
		},
	}

	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)
	assert.NoError(suite.T(), err)

	// Verify group payload was constructed correctly (matches Node.js behavior)
	assert.NotNil(suite.T(), capturedData)
	// Note: group field will be empty in tests because GetGroupInfo fails without real connection
	// In production, this would contain the actual group name (e.g., "Family Group")
	assert.Equal(suite.T(), "", capturedData["group"])              // Group name (empty in tests)
	assert.Equal(suite.T(), "group123@g.us", capturedData["phone"]) // Full group JID for group messages
	assert.Equal(suite.T(), "Group test message", capturedData["message"])
}

func (suite *WebhookServiceTestSuite) TestWebhookPayloadConstruction_MultideviceJID() {
	// Create a mock server that captures the request
	var capturedData map[string]string
	captureServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		err := r.ParseForm()
		if err == nil {
			capturedData = make(map[string]string)
			for key, values := range r.Form {
				if len(values) > 0 {
					capturedData[key] = values[0]
				}
			}
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer captureServer.Close()

	// Update session to use capture server
	suite.session.Cache.SiteURL = captureServer.URL

	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat: types.JID{User: "987654321", Server: "s.whatsapp.net"},
				// Multidevice JID with device ID 34 (simulates WhatsApp Web/Desktop)
				Sender: types.JID{
					User:   "5218138665955",
					Device: 34,
					Server: "s.whatsapp.net",
				},
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        "multidevice-test-payload",
			Timestamp: time.Unix(1728518400, 0),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "Message from device 34"; return &s }(),
		},
	}

	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)
	assert.NoError(suite.T(), err)

	// Verify payload was constructed correctly WITHOUT device suffix
	assert.NotNil(suite.T(), capturedData)
	assert.Equal(suite.T(), "5218138665955", capturedData["phone"], "Phone should not contain device suffix")
	assert.NotContains(suite.T(), capturedData["phone"], ":34", "Phone should not contain :34 device ID")
	assert.NotContains(suite.T(), capturedData["phone"], ":", "Phone should not contain any colon")
	assert.Equal(suite.T(), "Message from device 34", capturedData["message"])
	assert.Equal(suite.T(), "1728518400", capturedData["timestamp"])
}

func (suite *WebhookServiceTestSuite) TestWebhookPayloadConstruction_PhoneFormats() {
	// Test phone field format for group vs direct messages (matches Node.js behavior)
	// Group messages: phone = "120363403405318006@g.us" (full group JID)
	// Direct messages: phone = "5218138665955" (sender's phone only)

	testCases := []struct {
		name          string
		chatJID       types.JID
		senderJID     types.JID
		isGroup       bool
		expectedPhone string
	}{
		{
			name:          "Direct message - phone only",
			chatJID:       types.JID{User: "5218138665955", Server: "s.whatsapp.net"},
			senderJID:     types.JID{User: "5218138665955", Server: "s.whatsapp.net"},
			isGroup:       false,
			expectedPhone: "5218138665955",
		},
		{
			name:          "Group message - full group JID",
			chatJID:       types.JID{User: "120363403405318006", Server: "g.us"},
			senderJID:     types.JID{User: "5218138665955", Server: "s.whatsapp.net"},
			isGroup:       true,
			expectedPhone: "120363403405318006@g.us",
		},
	}

	for _, tc := range testCases {
		suite.T().Run(tc.name, func(t *testing.T) {
			var capturedData map[string]string
			captureServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				r.ParseForm()
				capturedData = make(map[string]string)
				for key, values := range r.Form {
					if len(values) > 0 {
						capturedData[key] = values[0]
					}
				}
				w.WriteHeader(http.StatusOK)
			}))
			defer captureServer.Close()

			suite.session.Cache.SiteURL = captureServer.URL

			messageEvent := &events.Message{
				Info: types.MessageInfo{
					MessageSource: types.MessageSource{
						Chat:     tc.chatJID,
						Sender:   tc.senderJID,
						IsFromMe: false,
						IsGroup:  tc.isGroup,
					},
					ID:        "phone-format-test-" + tc.name, // Unique ID per test case
					Timestamp: time.Now(),
				},
				Message: &proto.Message{
					Conversation: func() *string { s := "Test message"; return &s }(),
				},
			}

			err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)
			assert.NoError(t, err)
			assert.Equal(t, tc.expectedPhone, capturedData["phone"])
		})
	}
}

func (suite *WebhookServiceTestSuite) TestSendMessageReceived_EmptyMessageSkipped() {
	// Test that empty messages without content or media are skipped
	// This matches Node.js behavior to avoid unnecessary webhook calls

	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "987654321", Server: "s.whatsapp.net"},
				Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        "empty-msg-123",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{}, // Empty message with no content
	}

	// Should return nil (no error) and skip webhook
	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)
	assert.NoError(suite.T(), err)

	// Note: Webhook should NOT be called (empty message filtered out)
}

func (suite *WebhookServiceTestSuite) TestConcurrentWebhookSending() {
	// Test sending multiple webhooks concurrently
	done := make(chan bool, 10)

	for i := 0; i < 10; i++ {
		go func(index int) {
			defer func() { done <- true }()

			messageEvent := &events.Message{
				Info: types.MessageInfo{
					MessageSource: types.MessageSource{
						Chat:     types.JID{User: "987654321", Server: "s.whatsapp.net"},
						Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
						IsFromMe: false,
						IsGroup:  false,
					},
					ID:        "concurrent-" + string(rune(index+'0')),
					Timestamp: time.Now(),
				},
				Message: &proto.Message{
					Conversation: func() *string { s := "Concurrent message"; return &s }(),
				},
			}

			err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)
			assert.NoError(suite.T(), err)
		}(i)
	}

	// Wait for all goroutines to complete
	for i := 0; i < 10; i++ {
		<-done
	}
}

func TestWebhookServiceTestSuite(t *testing.T) {
	suite.Run(t, new(WebhookServiceTestSuite))
}

// Additional unit tests for edge cases
func TestWebhookService_EdgeCases(t *testing.T) {
	helpers.GinTestMode()

	config := &config.Config{}
	config.Server.Secret = "test-secret"
	config.Server.AutoRead = true
	config.Server.Host = "127.0.0.1"
	config.Server.Port = 8899

	logger := helpers.TestLogger()
	sm, err := NewSessionManager(config, logger)
	require.NoError(t, err)

	ws := NewWebhookService(sm, logger)

	t.Run("Nil message handling", func(t *testing.T) {
		content := ws.extractMessageContent(nil)
		assert.Equal(t, "", content)

		hasMedia := ws.hasMediaContent(nil)
		assert.False(t, hasMedia)
	})

	t.Run("URL construction with trailing slash", func(t *testing.T) {
		cache := helpers.TestSessionCache()
		cache.SiteURL = "https://example.com/"
		session, err := sm.CreateSession(context.Background(), cache)
		require.NoError(t, err)

		messageEvent := &events.Message{
			Info: types.MessageInfo{
				MessageSource: types.MessageSource{
					Chat:   types.JID{User: "987654321", Server: "s.whatsapp.net"},
					Sender: types.JID{User: "123456789", Server: "s.whatsapp.net"},
				},
				ID: "url-test",
			},
			Message: &proto.Message{
				Conversation: func() *string { s := "test"; return &s }(),
			},
		}

		// This should handle the trailing slash correctly
		// Since there's no real server, it will fail, but we can test the URL construction logic
		err = ws.SendMessageReceived(messageEvent, session)
		assert.Error(t, err) // Expected to fail due to no real server
	})

	t.Run("HTTP client is initialized", func(t *testing.T) {
		assert.NotNil(t, ws.httpClient)
	})

	t.Run("hasValidContent - empty message", func(t *testing.T) {
		emptyMsg := &proto.Message{}
		assert.False(t, ws.hasValidContent(emptyMsg), "Empty message should not have valid content")
	})

	t.Run("hasValidContent - text message", func(t *testing.T) {
		textMsg := &proto.Message{
			Conversation: func() *string { s := "Hello"; return &s }(),
		}
		assert.True(t, ws.hasValidContent(textMsg), "Text message should have valid content")
	})

	t.Run("hasValidContent - image without caption", func(t *testing.T) {
		imageMsg := &proto.Message{
			ImageMessage: &proto.ImageMessage{},
		}
		assert.True(t, ws.hasValidContent(imageMsg), "Image message should have valid content (has media)")
	})

	t.Run("hasValidContent - location message", func(t *testing.T) {
		locationMsg := &proto.Message{
			LocationMessage: &proto.LocationMessage{
				DegreesLatitude:  func() *float64 { f := 40.7128; return &f }(),
				DegreesLongitude: func() *float64 { f := -74.0060; return &f }(),
			},
		}
		assert.True(t, ws.hasValidContent(locationMsg), "Location message should have valid content")
	})

	t.Run("hasValidContent - audio message", func(t *testing.T) {
		audioMsg := &proto.Message{
			AudioMessage: &proto.AudioMessage{},
		}
		assert.True(t, ws.hasValidContent(audioMsg), "Audio message should have valid content (has media)")
	})
}

// TestSendMessageReceived_LIDAddressing tests webhook with LID addressing
func (suite *WebhookServiceTestSuite) TestSendMessageReceived_LIDAddressing() {
	messageText := "Hello from LID user"

	// Create message event with LID addressing
	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:         types.JID{User: "0:654321", Server: "lid"},
				RecipientAlt: types.JID{User: "9876543210", Server: "s.whatsapp.net"},
				Sender:       types.JID{User: "0:123456", Server: "lid"},
				SenderAlt:    types.JID{User: "1234567890", Server: "s.whatsapp.net"},
				IsFromMe:     false,
				IsGroup:      false,
			},
			ID:        "lid-msg-123",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: &messageText,
		},
	}

	// Create test HTTP server to capture webhook request
	var capturedPayload url.Values
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		r.ParseForm()
		capturedPayload = r.Form
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	// Update session to use capture server
	suite.session.Cache.SiteURL = server.URL

	// Send webhook
	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)

	// Verify success
	assert.NoError(suite.T(), err)
	assert.NotNil(suite.T(), capturedPayload)

	// Verify phone number was extracted from SenderAlt (not LID)
	assert.Equal(suite.T(), "1234567890", capturedPayload.Get("phone"))
	assert.Equal(suite.T(), messageText, capturedPayload.Get("message"))
	assert.Equal(suite.T(), "", capturedPayload.Get("group")) // DM, not group
}

// TestSendMessageReceived_LIDGroupMessage tests webhook with LID group message
func (suite *WebhookServiceTestSuite) TestSendMessageReceived_LIDGroupMessage() {
	messageText := "Group message from LID user"
	groupJID := types.JID{User: "120363111222333", Server: "g.us"}

	// Note: group name will be empty in tests because GetGroupInfo fails without real connection
	// In production, this would contain the actual group name

	// Create group message event with LID sender
	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:      groupJID,
				Sender:    types.JID{User: "0:789012", Server: "lid"},
				SenderAlt: types.JID{User: "5551234567", Server: "s.whatsapp.net"},
				IsFromMe:  false,
				IsGroup:   true,
			},
			ID:        "lid-group-msg-456",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: &messageText,
		},
	}

	// Create test HTTP server
	var capturedPayload url.Values
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		r.ParseForm()
		capturedPayload = r.Form
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	suite.session.Cache.SiteURL = server.URL

	// Send webhook
	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)

	// Verify success
	assert.NoError(suite.T(), err)
	assert.NotNil(suite.T(), capturedPayload)

	// Verify group JID was used (not affected by LID)
	assert.Equal(suite.T(), "120363111222333@g.us", capturedPayload.Get("phone"))
	// Group name will be empty in tests (no real WhatsApp connection)
	assert.Equal(suite.T(), "", capturedPayload.Get("group"))
	assert.Equal(suite.T(), messageText, capturedPayload.Get("message"))
}

// TestSendMessageReceived_MixedLIDAndPN tests webhook with both LID and PN messages
func (suite *WebhookServiceTestSuite) TestSendMessageReceived_MixedLIDAndPN() {
	// Traditional PN message
	pnMessage := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "1111111111", Server: "s.whatsapp.net"},
				Sender:   types.JID{User: "2222222222", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        "pn-msg",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "PN message"; return &s }(),
		},
	}

	// LID message
	lidMessage := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:         types.JID{User: "0:333333", Server: "lid"},
				RecipientAlt: types.JID{User: "3333333333", Server: "s.whatsapp.net"},
				Sender:       types.JID{User: "0:444444", Server: "lid"},
				SenderAlt:    types.JID{User: "4444444444", Server: "s.whatsapp.net"},
				IsFromMe:     false,
				IsGroup:      false,
			},
			ID:        "lid-msg",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "LID message"; return &s }(),
		},
	}

	// Capture payloads
	var pnPayload, lidPayload url.Values
	requestCount := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		r.ParseForm()
		if requestCount == 0 {
			pnPayload = r.Form
		} else {
			lidPayload = r.Form
		}
		requestCount++
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	suite.session.Cache.SiteURL = server.URL

	// Send both webhooks
	err1 := suite.webhookService.SendMessageReceived(pnMessage, suite.session)
	err2 := suite.webhookService.SendMessageReceived(lidMessage, suite.session)

	// Verify both succeeded
	assert.NoError(suite.T(), err1)
	assert.NoError(suite.T(), err2)

	// Verify PN message used Sender directly
	assert.Equal(suite.T(), "2222222222", pnPayload.Get("phone"))

	// Verify LID message used SenderAlt
	assert.Equal(suite.T(), "4444444444", lidPayload.Get("phone"))
}

// TestSendMessageReceived_LIDWithEmptySenderAlt tests fallback when SenderAlt is empty
func (suite *WebhookServiceTestSuite) TestSendMessageReceived_LIDWithEmptySenderAlt() {
	messageText := "LID message with empty alt"

	// Create message with LID but empty SenderAlt (edge case)
	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:         types.JID{User: "0:999999", Server: "lid"},
				Sender:       types.JID{User: "0:888888", Server: "lid"},
				SenderAlt:    types.JID{}, // Empty alternative
				RecipientAlt: types.JID{}, // Empty alternative
				IsFromMe:     false,
				IsGroup:      false,
			},
			ID:        "lid-empty-alt",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: &messageText,
		},
	}

	// Create test HTTP server
	var capturedPayload url.Values
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		r.ParseForm()
		capturedPayload = r.Form
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	suite.session.Cache.SiteURL = server.URL

	// Send webhook
	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)

	// With no SenderAlt and no LID store to consult, the sender identity
	// remains an unresolved LID. isUnresolvedLIDDM must refuse to forward
	// it as a "phone number" - the webhook is skipped (nil error, no HTTP
	// call), not silently sent with LID digits in the phone field.
	assert.NoError(suite.T(), err)
	assert.Nil(suite.T(), capturedPayload)
}

// TestSendMessageReceived_DMSenderNotDigitsOnly tests the defense-in-depth
// guard that stops a malformed (non-digit) DM sender User from ever reaching
// Zender's PHP webhook. whatsmeow's binary decoder does not constrain a JID's
// User field to digits, and PHP downstream keys its DB on this value, so a
// crafted or corrupted User must never be forwarded even though it is a
// s.whatsapp.net (PN) JID, not a LID - this guard is independent of the
// isUnresolvedLIDDM check.
func (suite *WebhookServiceTestSuite) TestSendMessageReceived_DMSenderNotDigitsOnly() {
	messageText := "Malformed sender message"

	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "1234567890", Server: "s.whatsapp.net"},
				Sender:   types.JID{User: "1@g.us234567890", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        "malformed-sender",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: &messageText,
		},
	}

	var capturedPayload url.Values
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		r.ParseForm()
		capturedPayload = r.Form
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	suite.session.Cache.SiteURL = server.URL

	err := suite.webhookService.SendMessageReceived(messageEvent, suite.session)

	// Non-digits-only DM sender must be rejected before the payload is built
	// or sent - no HTTP call, no error surfaced to the caller.
	assert.NoError(suite.T(), err)
	assert.Nil(suite.T(), capturedPayload)
}
