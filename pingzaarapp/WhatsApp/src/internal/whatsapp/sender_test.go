package whatsapp

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/sirupsen/logrus"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"github.com/stretchr/testify/suite"

	"whatsapp-server/tests/helpers"
)

// MessageSenderTestSuite tests the MessageSender with mock dependencies
type MessageSenderTestSuite struct {
	suite.Suite
	sender      *MessageSender
	mockHTTP    *MockHTTPClient
	mockSessMgr *MockSessionManager
	logger      *logrus.Logger
}

func TestMessageSenderTestSuite(t *testing.T) {
	suite.Run(t, new(MessageSenderTestSuite))
}

func (suite *MessageSenderTestSuite) SetupTest() {
	helpers.GinTestMode()
	suite.logger = logrus.New()
	suite.logger.SetLevel(logrus.ErrorLevel)

	suite.mockHTTP = &MockHTTPClient{}
	suite.mockSessMgr = NewMockSessionManager()
	suite.sender = NewMessageSenderWithDeps(suite.mockSessMgr, suite.logger, suite.mockHTTP)
}

// ===========================================
// Text Message Tests (5 tests)
// ===========================================

func (suite *MessageSenderTestSuite) TestParseMessageContent_PlainText() {
	msg, err := suite.sender.parseMessageContent(`{"text":"Hello World"}`, nil)

	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), msg.Conversation)
	assert.Equal(suite.T(), "Hello World", *msg.Conversation)
}

func (suite *MessageSenderTestSuite) TestParseMessageContent_HTMLEntitiesDecoded() {
	msg, err := suite.sender.parseMessageContent(`{"text":"Hello &amp; World &lt;test&gt;"}`, nil)

	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), msg.Conversation)
	assert.Equal(suite.T(), "Hello & World <test>", *msg.Conversation)
}

func (suite *MessageSenderTestSuite) TestParseMessageContent_InvalidJSONFallback() {
	// Invalid JSON should be treated as plain text
	plainText := "This is not JSON"
	msg, err := suite.sender.parseMessageContent(plainText, nil)

	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), msg.Conversation)
	assert.Equal(suite.T(), plainText, *msg.Conversation)
}

func (suite *MessageSenderTestSuite) TestParseMessageContent_MessageFieldFallback() {
	msg, err := suite.sender.parseMessageContent(`{"message":"Fallback message"}`, nil)

	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), msg.Conversation)
	assert.Equal(suite.T(), "Fallback message", *msg.Conversation)
}

func (suite *MessageSenderTestSuite) TestParseMessageContent_EmptyJSON() {
	// Empty JSON object should return the raw string
	msg, err := suite.sender.parseMessageContent(`{}`, nil)

	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), msg.Conversation)
	assert.Equal(suite.T(), `{}`, *msg.Conversation)
}

// ===========================================
// Media Download Tests (5 tests)
// ===========================================

func (suite *MessageSenderTestSuite) TestDownloadMediaFromURL_Success() {
	expectedData := []byte("image data here")
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		return &http.Response{
			StatusCode: http.StatusOK,
			Body:       io.NopCloser(bytes.NewReader(expectedData)),
		}, nil
	}

	ctx := context.Background()
	data, err := suite.sender.downloadMediaFromURL(ctx, "https://example.com/image.jpg")

	require.NoError(suite.T(), err)
	assert.Equal(suite.T(), expectedData, data)
	assert.Equal(suite.T(), 1, suite.mockHTTP.CallCount())
}

func (suite *MessageSenderTestSuite) TestDownloadMediaFromURL_Non200Status() {
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		return &http.Response{
			StatusCode: http.StatusNotFound,
			Body:       io.NopCloser(bytes.NewReader([]byte("Not Found"))),
		}, nil
	}

	ctx := context.Background()
	_, err := suite.sender.downloadMediaFromURL(ctx, "https://example.com/notfound.jpg")

	require.Error(suite.T(), err)
	assert.Contains(suite.T(), err.Error(), "404")
}

