package whatsapp

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/sirupsen/logrus"
	logrustest "github.com/sirupsen/logrus/hooks/test"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"github.com/stretchr/testify/suite"
	"go.mau.fi/whatsmeow/appstate"
	"go.mau.fi/whatsmeow/binary/proto"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"
	_ "modernc.org/sqlite" // SQLite driver

	"whatsapp-server/internal/config"
	goTypes "whatsapp-server/pkg/types"
	"whatsapp-server/tests/helpers"
)

type EventHandlerTestSuite struct {
	suite.Suite
	tempDir      string
	config       *config.Config
	sessionMgr   *SessionManager
	eventHandler *EventHandler
	session      *goTypes.Session
}

func (suite *EventHandlerTestSuite) SetupTest() {
	helpers.GinTestMode()
	suite.tempDir = suite.T().TempDir()

	suite.config = &config.Config{}
	suite.config.Server.Secret = "test-secret"
	suite.config.Server.AutoRead = true
	suite.config.Server.Host = "127.0.0.1"
	suite.config.Server.Port = 8899

	logger := helpers.TestLogger()
	var err error
	suite.sessionMgr, err = NewSessionManager(suite.config, logger)
	require.NoError(suite.T(), err)

	suite.eventHandler = NewEventHandler(suite.sessionMgr, logger)

	// Create a test session
	cache := helpers.TestSessionCache()
	suite.session, err = suite.sessionMgr.CreateSession(suite.T().Context(), cache)
	require.NoError(suite.T(), err)
}

func (suite *EventHandlerTestSuite) TestNewEventHandler() {
	logger := helpers.TestLogger()
	sm, err := NewSessionManager(suite.config, logger)
	require.NoError(suite.T(), err)

	eh := NewEventHandler(sm, logger)

	assert.NotNil(suite.T(), eh)
	assert.Equal(suite.T(), sm, eh.sessionMgr)
	assert.Equal(suite.T(), logger, eh.logger)
}

func (suite *EventHandlerTestSuite) TestHandleMessage() {
	// Create a mock message event
	messageID := "test-message-id"
	senderJID := types.JID{User: "123456789", Server: "s.whatsapp.net"}
	chatJID := types.JID{User: "987654321", Server: "s.whatsapp.net"}
	messageText := "Hello, world!"

	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     chatJID,
				Sender:   senderJID,
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        messageID,
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: &messageText,
		},
	}

	// Test handling message with session context
	oldActivity := suite.session.LastActivity

	suite.eventHandler.HandleWithSession(messageEvent, suite.session.ID)

	// Should update last activity
	assert.True(suite.T(), suite.session.LastActivity.After(oldActivity))

	// Test handling message without session (legacy method)
	suite.eventHandler.Handle(messageEvent)
	// Should not panic
}

func (suite *EventHandlerTestSuite) TestHandleMessage_FromMe() {
	// Create a message from self (should be ignored)
	messageID := "self-message-id"
	senderJID := types.JID{User: "123456789", Server: "s.whatsapp.net"}
	chatJID := types.JID{User: "987654321", Server: "s.whatsapp.net"}
	messageText := "My own message"

	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     chatJID,
				Sender:   senderJID,
				IsFromMe: true, // Message from self
				IsGroup:  false,
			},
			ID:        messageID,
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: &messageText,
		},
	}

	suite.eventHandler.HandleWithSession(messageEvent, suite.session.ID)
	// Should not process own messages, but shouldn't panic
}

func (suite *EventHandlerTestSuite) TestHandleMessage_GroupMessage() {
	messageID := "group-message-id"
	senderJID := types.JID{User: "123456789", Server: "s.whatsapp.net"}
	groupJID := types.JID{User: "987654321", Server: "g.us"}
	messageText := "Group message"

	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     groupJID,
				Sender:   senderJID,
				IsFromMe: false,
				IsGroup:  true,
			},
			ID:        messageID,
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: &messageText,
		},
	}

	suite.eventHandler.HandleWithSession(messageEvent, suite.session.ID)
	// Should handle group messages properly
}

