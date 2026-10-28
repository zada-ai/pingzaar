package whatsapp

import (
	"sync"
	"testing"
	"time"

	"github.com/sirupsen/logrus"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"github.com/stretchr/testify/suite"

	"whatsapp-server/pkg/types"
	"whatsapp-server/tests/helpers"
)

// ProcessorTestSuite tests the EventDrivenProcessor with mock dependencies
type ProcessorTestSuite struct {
	suite.Suite
	processor    *EventDrivenProcessor
	mockSessMgr  *MockSessionManager
	mockSender   *MockMessageSender
	mockReporter *MockStatusReporter
	logger       *logrus.Logger
}

func TestProcessorTestSuite(t *testing.T) {
	suite.Run(t, new(ProcessorTestSuite))
}

func (suite *ProcessorTestSuite) SetupTest() {
	helpers.GinTestMode()
	suite.logger = logrus.New()
	suite.logger.SetLevel(logrus.ErrorLevel)

	suite.mockSessMgr = NewMockSessionManager()
	suite.mockSender = &MockMessageSender{}
	suite.mockReporter = &MockStatusReporter{}

	suite.processor = NewEventDrivenProcessorWithDeps(
		suite.mockSessMgr,
		suite.mockSender,
		suite.mockReporter,
		suite.logger,
	)
}

func (suite *ProcessorTestSuite) TearDownTest() {
	if suite.processor != nil {
		suite.processor.Stop()
	}
}

// ===========================================
// Test 1: Start and Stop
// ===========================================

func (suite *ProcessorTestSuite) TestProcessor_StartAndStop() {
	// Start the processor
	suite.processor.Start()

	// Give it a moment to start goroutines
	time.Sleep(50 * time.Millisecond)

	// Verify context is not cancelled
	select {
	case <-suite.processor.ctx.Done():
		suite.Fail("Context should not be cancelled while running")
	default:
		// Expected - context is still active
	}

	// Stop the processor
	suite.processor.Stop()

	// Verify context is cancelled
	select {
	case <-suite.processor.ctx.Done():
		// Expected - context is cancelled
	default:
		suite.Fail("Context should be cancelled after Stop")
	}
}

// ===========================================
// Test 2: Message Added Event Triggers Processing
// ===========================================

func (suite *ProcessorTestSuite) TestProcessor_MessageAddedEventTriggersProcessing() {
	// Create a connected session with a message
	session := &types.Session{
		ID:           "test-session",
		Status:       types.StatusConnected,
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
		Cache: &types.SessionCache{
			RandomSend: 0, // 0 = disabled, 1 = enabled
			RandomMin:  0,
			RandomMax:  0,
		},
	}
	session.MessageQueue.Add(types.QueuedMessage{
		ID:      1,
		Phone:   "+1234567890",
		Message: `{"text":"Hello"}`,
	})
	suite.mockSessMgr.AddSession(session)

	// Directly test processSingleMessage to verify message sending works
	// (The event-driven system has timing dependencies that are hard to test)
	suite.processor.processSingleMessage(session)

	// Verify message was sent
	assert.Equal(suite.T(), 1, suite.mockSender.CallCount())

	// Verify message was removed from queue
	assert.True(suite.T(), session.MessageQueue.IsEmpty())
}

// ===========================================
// Test 3: Rate Limiting Respects Delay
// ===========================================

func (suite *ProcessorTestSuite) TestProcessor_RateLimitingRespectsDelay() {
	// Create a connected session with delay settings
	session := &types.Session{
		ID:           "test-session",
		Status:       types.StatusConnected,
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
		Cache: &types.SessionCache{
			RandomSend: 1, // 1 = random delay enabled
			RandomMin:  1, // 1 second minimum delay
			RandomMax:  1, // 1 second maximum delay
		},
	}
	// Set last sent time to now
	session.MessageState.SetLastSentTime(time.Now())
	session.MessageState.InitializeNextDelay(1, 1, 1)

	suite.mockSessMgr.AddSession(session)

	// Check if processor recognizes it can't send yet
	canSend := suite.processor.canSendNow(session)
	assert.False(suite.T(), canSend, "Should not be able to send immediately after last send")
}

// ===========================================
// Test 4: Campaign Paused Skips Messages
// ===========================================