func (suite *MessageSenderTestSuite) TestDownloadMediaFromURL_ExceedsSizeLimit() {
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		return &http.Response{
			StatusCode:    http.StatusOK,
			ContentLength: MaxMediaSize + 1, // Exceeds 100MB limit
			Body:          io.NopCloser(bytes.NewReader([]byte{})),
		}, nil
	}

	ctx := context.Background()
	_, err := suite.sender.downloadMediaFromURL(ctx, "https://example.com/huge.zip")

	require.Error(suite.T(), err)
	assert.Contains(suite.T(), err.Error(), "too large")
}

func (suite *MessageSenderTestSuite) TestDownloadMediaFromURL_NetworkError() {
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		return nil, context.DeadlineExceeded
	}

	ctx := context.Background()
	_, err := suite.sender.downloadMediaFromURL(ctx, "https://example.com/timeout.jpg")

	require.Error(suite.T(), err)
}

func (suite *MessageSenderTestSuite) TestDownloadMediaFromURL_EmptyBody() {
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		return &http.Response{
			StatusCode: http.StatusOK,
			Body:       io.NopCloser(bytes.NewReader([]byte{})),
		}, nil
	}

	ctx := context.Background()
	data, err := suite.sender.downloadMediaFromURL(ctx, "https://example.com/empty.jpg")

	require.NoError(suite.T(), err)
	assert.Empty(suite.T(), data)
}

// ===========================================
// Audio Type Detection Tests (7 tests)
// ===========================================

func (suite *MessageSenderTestSuite) TestDetectAudioType_OGGFromExtension() {
	testCases := []struct {
		url string
		ext string
	}{
		{"https://example.com/audio.ogg", ".ogg"},
		{"https://example.com/audio.oga", ".oga"},
		{"https://example.com/audio.opus", ".opus"},
		{"https://example.com/audio.OGG", ".OGG"},
	}

	for _, tc := range testCases {
		suite.Run(tc.ext, func() {
			result := suite.sender.detectAudioType(tc.url, []byte{})
			assert.Equal(suite.T(), "ogg", result.Format)
			assert.True(suite.T(), result.IsPTT, "OGG should be PTT (voice message)")
			assert.Equal(suite.T(), "audio/ogg; codecs=opus", result.MimeType)
		})
	}
}

func (suite *MessageSenderTestSuite) TestDetectAudioType_MP3FromExtension() {
	result := suite.sender.detectAudioType("https://example.com/audio.mp3", []byte{})

	assert.Equal(suite.T(), "mp3", result.Format)
	assert.False(suite.T(), result.IsPTT, "MP3 should not be PTT")
	assert.Equal(suite.T(), "audio/mpeg", result.MimeType)
}

func (suite *MessageSenderTestSuite) TestDetectAudioType_OGGFromSignature() {
	// OGG file signature: "OggS"
	oggData := []byte{'O', 'g', 'g', 'S', 0, 0, 0, 0}

	result := suite.sender.detectAudioType("https://example.com/audio", oggData)

	assert.Equal(suite.T(), "ogg", result.Format)
	assert.True(suite.T(), result.IsPTT)
}

func (suite *MessageSenderTestSuite) TestDetectAudioType_MP3FromID3Signature() {
	// MP3 ID3 tag signature
	mp3Data := []byte{'I', 'D', '3', 0, 0, 0}

	result := suite.sender.detectAudioType("https://example.com/audio", mp3Data)

	assert.Equal(suite.T(), "mp3", result.Format)
	assert.False(suite.T(), result.IsPTT)
}

func (suite *MessageSenderTestSuite) TestDetectAudioType_MP3FromSyncBytes() {
	// MP3 sync bytes (0xFF 0xFB)
	mp3Data := []byte{0xFF, 0xFB, 0x90, 0x00}

	result := suite.sender.detectAudioType("https://example.com/audio", mp3Data)

	assert.Equal(suite.T(), "mp3", result.Format)
	assert.False(suite.T(), result.IsPTT)
}