func (suite *EventHandlerTestSuite) TestHandleMessage_MediaTypes() {
	testCases := []struct {
		name    string
		message *proto.Message
		msgType goTypes.MessageType
	}{
		{
			name: "Image message",
			message: &proto.Message{
				ImageMessage: &proto.ImageMessage{
					Caption: func() *string { s := "Image caption"; return &s }(),
				},
			},
			msgType: goTypes.MessageTypeImage,
		},
		{
			name: "Video message",
			message: &proto.Message{
				VideoMessage: &proto.VideoMessage{
					Caption: func() *string { s := "Video caption"; return &s }(),
				},
			},
			msgType: goTypes.MessageTypeVideo,
		},
		{
			name: "Audio message",
			message: &proto.Message{
				AudioMessage: &proto.AudioMessage{},
			},
			msgType: goTypes.MessageTypeAudio,
		},
		{
			name: "Document message",
			message: &proto.Message{
				DocumentMessage: &proto.DocumentMessage{
					Caption: func() *string { s := "Document caption"; return &s }(),
				},
			},
			msgType: goTypes.MessageTypeDocument,
		},
		{
			name: "Location message",
			message: &proto.Message{
				LocationMessage: &proto.LocationMessage{
					DegreesLatitude:  func() *float64 { f := 40.7128; return &f }(),
					DegreesLongitude: func() *float64 { f := -74.0060; return &f }(),
				},
			},
			msgType: goTypes.MessageTypeLocation,
		},
		{
			name: "Extended text message",
			message: &proto.Message{
				ExtendedTextMessage: &proto.ExtendedTextMessage{
					Text: func() *string { s := "Extended text"; return &s }(),
				},
			},
			msgType: goTypes.MessageTypeText,
		},
	}

	for _, tc := range testCases {
		suite.T().Run(tc.name, func(t *testing.T) {
			messageID := "media-message-id"
			senderJID := types.JID{User: "123456789", Server: "s.whatsapp.net"}
			chatJID := types.JID{User: "987654321", Server: "s.whatsapp.net"}

			messageEvent := &events.Message{
				Info: types.MessageInfo{
					MessageSource: types.MessageSource{
						Chat:     chatJID,
						Sender:   senderJID,
						IsFromMe: false,
						IsGroup:  false,
					},
					ID:        messageID,
					Timestamp: time.Now(),
				},
				Message: tc.message,
			}

			// Test message type detection
			detectedType := suite.eventHandler.getMessageType(tc.message)
			assert.Equal(t, tc.msgType, detectedType)

			// Test content extraction (some message types may have empty content)
			content := suite.eventHandler.extractMessageContent(tc.message)
			if tc.msgType == goTypes.MessageTypeAudio {
				// Audio messages don't have captions, so content may be empty
				assert.NotNil(t, content)
			} else {
				assert.NotEmpty(t, content)
			}

			// Test handling
			suite.eventHandler.HandleWithSession(messageEvent, suite.session.ID)
		})
	}
}

func (suite *EventHandlerTestSuite) TestHandleConnected() {
	connectedEvent := &events.Connected{}

	// Test with session context
	suite.session.Status = goTypes.StatusDisconnected
	suite.eventHandler.HandleWithSession(connectedEvent, suite.session.ID)
	assert.Equal(suite.T(), goTypes.StatusConnected, suite.session.Status)

	// Test without session context
	suite.eventHandler.Handle(connectedEvent)
	// Should not panic
}

func (suite *EventHandlerTestSuite) TestHandleDisconnected() {
	disconnectedEvent := &events.Disconnected{}

	// Test with session context
	suite.session.Status = goTypes.StatusConnected
	suite.eventHandler.HandleWithSession(disconnectedEvent, suite.session.ID)
	assert.Equal(suite.T(), goTypes.StatusDisconnected, suite.session.Status)

	// Test without session context
	suite.eventHandler.Handle(disconnectedEvent)
	// Should not panic
}

func (suite *EventHandlerTestSuite) TestHandleLoggedOut() {
	loggedOutEvent := &events.LoggedOut{
		Reason: events.ConnectFailureLoggedOut,
	}

	// Test with session context
	suite.session.Status = goTypes.StatusConnected
	suite.eventHandler.HandleWithSession(loggedOutEvent, suite.session.ID)
	assert.Equal(suite.T(), goTypes.StatusLoggedOut, suite.session.Status)

	// Test without session context
	suite.eventHandler.Handle(loggedOutEvent)
	// Should not panic
}

func (suite *EventHandlerTestSuite) TestHandlePairSuccess() {
	pairSuccessEvent := &events.PairSuccess{}

	// Test with session context
	suite.session.Status = goTypes.StatusDisconnected
	suite.eventHandler.HandleWithSession(pairSuccessEvent, suite.session.ID)
	assert.Equal(suite.T(), goTypes.StatusConnected, suite.session.Status)

	// Test without session context
	suite.eventHandler.Handle(pairSuccessEvent)
	// Should not panic
}

func (suite *EventHandlerTestSuite) TestHandleReceipt() {
	receiptEvent := &events.Receipt{
		MessageSource: types.MessageSource{
			Chat: types.JID{User: "123456789", Server: "s.whatsapp.net"},
		},
		Timestamp: time.Now(),
		Type:      events.ReceiptTypeRead,
	}

	suite.eventHandler.HandleWithSession(receiptEvent, suite.session.ID)
	suite.eventHandler.Handle(receiptEvent)
	// Should handle receipts without errors
}