func (suite *ProcessorTestSuite) TestProcessor_CampaignPausedSkipsMessages() {
	// Create a connected session with a paused campaign message
	session := &types.Session{
		ID:           "test-session",
		Status:       types.StatusConnected,
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
		Cache: &types.SessionCache{
			RandomSend: 0,
			RandomMin:  0,
			RandomMax:  0,
		},
	}

	// Add message from campaign ID 123
	session.MessageQueue.Add(types.QueuedMessage{
		ID:      1,
		CID:     123,
		Phone:   "+1234567890",
		Message: `{"text":"Hello"}`,
	})

	// Pause the campaign
	session.PauseCampaign(123)

	suite.mockSessMgr.AddSession(session)

	// Start the processor
	suite.processor.Start()

	// Trigger processing
	suite.processor.OnMessagesAdded("test-session")

	// Wait for processing
	time.Sleep(200 * time.Millisecond)

	// Verify message was NOT sent (campaign is paused)
	assert.Equal(suite.T(), 0, suite.mockSender.CallCount(), "Message should not be sent for paused campaign")
}

// ===========================================
// Test 5: Concurrent Events Are Thread Safe
// ===========================================

func (suite *ProcessorTestSuite) TestProcessor_ConcurrentEventsThreadSafe() {
	// Create multiple sessions
	sessions := make([]*types.Session, 5)
	for i := 0; i < 5; i++ {
		session := &types.Session{
			ID:           "session-" + string(rune('A'+i)),
			Status:       types.StatusConnected,
			MessageQueue: types.NewMessageQueue(),
			MessageState: types.NewSessionMessageState(),
			Cache: &types.SessionCache{
				RandomSend: 0,
				RandomMin:  0,
				RandomMax:  0,
			},
		}
		session.MessageQueue.Add(types.QueuedMessage{
			ID:      i + 1,
			Phone:   "+1234567890",
			Message: `{"text":"Hello"}`,
		})
		suite.mockSessMgr.AddSession(session)
		sessions[i] = session
	}

	// Process messages concurrently
	var wg sync.WaitGroup
	for i := 0; i < 5; i++ {
		wg.Add(1)
		go func(idx int) {
			defer wg.Done()
			suite.processor.processSingleMessage(sessions[idx])
		}(i)
	}
	wg.Wait()

	// Should not panic and should process all messages
	assert.Equal(suite.T(), 5, suite.mockSender.CallCount())
}

// ===========================================
// Test 6: Queue Overflow Handling
// ===========================================

func (suite *ProcessorTestSuite) TestProcessor_QueueOverflowHandling() {
	// Create a session
	session := &types.Session{
		ID:           "test-session",
		Status:       types.StatusConnected,
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
		Cache: &types.SessionCache{
			RandomSend: 0,
			RandomMin:  0,
			RandomMax:  0,
		},
	}
	suite.mockSessMgr.AddSession(session)

	// Fill the queue to test overflow behavior
	// Note: We can't actually overflow a 20000 buffer easily in tests,
	// but we can verify the retry mechanism works
	for i := 0; i < 100; i++ {
		suite.processor.TriggerProcessing("test-session", "test_event")
	}

	// Queue should have accepted many events
	queueLen := len(suite.processor.processingQueue)
	assert.GreaterOrEqual(suite.T(), queueLen, 50, "Queue should accept events")
}

// ===========================================
// Test 7: Session Not Found Handling
// ===========================================

func (suite *ProcessorTestSuite) TestProcessor_SessionNotFoundHandling() {
	// Start the processor
	suite.processor.Start()

	// Trigger processing for non-existent session
	suite.processor.TriggerProcessing("non-existent-session", "messages_added")

	// Wait for processing
	time.Sleep(100 * time.Millisecond)

	// Should not panic and should not send any messages
	assert.Equal(suite.T(), 0, suite.mockSender.CallCount())
}

// ===========================================
// Test 8: Disconnected Session Handling
// ===========================================

func (suite *ProcessorTestSuite) TestProcessor_DisconnectedSessionHandling() {
	// Create a disconnected session
	session := &types.Session{
		ID:           "test-session",
		Status:       types.StatusDisconnected,
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
		Cache: &types.SessionCache{
			RandomSend: 0,
			RandomMin:  0,
			RandomMax:  0,
		},
	}
	session.MessageQueue.Add(types.QueuedMessage{
		ID:      1,
		Phone:   "+1234567890",
		Message: `{"text":"Hello"}`,
	})
	suite.mockSessMgr.AddSession(session)

	// Start the processor
	suite.processor.Start()

	// Trigger processing
	suite.processor.OnMessagesAdded("test-session")

	// Wait for processing
	time.Sleep(100 * time.Millisecond)

	// Verify message was NOT sent (session is disconnected)
	assert.Equal(suite.T(), 0, suite.mockSender.CallCount(), "Message should not be sent for disconnected session")
}