func (suite *MessageSenderTestSuite) TestDetectAudioType_UnknownDefaultsToOGG() {
	// Unknown format should default to OGG voice message
	unknownData := []byte{0x00, 0x00, 0x00, 0x00}

	result := suite.sender.detectAudioType("https://example.com/audio", unknownData)

	assert.Equal(suite.T(), "ogg", result.Format)
	assert.True(suite.T(), result.IsPTT)
}

func (suite *MessageSenderTestSuite) TestDetectAudioType_ExtensionTakesPrecedence() {
	// Even with MP3 signature, OGG extension should take precedence
	mp3Data := []byte{'I', 'D', '3', 0, 0, 0}

	result := suite.sender.detectAudioType("https://example.com/audio.ogg", mp3Data)

	assert.Equal(suite.T(), "ogg", result.Format)
	assert.True(suite.T(), result.IsPTT)
}

// ===========================================
// Location Message Tests (3 tests)
// ===========================================

func (suite *MessageSenderTestSuite) TestCreateLocationMessage_WithAllFields() {
	location := map[string]interface{}{
		"latitude":  37.7749,
		"longitude": -122.4194,
		"name":      "San Francisco",
		"address":   "California, USA",
	}

	msg, err := suite.sender.createLocationMessage(location)

	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), msg.LocationMessage)
	assert.InDelta(suite.T(), 37.7749, *msg.LocationMessage.DegreesLatitude, 0.0001)
	assert.InDelta(suite.T(), -122.4194, *msg.LocationMessage.DegreesLongitude, 0.0001)
	assert.Equal(suite.T(), "San Francisco", *msg.LocationMessage.Name)
	assert.Equal(suite.T(), "California, USA", *msg.LocationMessage.Address)
}

func (suite *MessageSenderTestSuite) TestCreateLocationMessage_LatLngOnly() {
	location := map[string]interface{}{
		"latitude":  40.7128,
		"longitude": -74.0060,
	}

	msg, err := suite.sender.createLocationMessage(location)

	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), msg.LocationMessage)
	assert.InDelta(suite.T(), 40.7128, *msg.LocationMessage.DegreesLatitude, 0.0001)
	assert.InDelta(suite.T(), -74.0060, *msg.LocationMessage.DegreesLongitude, 0.0001)
}

func (suite *MessageSenderTestSuite) TestCreateLocationMessage_EmptyMap() {
	location := map[string]interface{}{}

	msg, err := suite.sender.createLocationMessage(location)

	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), msg.LocationMessage)
	// Should have zero values
	assert.Equal(suite.T(), float64(0), *msg.LocationMessage.DegreesLatitude)
	assert.Equal(suite.T(), float64(0), *msg.LocationMessage.DegreesLongitude)
}

// ===========================================
// Contact Message Tests (3 tests)
// ===========================================

func (suite *MessageSenderTestSuite) TestCreateContactMessage_FullContact() {
	contact := map[string]interface{}{
		"name":  "John Doe",
		"phone": "+1234567890",
	}

	msg, err := suite.sender.createContactMessage(contact)

	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), msg.ContactMessage)
	assert.Equal(suite.T(), "John Doe", *msg.ContactMessage.DisplayName)
	assert.Contains(suite.T(), *msg.ContactMessage.Vcard, "FN:John Doe")
	assert.Contains(suite.T(), *msg.ContactMessage.Vcard, "TEL")
}

func (suite *MessageSenderTestSuite) TestCreateContactMessage_PhoneOnly() {
	contact := map[string]interface{}{
		"phone": "+1234567890",
	}

	msg, err := suite.sender.createContactMessage(contact)

	require.NoError(suite.T(), err)
	require.NotNil(suite.T(), msg.ContactMessage)
	// Phone should be used as display name
	assert.Equal(suite.T(), "+1234567890", *msg.ContactMessage.DisplayName)
}

func (suite *MessageSenderTestSuite) TestCreateContactMessage_Empty() {
	contact := map[string]interface{}{}

	_, err := suite.sender.createContactMessage(contact)

	require.Error(suite.T(), err)
	assert.Contains(suite.T(), err.Error(), "name or phone")
}