func (suite *EventHandlerTestSuite) TestHandlePresence() {
	presenceEvent := &events.Presence{
		From:        types.JID{User: "123456789", Server: "s.whatsapp.net"},
		Unavailable: false,
		LastSeen:    time.Now().Add(-time.Minute),
	}

	suite.eventHandler.HandleWithSession(presenceEvent, suite.session.ID)
	suite.eventHandler.Handle(presenceEvent)
	// Should handle presence updates without errors
}

func (suite *EventHandlerTestSuite) TestHandleUnknownEvent() {
	// Test with unknown event type
	unknownEvent := struct {
		SomeField string
	}{
		SomeField: "unknown",
	}

	// Should handle unknown events gracefully
	suite.eventHandler.HandleWithSession(unknownEvent, suite.session.ID)
	suite.eventHandler.Handle(unknownEvent)
}

func (suite *EventHandlerTestSuite) TestHandleWithSession_UnknownSession() {
	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "987654321", Server: "s.whatsapp.net"},
				Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        "test-id",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "test message"; return &s }(),
		},
	}

	// Should handle gracefully when session doesn't exist
	suite.eventHandler.HandleWithSession(messageEvent, "nonexistent-session")
	// Should not panic
}

func (suite *EventHandlerTestSuite) TestGetMessageType_EdgeCases() {
	testCases := []struct {
		name     string
		message  *proto.Message
		expected goTypes.MessageType
	}{
		{
			name:     "Empty message",
			message:  &proto.Message{},
			expected: goTypes.MessageTypeUnknown,
		},
		{
			name: "Multiple message types (conversation takes precedence)",
			message: &proto.Message{
				Conversation: func() *string { s := "text"; return &s }(),
				ImageMessage: &proto.ImageMessage{},
			},
			expected: goTypes.MessageTypeText,
		},
	}

	for _, tc := range testCases {
		suite.T().Run(tc.name, func(t *testing.T) {
			msgType := suite.eventHandler.getMessageType(tc.message)
			assert.Equal(t, tc.expected, msgType)
		})
	}
}

func (suite *EventHandlerTestSuite) TestExtractMessageContent_EdgeCases() {
	testCases := []struct {
		name     string
		message  *proto.Message
		expected string
	}{
		{
			name:     "Empty message",
			message:  &proto.Message{},
			expected: "",
		},
		{
			name: "Location with coordinates",
			message: &proto.Message{
				LocationMessage: &proto.LocationMessage{
					DegreesLatitude:  func() *float64 { f := 40.7128; return &f }(),
					DegreesLongitude: func() *float64 { f := -74.0060; return &f }(),
				},
			},
			expected: "Location: 40.712800, -74.006000",
		},
		{
			name: "Image with caption",
			message: &proto.Message{
				ImageMessage: &proto.ImageMessage{
					Caption: func() *string { s := "Beautiful sunset"; return &s }(),
				},
			},
			expected: "Beautiful sunset",
		},
	}

	for _, tc := range testCases {
		suite.T().Run(tc.name, func(t *testing.T) {
			content := suite.eventHandler.extractMessageContent(tc.message)
			assert.Equal(t, tc.expected, content)
		})
	}
}

func (suite *EventHandlerTestSuite) TestAutoReadFunctionality() {
	// Test with auto-read enabled
	suite.config.Server.AutoRead = true

	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "987654321", Server: "s.whatsapp.net"},
				Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        "auto-read-test",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "Test auto-read message"; return &s }(),
		},
	}

	// Should attempt to mark as read (though will fail in test without real client)
	suite.eventHandler.HandleWithSession(messageEvent, suite.session.ID)

	// Test with auto-read disabled
	suite.config.Server.AutoRead = false
	suite.eventHandler.HandleWithSession(messageEvent, suite.session.ID)
}

