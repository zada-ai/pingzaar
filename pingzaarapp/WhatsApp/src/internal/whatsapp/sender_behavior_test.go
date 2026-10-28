package whatsapp

import (
	"testing"

	"github.com/sirupsen/logrus"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// sender_behavior_test.go locks the existing behavior of MessageSender
// These tests document the current implementation and should pass immediately.
// Any refactoring that breaks these tests indicates a behavior change.

// TestSender_Behavior_CreateTextMessage_HTMLDecode documents that HTML entities are decoded
func TestSender_Behavior_CreateTextMessage_HTMLDecode(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	testCases := []struct {
		name     string
		input    string
		expected string
	}{
		{
			name:     "ampersand entity",
			input:    "Hello &amp; World",
			expected: "Hello & World",
		},
		{
			name:     "less than and greater than",
			input:    "&lt;tag&gt;",
			expected: "<tag>",
		},
		{
			name:     "quote entities",
			input:    "&quot;quoted&quot;",
			expected: "\"quoted\"",
		},
		{
			name:     "apostrophe entity",
			input:    "it&#39;s working",
			expected: "it's working",
		},
		{
			name:     "no entities",
			input:    "plain text",
			expected: "plain text",
		},
		{
			name:     "mixed content",
			input:    "Price: $100 &amp; &lt;discount&gt;",
			expected: "Price: $100 & <discount>",
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			msg := sender.createTextMessage(tc.input)
			require.NotNil(t, msg.Conversation)
			assert.Equal(t, tc.expected, *msg.Conversation)
		})
	}
}

// TestSender_Behavior_DetectAudioType_FromExtension documents audio type detection from URL extension
func TestSender_Behavior_DetectAudioType_FromExtension(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	testCases := []struct {
		name         string
		url          string
		expectedFmt  string
		expectedPTT  bool
		expectedMime string
	}{
		{
			name:         "OGG extension is voice message",
			url:          "https://example.com/audio.ogg",
			expectedFmt:  "ogg",
			expectedPTT:  true,
			expectedMime: "audio/ogg; codecs=opus",
		},
		{
			name:         "OGA extension is voice message",
			url:          "https://example.com/audio.oga",
			expectedFmt:  "ogg",
			expectedPTT:  true,
			expectedMime: "audio/ogg; codecs=opus",
		},
		{
			name:         "OPUS extension is voice message",
			url:          "https://example.com/audio.opus",
			expectedFmt:  "ogg",
			expectedPTT:  true,
			expectedMime: "audio/ogg; codecs=opus",
		},
		{
			name:         "MP3 extension is audio file",
			url:          "https://example.com/audio.mp3",
			expectedFmt:  "mp3",
			expectedPTT:  false,
			expectedMime: "audio/mpeg",
		},
		{
			name:         "case insensitive OGG",
			url:          "https://example.com/audio.OGG",
			expectedFmt:  "ogg",
			expectedPTT:  true,
			expectedMime: "audio/ogg; codecs=opus",
		},
		{
			name:         "case insensitive MP3",
			url:          "https://example.com/audio.MP3",
			expectedFmt:  "mp3",
			expectedPTT:  false,
			expectedMime: "audio/mpeg",
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			result := sender.detectAudioType(tc.url, []byte{})
			assert.Equal(t, tc.expectedFmt, result.Format)
			assert.Equal(t, tc.expectedPTT, result.IsPTT)
			assert.Equal(t, tc.expectedMime, result.MimeType)
		})
	}
}

// TestSender_Behavior_DetectAudioType_FromSignature documents audio type detection from file signature
func TestSender_Behavior_DetectAudioType_FromSignature(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	testCases := []struct {
		name        string
		url         string
		data        []byte
		expectedFmt string
		expectedPTT bool
	}{
		{
			name:        "OGG magic bytes",
			url:         "https://example.com/audio", // no extension
			data:        []byte{'O', 'g', 'g', 'S', 0, 0, 0, 0},
			expectedFmt: "ogg",
			expectedPTT: true,
		},
		{
			name:        "MP3 ID3 tag",
			url:         "https://example.com/audio", // no extension
			data:        []byte{'I', 'D', '3', 0, 0, 0},
			expectedFmt: "mp3",
			expectedPTT: false,
		},
		{
			name:        "MP3 sync bytes",
			url:         "https://example.com/audio", // no extension
			data:        []byte{0xFF, 0xFB, 0x90, 0x00},
			expectedFmt: "mp3",
			expectedPTT: false,
		},
		{
			name:        "unknown format defaults to OGG",
			url:         "https://example.com/audio", // no extension
			data:        []byte{0x00, 0x00, 0x00, 0x00},
			expectedFmt: "ogg",
			expectedPTT: true,
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			result := sender.detectAudioType(tc.url, tc.data)
			assert.Equal(t, tc.expectedFmt, result.Format)
			assert.Equal(t, tc.expectedPTT, result.IsPTT)
		})
	}
}