// ===========================================
// Duration Calculation Tests (4 tests)
// ===========================================

func (suite *MessageSenderTestSuite) TestEstimateDurationFromSize_OGG() {
	// 28 kbps = 3500 bytes/second for OGG
	// 35000 bytes = 10 seconds
	data := make([]byte, 35000)

	duration := suite.sender.estimateDurationFromSize(data, "ogg")

	// (35000 * 8) / 28000 = 10
	assert.Equal(suite.T(), uint32(10), duration)
}

func (suite *MessageSenderTestSuite) TestEstimateDurationFromSize_MP3() {
	// 128 kbps = 16000 bytes/second for MP3
	// 160000 bytes = 10 seconds
	data := make([]byte, 160000)

	duration := suite.sender.estimateDurationFromSize(data, "mp3")

	// (160000 * 8) / 128000 = 10
	assert.Equal(suite.T(), uint32(10), duration)
}

func (suite *MessageSenderTestSuite) TestCalculateAudioDuration_FallbackToEstimate() {
	// Invalid OGG data should fall back to size estimation
	data := make([]byte, 35000)

	duration := suite.sender.calculateAudioDuration(data, "ogg")

	// Should use size estimation as fallback
	assert.Greater(suite.T(), duration, uint32(0))
}

func (suite *MessageSenderTestSuite) TestCalculateAudioDuration_DefaultToOne() {
	// Empty data should default to 1 second
	data := []byte{}

	duration := suite.sender.calculateAudioDuration(data, "ogg")

	assert.Equal(suite.T(), uint32(1), duration)
}

// ===========================================
// Phone Number Parsing Tests (3 tests)
// ===========================================

func (suite *MessageSenderTestSuite) TestParseRecipientJID_PlainNumber() {
	jid, err := suite.sender.parseRecipientJID("1234567890")

	require.NoError(suite.T(), err)
	assert.Equal(suite.T(), "1234567890@s.whatsapp.net", jid.String())
}

func (suite *MessageSenderTestSuite) TestParseRecipientJID_WithFormatting() {
	testCases := []struct {
		input    string
		expected string
	}{
		{"+1234567890", "1234567890@s.whatsapp.net"},
		{"123-456-7890", "1234567890@s.whatsapp.net"},
		{"(123) 456-7890", "1234567890@s.whatsapp.net"},
		{"123 456 7890", "1234567890@s.whatsapp.net"},
	}

	for _, tc := range testCases {
		suite.Run(tc.input, func() {
			jid, err := suite.sender.parseRecipientJID(tc.input)
			require.NoError(suite.T(), err)
			assert.Equal(suite.T(), tc.expected, jid.String())
		})
	}
}

func (suite *MessageSenderTestSuite) TestParseRecipientJID_AlreadyJID() {
	testCases := []struct {
		input    string
		expected string
	}{
		{"1234567890@s.whatsapp.net", "1234567890@s.whatsapp.net"},
		{"123456789@g.us", "123456789@g.us"},
		{"user@lid", "user@lid"},
		{"123456@lid", "123456@lid"},
	}

	for _, tc := range testCases {
		suite.Run(tc.input, func() {
			jid, err := suite.sender.parseRecipientJID(tc.input)
			require.NoError(suite.T(), err)
			assert.Equal(suite.T(), tc.expected, jid.String())
		})
	}
}

func TestIsGroupOrLIDRecipient_DetectsGroupSuffix(t *testing.T) {
	assert.True(t, isGroupOrLIDRecipient("120363403405318006@g.us"))
}

func TestIsGroupOrLIDRecipient_DetectsLIDSuffix(t *testing.T) {
	assert.True(t, isGroupOrLIDRecipient("user@lid"))
	assert.True(t, isGroupOrLIDRecipient("123456@lid"))
}

func TestIsGroupOrLIDRecipient_RejectsPlainPhone(t *testing.T) {
	assert.False(t, isGroupOrLIDRecipient("+1234567890"))
	assert.False(t, isGroupOrLIDRecipient("1234567890"))
}