func (suite *EventHandlerTestSuite) TestConcurrentEventHandling() {
	// Test handling multiple events concurrently
	done := make(chan bool, 10)

	eventsList := []interface{}{
		&events.Connected{},
		&events.Disconnected{},
		&events.Message{
			Info: types.MessageInfo{
				MessageSource: types.MessageSource{
					Chat:     types.JID{User: "987654321", Server: "s.whatsapp.net"},
					Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
					IsFromMe: false,
					IsGroup:  false,
				},
				ID:        "concurrent-test",
				Timestamp: time.Now(),
			},
			Message: &proto.Message{
				Conversation: func() *string { s := "Concurrent message"; return &s }(),
			},
		},
		&events.Receipt{
			MessageSource: types.MessageSource{
				Chat: types.JID{User: "123456789", Server: "s.whatsapp.net"},
			},
			Timestamp: time.Now(),
			Type:      events.ReceiptTypeRead,
		},
		&events.Presence{
			From:        types.JID{User: "123456789", Server: "s.whatsapp.net"},
			Unavailable: false,
			LastSeen:    time.Now(),
		},
	}

	// Handle events concurrently
	for i, event := range eventsList {
		go func(e interface{}, index int) {
			defer func() { done <- true }()
			suite.eventHandler.HandleWithSession(e, suite.session.ID)
			suite.eventHandler.Handle(e)
		}(event, i)
	}

	// Wait for all goroutines to complete
	for i := 0; i < len(eventsList); i++ {
		<-done
	}
}

func TestEventHandlerTestSuite(t *testing.T) {
	suite.Run(t, new(EventHandlerTestSuite))
}

// Additional unit tests for specific edge cases
func TestEventHandler_EdgeCases(t *testing.T) {
	helpers.GinTestMode()

	config := &config.Config{}
	config.Server.Secret = "test-secret"
	config.Server.AutoRead = false
	config.Server.Host = "127.0.0.1"
	config.Server.Port = 8899

	logger := helpers.TestLogger()
	sm, err := NewSessionManager(config, logger)
	require.NoError(t, err)

	eh := NewEventHandler(sm, logger)

	t.Run("Nil message handling", func(t *testing.T) {
		assert.NotPanics(t, func() {
			eh.getMessageType(nil)
			eh.extractMessageContent(nil)
		})
	})

	t.Run("Event with nil session", func(t *testing.T) {
		messageEvent := &events.Message{
			Info: types.MessageInfo{
				MessageSource: types.MessageSource{
					Chat:     types.JID{User: "987654321", Server: "s.whatsapp.net"},
					Sender:   types.JID{User: "123456789", Server: "s.whatsapp.net"},
					IsFromMe: false,
					IsGroup:  false,
				},
				ID:        "nil-session-test",
				Timestamp: time.Now(),
			},
			Message: &proto.Message{
				Conversation: func() *string { s := "Test message"; return &s }(),
			},
		}

		// Should not panic with nil session context
		assert.NotPanics(t, func() {
			eh.Handle(messageEvent)
		})
	})

	t.Run("Multiple message type precedence", func(t *testing.T) {
		// Conversation should take precedence over other types
		msg := &proto.Message{
			Conversation:    func() *string { s := "text message"; return &s }(),
			ImageMessage:    &proto.ImageMessage{Caption: func() *string { s := "image"; return &s }()},
			DocumentMessage: &proto.DocumentMessage{Caption: func() *string { s := "doc"; return &s }()},
		}

		msgType := eh.getMessageType(msg)
		assert.Equal(t, goTypes.MessageTypeText, msgType)

		content := eh.extractMessageContent(msg)
		assert.Equal(t, "text message", content)
	})
}

// fakeLIDResolver is a test double for the whatsmeow LID map store.
type fakeLIDResolver struct {
	pnByLID map[string]types.JID
	err     error
}

func (f *fakeLIDResolver) GetPNForLID(ctx context.Context, lid types.JID) (types.JID, error) {
	if f.err != nil {
		return types.JID{}, f.err
	}
	if pn, ok := f.pnByLID[lid.String()]; ok {
		return pn, nil
	}
	return types.JID{}, nil
}