// TestSender_Behavior_ParseMessageContent_InvalidJSON documents that invalid JSON is treated as plain text
func TestSender_Behavior_ParseMessageContent_InvalidJSON(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	testCases := []struct {
		name     string
		input    string
		expected string
	}{
		{
			name:     "plain text",
			input:    "Hello World",
			expected: "Hello World",
		},
		{
			name:     "broken JSON",
			input:    "{incomplete",
			expected: "{incomplete",
		},
		{
			name:     "empty string",
			input:    "",
			expected: "",
		},
		{
			name:     "plain number",
			input:    "12345",
			expected: "12345",
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			msg, err := sender.parseMessageContent(tc.input, nil)
			require.NoError(t, err, "Invalid JSON should not return error")
			require.NotNil(t, msg.Conversation)
			assert.Equal(t, tc.expected, *msg.Conversation)
		})
	}
}

// TestSender_Behavior_ParseMessageContent_TextJSON documents JSON text message parsing
func TestSender_Behavior_ParseMessageContent_TextJSON(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	testCases := []struct {
		name     string
		input    string
		expected string
	}{
		{
			name:     "simple text",
			input:    `{"text":"Hello World"}`,
			expected: "Hello World",
		},
		{
			name:     "text with HTML entities",
			input:    `{"text":"Hello &amp; World"}`,
			expected: "Hello & World",
		},
		{
			name:     "message field fallback",
			input:    `{"message":"Fallback message"}`,
			expected: "Fallback message",
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			msg, err := sender.parseMessageContent(tc.input, nil)
			require.NoError(t, err)
			require.NotNil(t, msg.Conversation)
			assert.Equal(t, tc.expected, *msg.Conversation)
		})
	}
}

// TestSender_Behavior_ParseRecipientJID documents phone number to JID conversion
func TestSender_Behavior_ParseRecipientJID(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	testCases := []struct {
		name        string
		input       string
		expectedJID string
	}{
		{
			name:        "plain number",
			input:       "1234567890",
			expectedJID: "1234567890@s.whatsapp.net",
		},
		{
			name:        "number with plus prefix",
			input:       "+1234567890",
			expectedJID: "1234567890@s.whatsapp.net",
		},
		{
			name:        "number with dashes",
			input:       "123-456-7890",
			expectedJID: "1234567890@s.whatsapp.net",
		},
		{
			name:        "number with spaces",
			input:       "123 456 7890",
			expectedJID: "1234567890@s.whatsapp.net",
		},
		{
			name:        "number with parentheses",
			input:       "(123) 456-7890",
			expectedJID: "1234567890@s.whatsapp.net",
		},
		{
			name:        "already a JID",
			input:       "1234567890@s.whatsapp.net",
			expectedJID: "1234567890@s.whatsapp.net",
		},
		{
			name:        "group JID preserved",
			input:       "123456789@g.us",
			expectedJID: "123456789@g.us",
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			jid, err := sender.parseRecipientJID(tc.input)
			require.NoError(t, err)
			assert.Equal(t, tc.expectedJID, jid.String())
		})
	}
}

// TestSender_Behavior_AudioType_Struct documents the AudioType struct fields
func TestSender_Behavior_AudioType_Struct(t *testing.T) {
	// OGG voice message
	oggType := AudioType{
		Format:   "ogg",
		MimeType: "audio/ogg; codecs=opus",
		IsPTT:    true,
	}
	assert.Equal(t, "ogg", oggType.Format)
	assert.Equal(t, "audio/ogg; codecs=opus", oggType.MimeType)
	assert.True(t, oggType.IsPTT, "OGG should be PTT (voice message)")

	// MP3 audio file
	mp3Type := AudioType{
		Format:   "mp3",
		MimeType: "audio/mpeg",
		IsPTT:    false,
	}
	assert.Equal(t, "mp3", mp3Type.Format)
	assert.Equal(t, "audio/mpeg", mp3Type.MimeType)
	assert.False(t, mp3Type.IsPTT, "MP3 should not be PTT (audio file)")
}

// TestSender_Behavior_OggDuration_NoPage documents behavior when OGG page is not found
func TestSender_Behavior_OggDuration_NoPage(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)
	sender := NewMessageSender(nil, logger)

	// Invalid data should return 0 duration
	result := sender.getOggDuration([]byte("not an ogg file"))
	assert.Equal(t, uint32(0), result, "Invalid OGG should return 0 duration")

	// Empty data should return 0 duration
	result = sender.getOggDuration([]byte{})
	assert.Equal(t, uint32(0), result, "Empty data should return 0 duration")
}