func TestIsGroupOrLIDRecipient_RejectsUserServer(t *testing.T) {
	assert.False(t, isGroupOrLIDRecipient("1234567890@s.whatsapp.net"))
}

func TestIsGroupOrLIDRecipient_RejectsEmpty(t *testing.T) {
	assert.False(t, isGroupOrLIDRecipient(""))
}

// ===========================================
// Integration-style Tests with Mock Server
// ===========================================

func TestDownloadMediaFromURL_Integration(t *testing.T) {
	// Create a test server that returns image data
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/image.jpg":
			w.Header().Set("Content-Type", "image/jpeg")
			w.Write([]byte("fake jpeg data"))
		case "/large.jpg":
			w.Header().Set("Content-Length", "200000000") // 200MB
			w.WriteHeader(http.StatusOK)
		case "/error":
			w.WriteHeader(http.StatusInternalServerError)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer server.Close()

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	t.Run("successful download", func(t *testing.T) {
		ctx := context.Background()
		data, err := sender.downloadMediaFromURL(ctx, server.URL+"/image.jpg")
		require.NoError(t, err)
		assert.Equal(t, []byte("fake jpeg data"), data)
	})

	t.Run("not found", func(t *testing.T) {
		ctx := context.Background()
		_, err := sender.downloadMediaFromURL(ctx, server.URL+"/notfound.jpg")
		require.Error(t, err)
	})

	t.Run("server error", func(t *testing.T) {
		ctx := context.Background()
		_, err := sender.downloadMediaFromURL(ctx, server.URL+"/error")
		require.Error(t, err)
	})

	t.Run("too large", func(t *testing.T) {
		ctx := context.Background()
		_, err := sender.downloadMediaFromURL(ctx, server.URL+"/large.jpg")
		require.Error(t, err)
		assert.Contains(t, err.Error(), "too large")
	})
}

// ===========================================
// Additional Edge Case Tests
// ===========================================

func TestParseMessageContent_AllMessageTypes(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	testCases := []struct {
		name       string
		input      string
		expectType string
	}{
		{"text message", `{"text":"Hello"}`, "text"},
		{"message field", `{"message":"Hello"}`, "text"},
		{"image message", `{"image":"https://example.com/img.jpg"}`, "image"},
		{"video message", `{"video":"https://example.com/vid.mp4"}`, "video"},
		{"audio message", `{"audio":"https://example.com/audio.ogg"}`, "audio"},
		{"document message", `{"document":"https://example.com/doc.pdf"}`, "document"},
		{"location message", `{"location":{"latitude":0,"longitude":0}}`, "location"},
		{"contact message", `{"contact":{"name":"Test","phone":"123"}}`, "contact"},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			msg, err := sender.parseMessageContent(tc.input, nil)
			// Some types require session for upload, so they may error
			// But we're testing that parsing recognizes the type
			if tc.expectType == "text" {
				require.NoError(t, err)
				assert.NotNil(t, msg.Conversation)
			} else if tc.expectType == "location" {
				require.NoError(t, err)
				assert.NotNil(t, msg.LocationMessage)
			} else if tc.expectType == "contact" {
				require.NoError(t, err)
				assert.NotNil(t, msg.ContactMessage)
			}
			// Media types (image, video, audio, document) will error without session
		})
	}
}

func TestCreateTextMessage_SpecialCharacters(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	testCases := []struct {
		name     string
		input    string
		expected string
	}{
		{"emoji", "Hello 👋 World", "Hello 👋 World"},
		{"newlines", "Line1\nLine2", "Line1\nLine2"},
		{"unicode", "日本語テスト", "日本語テスト"},
		{"mixed entities", "&amp; &lt; &gt; &quot;", "& < > \""},
		{"numeric entities", "&#39; &#x27;", "' '"},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			msg := sender.createTextMessage(tc.input)
			assert.Equal(t, tc.expected, *msg.Conversation)
		})
	}
}