// TestExtractPhoneNumber tests the LID phone number extraction helper
func TestExtractPhoneNumber(t *testing.T) {
	t.Run("Traditional phone number addressing (PN mode)", func(t *testing.T) {
		source := types.MessageSource{
			Sender: types.JID{User: "1234567890", Server: "s.whatsapp.net"},
		}

		result := extractPhoneNumber(context.Background(), source, nil)
		assert.Equal(t, "1234567890", result.User)
		assert.Equal(t, "s.whatsapp.net", result.Server)
	})

	t.Run("LID addressing mode", func(t *testing.T) {
		source := types.MessageSource{
			Sender:    types.JID{User: "0:123456", Server: "lid"},
			SenderAlt: types.JID{User: "1234567890", Server: "s.whatsapp.net"},
		}

		result := extractPhoneNumber(context.Background(), source, nil)
		// Should return phone number from SenderAlt
		assert.Equal(t, "1234567890", result.User)
		assert.Equal(t, "s.whatsapp.net", result.Server)
	})

	t.Run("LID addressing with empty SenderAlt (fallback)", func(t *testing.T) {
		source := types.MessageSource{
			Sender:    types.JID{User: "0:123456", Server: "lid"},
			SenderAlt: types.JID{}, // Empty alternative
		}

		result := extractPhoneNumber(context.Background(), source, nil)
		// Should fall back to Sender (LID)
		assert.Equal(t, "0:123456", result.User)
		assert.Equal(t, "lid", result.Server)
	})

	t.Run("Group message with LID participant", func(t *testing.T) {
		source := types.MessageSource{
			Sender:    types.JID{User: "0:789012", Server: "lid"},
			SenderAlt: types.JID{User: "9876543210", Server: "s.whatsapp.net"},
			IsGroup:   true,
		}

		result := extractPhoneNumber(context.Background(), source, nil)
		// Should extract phone from SenderAlt even in groups
		assert.Equal(t, "9876543210", result.User)
		assert.Equal(t, "s.whatsapp.net", result.Server)
	})

	t.Run("LID with empty SenderAlt resolves via store fallback", func(t *testing.T) {
		source := types.MessageSource{
			Sender:    types.JID{User: "98765", Server: "lid"},
			SenderAlt: types.JID{},
		}
		resolver := &fakeLIDResolver{pnByLID: map[string]types.JID{
			"98765@lid": {User: "1234567890", Server: "s.whatsapp.net"},
		}}

		result := extractPhoneNumber(context.Background(), source, resolver)
		assert.Equal(t, "1234567890", result.User)
		assert.Equal(t, "s.whatsapp.net", result.Server)
	})

	t.Run("LID unknown to store falls back to LID unchanged", func(t *testing.T) {
		source := types.MessageSource{
			Sender:    types.JID{User: "98765", Server: "lid"},
			SenderAlt: types.JID{},
		}
		resolver := &fakeLIDResolver{pnByLID: map[string]types.JID{}}

		result := extractPhoneNumber(context.Background(), source, resolver)
		assert.Equal(t, "lid", result.Server)
		assert.Equal(t, "98765", result.User)
	})

	t.Run("LID store error falls back to LID unchanged", func(t *testing.T) {
		source := types.MessageSource{
			Sender:    types.JID{User: "98765", Server: "lid"},
			SenderAlt: types.JID{},
		}
		resolver := &fakeLIDResolver{err: assert.AnError}

		result := extractPhoneNumber(context.Background(), source, resolver)
		assert.Equal(t, "lid", result.Server)
		assert.Equal(t, "98765", result.User)
	})

	t.Run("Non-LID sender ignores stray SenderAlt", func(t *testing.T) {
		source := types.MessageSource{
			Sender:    types.JID{User: "1234567890", Server: "s.whatsapp.net"},
			SenderAlt: types.JID{User: "9999999999", Server: "s.whatsapp.net"},
		}

		result := extractPhoneNumber(context.Background(), source, nil)
		assert.Equal(t, "1234567890", result.User)
		assert.Equal(t, "s.whatsapp.net", result.Server)
	})
}

// TestExtractChatJID tests the LID chat identifier extraction helper
func TestExtractChatJID(t *testing.T) {
	t.Run("Traditional DM addressing", func(t *testing.T) {
		source := types.MessageSource{
			Chat:    types.JID{User: "1234567890", Server: "s.whatsapp.net"},
			IsGroup: false,
		}

		result := extractChatJID(context.Background(), source, nil)
		assert.Equal(t, "1234567890", result.User)
		assert.Equal(t, "s.whatsapp.net", result.Server)
	})

	t.Run("LID DM addressing", func(t *testing.T) {
		source := types.MessageSource{
			Chat:         types.JID{User: "0:123456", Server: "lid"},
			RecipientAlt: types.JID{User: "1234567890", Server: "s.whatsapp.net"},
			IsGroup:      false,
		}

		result := extractChatJID(context.Background(), source, nil)
		// Should return phone number from RecipientAlt
		assert.Equal(t, "1234567890", result.User)
		assert.Equal(t, "s.whatsapp.net", result.Server)
	})

	t.Run("LID DM with empty RecipientAlt (fallback)", func(t *testing.T) {
		source := types.MessageSource{
			Chat:         types.JID{User: "0:123456", Server: "lid"},
			RecipientAlt: types.JID{}, // Empty alternative
			IsGroup:      false,
		}

		result := extractChatJID(context.Background(), source, nil)
		// Should fall back to Chat (LID)
		assert.Equal(t, "0:123456", result.User)
		assert.Equal(t, "lid", result.Server)
	})

	t.Run("Group chat (not affected by LID)", func(t *testing.T) {
		source := types.MessageSource{
			Chat:    types.JID{User: "120363123456789", Server: "g.us"},
			IsGroup: true,
		}

		result := extractChatJID(context.Background(), source, nil)
		// Groups should always return Chat directly
		assert.Equal(t, "120363123456789", result.User)
		assert.Equal(t, "g.us", result.Server)
	})

	t.Run("Group chat with LID addressing (still returns group JID)", func(t *testing.T) {
		source := types.MessageSource{
			Chat:         types.JID{User: "120363987654321", Server: "g.us"},
			RecipientAlt: types.JID{User: "1234567890", Server: "s.whatsapp.net"},
			IsGroup:      true,
		}

		result := extractChatJID(context.Background(), source, nil)
		// Groups ignore RecipientAlt
		assert.Equal(t, "120363987654321", result.User)
		assert.Equal(t, "g.us", result.Server)
	})

	t.Run("LID DM with empty RecipientAlt resolves via store fallback", func(t *testing.T) {
		source := types.MessageSource{
			Chat:         types.JID{User: "98765", Server: "lid"},
			RecipientAlt: types.JID{},
			IsGroup:      false,
		}
		resolver := &fakeLIDResolver{pnByLID: map[string]types.JID{
			"98765@lid": {User: "1234567890", Server: "s.whatsapp.net"},
		}}

		result := extractChatJID(context.Background(), source, resolver)
		assert.Equal(t, "1234567890", result.User)
		assert.Equal(t, "s.whatsapp.net", result.Server)
	})

	t.Run("Non-LID chat ignores stray RecipientAlt", func(t *testing.T) {
		source := types.MessageSource{
			Chat:         types.JID{User: "1234567890", Server: "s.whatsapp.net"},
			RecipientAlt: types.JID{User: "9999999999", Server: "s.whatsapp.net"},
			IsGroup:      false,
		}

		result := extractChatJID(context.Background(), source, nil)
		assert.Equal(t, "1234567890", result.User)
		assert.Equal(t, "s.whatsapp.net", result.Server)
	})
}