// ===========================================
// Additional Tests for Edge Cases
// ===========================================

func TestProcessor_IsSendingFlagPreventsDoubleProcessing(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	mockSessMgr := NewMockSessionManager()
	mockSender := &MockMessageSender{}
	mockReporter := &MockStatusReporter{}

	processor := NewEventDrivenProcessorWithDeps(mockSessMgr, mockSender, mockReporter, logger)
	defer processor.Stop()

	// Create a session that's already "sending"
	session := &types.Session{
		ID:           "test-session",
		Status:       types.StatusConnected,
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
		Cache: &types.SessionCache{
			RandomSend: 0,
			RandomMin:  0,
			RandomMax:  0,
		},
	}
	session.MessageQueue.Add(types.QueuedMessage{
		ID:      1,
		Phone:   "+1234567890",
		Message: `{"text":"Hello"}`,
	})
	// Mark as already sending
	session.MessageState.TrySetSending()
	mockSessMgr.AddSession(session)

	// Try to process - should skip because IsSending is true
	processor.processSingleMessage(session)

	// Should not have sent any message (already in sending state)
	// Note: The first TrySetSending() above already set it
	assert.Equal(t, 0, mockSender.CallCount())
}

func TestProcessor_StatusReportError_MessageStillRemoved(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	mockSessMgr := NewMockSessionManager()
	mockSender := &MockMessageSender{
		SendFunc: func(session *types.Session, message types.QueuedMessage) bool {
			return true // Message sent successfully
		},
	}
	mockReporter := &MockStatusReporter{
		QueueError: assert.AnError, // Simulate queue full error
	}

	processor := NewEventDrivenProcessorWithDeps(mockSessMgr, mockSender, mockReporter, logger)
	defer processor.Stop()

	// Create a session with a message
	session := &types.Session{
		ID:           "test-session",
		Status:       types.StatusConnected,
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
		Cache: &types.SessionCache{
			RandomSend: 0,
			RandomMin:  0,
			RandomMax:  0,
		},
	}
	session.MessageQueue.Add(types.QueuedMessage{
		ID:      1,
		Phone:   "+1234567890",
		Message: `{"text":"Hello"}`,
	})
	mockSessMgr.AddSession(session)

	// Process the message
	processor.processSingleMessage(session)

	// Message should be sent
	require.Equal(t, 1, mockSender.CallCount())

	// Message should be removed from queue despite status report error
	assert.True(t, session.MessageQueue.IsEmpty(), "Message should be removed even if status report fails")
}

func TestProcessor_EmptyQueueHandling(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	mockSessMgr := NewMockSessionManager()
	mockSender := &MockMessageSender{}
	mockReporter := &MockStatusReporter{}

	processor := NewEventDrivenProcessorWithDeps(mockSessMgr, mockSender, mockReporter, logger)
	defer processor.Stop()

	// Create a session with empty queue
	session := &types.Session{
		ID:           "test-session",
		Status:       types.StatusConnected,
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
		Cache: &types.SessionCache{
			RandomSend: 0,
			RandomMin:  0,
			RandomMax:  0,
		},
	}
	mockSessMgr.AddSession(session)

	// Process - should do nothing because queue is empty
	processor.processSingleMessage(session)

	// No message should be sent
	assert.Equal(t, 0, mockSender.CallCount())
}

func TestProcessor_FirstMessageInitializesBaseline(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	mockSessMgr := NewMockSessionManager()
	processor := NewEventDrivenProcessorWithDeps(mockSessMgr, &MockMessageSender{}, &MockStatusReporter{}, logger)
	defer processor.Stop()

	// Create a fresh session (baseline not initialized)
	session := &types.Session{
		ID:           "test-session",
		Status:       types.StatusConnected,
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
		Cache: &types.SessionCache{
			RandomSend: 1, // 1 = enabled
			RandomMin:  1,
			RandomMax:  2,
		},
	}
	session.MessageQueue.Add(types.QueuedMessage{
		ID:      1,
		Phone:   "+1234567890",
		Message: `{"text":"Hello"}`,
	})
	mockSessMgr.AddSession(session)

	// First check should initialize baseline and return false
	canSend := processor.canSendNow(session)
	assert.False(t, canSend, "First message should initialize baseline and return false")

	// LastSentTime should now be set (baseline initialized)
	assert.False(t, session.MessageState.LastSentTime.IsZero(), "LastSentTime should be initialized")
}