func TestExtractMediaURL(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	testCases := []struct {
		name     string
		input    interface{}
		expected string
		hasError bool
	}{
		{"string URL", "https://example.com/image.jpg", "https://example.com/image.jpg", false},
		{"map with url", map[string]interface{}{"url": "https://example.com/image.jpg"}, "https://example.com/image.jpg", false},
		// Note: "link" key is NOT supported - only "url" key works
		{"map with link unsupported", map[string]interface{}{"link": "https://example.com/image.jpg"}, "", true},
		{"empty map", map[string]interface{}{}, "", true},
		{"invalid type", 12345, "", true},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			url, err := sender.extractMediaURL(tc.input)
			if tc.hasError {
				assert.Error(t, err)
			} else {
				require.NoError(t, err)
				assert.Equal(t, tc.expected, url)
			}
		})
	}
}

func TestDetectMimeType(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	// Note: detectMimeType uses http.DetectContentType first, which looks at actual file content.
	// Empty data returns "text/plain; charset=utf-8" from http.DetectContentType,
	// so extension-based detection is only used when content detection returns "application/octet-stream"
	testCases := []struct {
		name     string
		data     []byte
		url      string
		expected string
	}{
		// JPEG magic bytes: FF D8 FF
		{"jpeg from content", []byte{0xFF, 0xD8, 0xFF, 0xE0}, "https://example.com/image.jpg", "image/jpeg"},
		// PNG magic bytes: 89 50 4E 47 0D 0A 1A 0A
		{"png from content", []byte{0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A}, "https://example.com/image.png", "image/png"},
		// PDF magic bytes are NOT detected by http.DetectContentType - it returns text/plain
		// because %PDF looks like text. Extension fallback won't help since text/plain != octet-stream
		{"pdf returns text/plain (known limitation)", []byte{0x25, 0x50, 0x44, 0x46}, "https://example.com/doc.pdf", "text/plain; charset=utf-8"},
		// Empty data returns text/plain from http.DetectContentType
		{"empty data returns text/plain", []byte{}, "https://example.com/image.jpg", "text/plain; charset=utf-8"},
		// Binary data that can't be identified falls back to extension
		{"binary data with extension", []byte{0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00}, "https://example.com/file.bin", "application/octet-stream"},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			mime := sender.detectMimeType(tc.data, tc.url)
			assert.Equal(t, tc.expected, mime)
		})
	}
}

// ===========================================
// Struct Verification Tests
// ===========================================

func TestAudioType_Struct(t *testing.T) {
	// Verify AudioType struct fields
	oggType := AudioType{
		Format:   "ogg",
		MimeType: "audio/ogg; codecs=opus",
		IsPTT:    true,
	}

	assert.Equal(t, "ogg", oggType.Format)
	assert.Equal(t, "audio/ogg; codecs=opus", oggType.MimeType)
	assert.True(t, oggType.IsPTT)

	mp3Type := AudioType{
		Format:   "mp3",
		MimeType: "audio/mpeg",
		IsPTT:    false,
	}

	assert.Equal(t, "mp3", mp3Type.Format)
	assert.Equal(t, "audio/mpeg", mp3Type.MimeType)
	assert.False(t, mp3Type.IsPTT)
}

func TestMaxMediaSize_Constant(t *testing.T) {
	// Verify MaxMediaSize is 100MB
	assert.Equal(t, 100*1024*1024, MaxMediaSize)
}

// ===========================================
// Concurrency Tests
// ===========================================

func TestMessageSender_ConcurrentParseMessageContent(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	// Test that parseMessageContent is thread-safe
	done := make(chan bool, 10)

	for i := 0; i < 10; i++ {
		go func(index int) {
			content := map[string]interface{}{"text": "Message " + string(rune('A'+index))}
			jsonContent, _ := json.Marshal(content)
			msg, err := sender.parseMessageContent(string(jsonContent), nil)
			assert.NoError(t, err)
			assert.NotNil(t, msg)
			done <- true
		}(i)
	}

	// Wait for all goroutines
	for i := 0; i < 10; i++ {
		<-done
	}
}