// TestHandleMessage_LIDAddressing tests message handling with LID addressing
func (suite *EventHandlerTestSuite) TestHandleMessage_LIDAddressing() {
	messageID := "lid-test-message"
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
			ID:        messageID,
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: &messageText,
		},
	}

	// Handle message
	suite.eventHandler.HandleWithSession(messageEvent, suite.session.ID)

	// Verify last activity was updated
	assert.NotZero(suite.T(), suite.session.LastActivity)
}

// TestHandleMessage_LIDGroupMessage tests group message handling with LID addressing
func (suite *EventHandlerTestSuite) TestHandleMessage_LIDGroupMessage() {
	messageID := "lid-group-message"
	messageText := "Hello group from LID user"
	groupJID := types.JID{User: "120363111222333", Server: "g.us"}

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
			ID:        messageID,
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: &messageText,
		},
	}

	// Handle message
	suite.eventHandler.HandleWithSession(messageEvent, suite.session.ID)

	// Verify last activity was updated
	assert.NotZero(suite.T(), suite.session.LastActivity)
}

// TestHandleMessage_MixedLIDAndPN tests handling messages from both LID and PN users
func (suite *EventHandlerTestSuite) TestHandleMessage_MixedLIDAndPN() {
	// Test traditional PN message
	pnMessage := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:     types.JID{User: "1111111111", Server: "s.whatsapp.net"},
				Sender:   types.JID{User: "2222222222", Server: "s.whatsapp.net"},
				IsFromMe: false,
				IsGroup:  false,
			},
			ID:        "pn-message",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "PN message"; return &s }(),
		},
	}

	// Test LID message
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
			ID:        "lid-message",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: func() *string { s := "LID message"; return &s }(),
		},
	}

	// Both should be handled without errors
	suite.eventHandler.HandleWithSession(pnMessage, suite.session.ID)
	suite.eventHandler.HandleWithSession(lidMessage, suite.session.ID)

	assert.NotZero(suite.T(), suite.session.LastActivity)
}

// TestHandleMessage_DropsUnresolvableLIDDM verifies that handleMessage drops
// a DM whose sender LID cannot be resolved (no alt JID, and the session's
// real - but empty - sqlite LID store has no mapping), logging the drop
// instead of forwarding LID digits as a phone number.
func (suite *EventHandlerTestSuite) TestHandleMessage_DropsUnresolvableLIDDM() {
	logger, hook := logrustest.NewNullLogger()
	logger.SetLevel(logrus.DebugLevel)
	eh := NewEventHandler(suite.sessionMgr, logger)

	messageText := "unresolvable LID sender"
	messageEvent := &events.Message{
		Info: types.MessageInfo{
			MessageSource: types.MessageSource{
				Chat:         types.JID{User: "987654321", Server: "s.whatsapp.net"},
				Sender:       types.JID{User: "0:999999", Server: "lid"},
				SenderAlt:    types.JID{},
				RecipientAlt: types.JID{},
				IsFromMe:     false,
				IsGroup:      false,
			},
			ID:        "unresolvable-lid-dm",
			Timestamp: time.Now(),
		},
		Message: &proto.Message{
			Conversation: &messageText,
		},
	}

	eh.handleMessage(messageEvent, suite.session)

	var droppedFound, receivedFound bool
	for _, entry := range hook.AllEntries() {
		if strings.Contains(entry.Message, "Dropping DM with unresolvable LID sender") {
			droppedFound = true
		}
		if strings.Contains(entry.Message, "Message received") {
			receivedFound = true
		}
	}
	assert.True(suite.T(), droppedFound, "expected a log entry about dropping the unresolvable LID DM")
	assert.False(suite.T(), receivedFound, "message must not be forwarded past the LID guard")
}

func (suite *EventHandlerTestSuite) TestHandleAppStateSyncComplete() {
	evt := &events.AppStateSyncComplete{
		Name: appstate.WAPatchCriticalBlock,
	}

	// Test with session context — should route to handler without panic
	// PushName is empty on test sessions, so handler returns early at PushName guard
	suite.eventHandler.HandleWithSession(evt, suite.session.ID)

	// Test without session context (legacy)
	suite.eventHandler.Handle(evt)
	// Should not panic
}

func (suite *EventHandlerTestSuite) TestHandleAppStateSyncComplete_SkipsNonCritical() {
	evt := &events.AppStateSyncComplete{
		Name: appstate.WAPatchRegularLow,
	}

	// Should handle gracefully with nil session (returns at nil guard)
	suite.eventHandler.handleAppStateSyncComplete(evt, nil)
	// Should not panic

	// Should handle with session but non-critical sync type (returns at name check)
	suite.eventHandler.handleAppStateSyncComplete(evt, suite.session)
	// Should not panic
}

func (suite *EventHandlerTestSuite) TestHandlePushNameSetting() {
	evt := &events.PushNameSetting{}

	// Test with session context — should route to handler without panic
	suite.eventHandler.HandleWithSession(evt, suite.session.ID)

	// Test without session context (legacy)
	suite.eventHandler.Handle(evt)
	// Should not panic
}

func (suite *EventHandlerTestSuite) TestHandleStreamReplaced() {
	evt := &events.StreamReplaced{}

	// Set session as connected before stream replacement
	suite.session.Status = goTypes.StatusConnected
	suite.eventHandler.HandleWithSession(evt, suite.session.ID)

	// NotifyAccountLoggedOut → ProcessConnectionEvent("logged_out") sets StatusLoggedOut
	assert.Equal(suite.T(), goTypes.StatusLoggedOut, suite.session.Status)

	// Test without session context
	suite.eventHandler.Handle(evt)
	// Should not panic
}

func (suite *EventHandlerTestSuite) TestHandleTemporaryBan_NotifiesPHPKeepsSession() {
	evt := &events.TemporaryBan{
		Code:   events.TempBanSentToTooManyPeople,
		Expire: 24 * time.Hour,
	}

	suite.session.Status = goTypes.StatusConnected
	suite.eventHandler.HandleWithSession(evt, suite.session.ID)

	// PHP is notified via NotifyAccountLoggedOut → StatusLoggedOut
	assert.Equal(suite.T(), goTypes.StatusLoggedOut, suite.session.Status)

	// Session must still exist (files preserved for recovery after ban expires)
	_, stillExists := suite.sessionMgr.GetSession(suite.session.ID)
	assert.True(suite.T(), stillExists, "session must remain after TemporaryBan (files preserved)")

	// Legacy path must not panic
	suite.eventHandler.Handle(evt)
}

func (suite *EventHandlerTestSuite) TestHandleClientOutdated_LogsOnlyNoCleanup() {
	evt := &events.ClientOutdated{}

	suite.session.Status = goTypes.StatusConnected
	suite.eventHandler.HandleWithSession(evt, suite.session.ID)

	// No status change, session remains
	assert.Equal(suite.T(), goTypes.StatusConnected, suite.session.Status)
	_, stillExists := suite.sessionMgr.GetSession(suite.session.ID)
	assert.True(suite.T(), stillExists)

	// Legacy path
	suite.eventHandler.Handle(evt)
}

func (suite *EventHandlerTestSuite) TestHandleConnectFailure_MarksDisconnected() {
	evt := &events.ConnectFailure{
		Reason:  events.ConnectFailureServiceUnavailable,
		Message: "service unavailable, try again",
	}

	suite.session.Status = goTypes.StatusConnected
	suite.eventHandler.HandleWithSession(evt, suite.session.ID)

	// Marked disconnected but not cleaned up
	assert.Equal(suite.T(), goTypes.StatusDisconnected, suite.session.Status)
	_, stillExists := suite.sessionMgr.GetSession(suite.session.ID)
	assert.True(suite.T(), stillExists)

	// Legacy path
	suite.eventHandler.Handle(evt)
}

func (suite *EventHandlerTestSuite) TestHandleCATRefreshError_LogsOnly() {
	evt := &events.CATRefreshError{Error: assert.AnError}

	suite.session.Status = goTypes.StatusConnected
	suite.eventHandler.HandleWithSession(evt, suite.session.ID)

	assert.Equal(suite.T(), goTypes.StatusConnected, suite.session.Status)

	suite.eventHandler.Handle(evt)
}

func (suite *EventHandlerTestSuite) TestHandleHistorySync_NilDataDoesNotPanic() {
	evt := &events.HistorySync{Data: nil}

	// Should not panic even when Data is nil
	done := make(chan struct{})
	go func() {
		suite.eventHandler.HandleWithSession(evt, suite.session.ID)
		suite.eventHandler.Handle(evt)
		close(done)
	}()

	select {
	case <-done:
		// ok
	case <-time.After(500 * time.Millisecond):
		suite.T().Fatal("handleHistorySync blocked on nil Data")
	}
}

func (suite *EventHandlerTestSuite) TestHandleOfflineSyncPreview_Logs() {
	evt := &events.OfflineSyncPreview{
		Total:          42,
		AppDataChanges: 2,
		Messages:       20,
		Notifications:  5,
		Receipts:       15,
	}

	// No session state change, just an informational log
	suite.eventHandler.HandleWithSession(evt, suite.session.ID)
	suite.eventHandler.Handle(evt)
}

func (suite *EventHandlerTestSuite) TestHandleOfflineSyncCompleted_Logs() {
	evt := &events.OfflineSyncCompleted{Count: 42}

	suite.eventHandler.HandleWithSession(evt, suite.session.ID)
	suite.eventHandler.Handle(evt)
}

func TestIsLinkedDeviceReceipt_Device0IsPrimary(t *testing.T) {
	evt := &events.Receipt{
		MessageSource: types.MessageSource{
			Sender: types.JID{User: "1234", Server: "s.whatsapp.net", Device: 0},
		},
	}
	assert.False(t, isLinkedDeviceReceipt(evt), "Device=0 is primary device, not linked")
}

func TestIsLinkedDeviceReceipt_DeviceNonZeroIsLinked(t *testing.T) {
	evt := &events.Receipt{
		MessageSource: types.MessageSource{
			Sender: types.JID{User: "1234", Server: "s.whatsapp.net", Device: 34},
		},
	}
	assert.True(t, isLinkedDeviceReceipt(evt), "Device=34 is a linked device")
}

func TestDefaultConnectPresence_IsUnavailable(t *testing.T) {
	// Bot accounts must not advertise "online" on connect. Flipping this
	// constant is a user-facing behaviour change.
	assert.Equal(t, types.PresenceUnavailable, defaultConnectPresence,
		"defaultConnectPresence must stay Unavailable; changing this makes bots appear online to all contacts")
}

// TestIsUnresolvedLIDDM tests the guard that stops LID digits from being
// forwarded to Zender as phone numbers.
func TestIsUnresolvedLIDDM(t *testing.T) {
	t.Run("DM with lid sender is dropped", func(t *testing.T) {
		assert.True(t, isUnresolvedLIDDM(false, types.JID{User: "98765", Server: "lid"}))
	})

	t.Run("DM with phone sender passes", func(t *testing.T) {
		assert.False(t, isUnresolvedLIDDM(false, types.JID{User: "1234567890", Server: "s.whatsapp.net"}))
	})

	t.Run("group message passes even with lid participant", func(t *testing.T) {
		assert.False(t, isUnresolvedLIDDM(true, types.JID{User: "98765", Server: "lid"}))
	})
}

// TestMsgSessionID tests the nil-tolerant session ID accessor used for
// logging on the legacy Handle path.
func TestMsgSessionID(t *testing.T) {
	assert.Equal(t, "", msgSessionID(nil))
	assert.Equal(t, "abc", msgSessionID(&goTypes.Session{ID: "abc"}))
}

// TestSessionLIDResolver_NilChain tests that sessionLIDResolver returns a nil
// resolver whenever any link in the session -> client -> store -> LIDs chain
// is missing.
func TestSessionLIDResolver_NilChain(t *testing.T) {
	assert.Nil(t, sessionLIDResolver(nil))
	assert.Nil(t, sessionLIDResolver(&goTypes.Session{}))
}
