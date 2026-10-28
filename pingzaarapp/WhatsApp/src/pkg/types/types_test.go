package types

import (
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"github.com/stretchr/testify/suite"
)

// MessageQueueTestSuite tests the MessageQueue functionality
type MessageQueueTestSuite struct {
	suite.Suite
	queue *MessageQueue
}

func (suite *MessageQueueTestSuite) SetupTest() {
	suite.queue = NewMessageQueue()
}

func (suite *MessageQueueTestSuite) TestNewMessageQueue() {
	queue := NewMessageQueue()

	assert.NotNil(suite.T(), queue)
	assert.Equal(suite.T(), 100, queue.MaxSize)
	assert.Equal(suite.T(), 0, len(queue.Messages))
	// Note: Mu is a value type (sync.RWMutex), always initialized with the struct
}

func (suite *MessageQueueTestSuite) TestAdd() {
	message := QueuedMessage{
		ID:       1,
		CID:      1,
		Phone:    "1234567890",
		Message:  `{"text": "Hello"}`,
		Priority: 1,
		WID:      "wid1",
	}

	result := suite.queue.Add(message)

	assert.True(suite.T(), result)
	assert.Equal(suite.T(), 1, suite.queue.Size())

	retrieved := suite.queue.GetNext()
	assert.NotNil(suite.T(), retrieved)
	assert.Equal(suite.T(), message.ID, retrieved.ID)
}

func (suite *MessageQueueTestSuite) TestAdd_QueueFull() {
	// Fill the queue to capacity
	for i := 0; i < 100; i++ {
		message := QueuedMessage{
			ID:       i + 1,
			CID:      1,
			Phone:    "1234567890",
			Message:  `{"text": "Hello"}`,
			Priority: 1,
			WID:      "wid1",
		}
		result := suite.queue.Add(message)
		assert.True(suite.T(), result)
	}

	// Try to add one more message - should fail
	extraMessage := QueuedMessage{
		ID:       101,
		CID:      1,
		Phone:    "1234567890",
		Message:  `{"text": "Hello"}`,
		Priority: 1,
		WID:      "wid1",
	}
	result := suite.queue.Add(extraMessage)

	assert.False(suite.T(), result)
	assert.Equal(suite.T(), 100, suite.queue.Size())
}

func (suite *MessageQueueTestSuite) TestAddBatch() {
	messages := []QueuedMessage{
		{ID: 1, CID: 1, Phone: "1234567890", Message: `{"text": "Hello1"}`, Priority: 1, WID: "wid1"},
		{ID: 2, CID: 1, Phone: "1234567890", Message: `{"text": "Hello2"}`, Priority: 1, WID: "wid1"},
		{ID: 3, CID: 1, Phone: "1234567890", Message: `{"text": "Hello3"}`, Priority: 1, WID: "wid1"},
	}

	added := suite.queue.AddBatch(messages)

	assert.Equal(suite.T(), 3, added)
	assert.Equal(suite.T(), 3, suite.queue.Size())
}

func (suite *MessageQueueTestSuite) TestAddBatch_PartialAdd() {
	// Fill queue to near capacity
	for i := 0; i < 98; i++ {
		message := QueuedMessage{
			ID:       1000 + i, // Convert to int
			CID:      1,        // Convert to int
			Phone:    "1234567890",
			Message:  `{"text": "Hello"}`,
			Priority: 1,
			WID:      "wid1",
		}
		suite.queue.Add(message)
	}

	// Try to add 5 messages, but only 2 should fit
	messages := []QueuedMessage{
		{ID: 2001, CID: 1, Phone: "1234567890", Message: `{"text": "Hello1"}`, Priority: 1, WID: "wid1"},
		{ID: 2002, CID: 1, Phone: "1234567890", Message: `{"text": "Hello2"}`, Priority: 1, WID: "wid1"},
		{ID: 2003, CID: 1, Phone: "1234567890", Message: `{"text": "Hello3"}`, Priority: 1, WID: "wid1"},
		{ID: 2004, CID: 1, Phone: "1234567890", Message: `{"text": "Hello4"}`, Priority: 1, WID: "wid1"},
		{ID: 2005, CID: 1, Phone: "1234567890", Message: `{"text": "Hello5"}`, Priority: 1, WID: "wid1"},
	}

	added := suite.queue.AddBatch(messages)

	assert.Equal(suite.T(), 2, added)
	assert.Equal(suite.T(), 100, suite.queue.Size())
}

func (suite *MessageQueueTestSuite) TestGetNext() {
	// Test empty queue
	message := suite.queue.GetNext()
	assert.Nil(suite.T(), message)

	// Add messages and test retrieval
	msg1 := QueuedMessage{ID: 1001, CID: 1, Phone: "1234567890", Message: `{"text": "Hello1"}`, Priority: 1, WID: "wid1"}
	msg2 := QueuedMessage{ID: 1002, CID: 1, Phone: "1234567890", Message: `{"text": "Hello2"}`, Priority: 2, WID: "wid1"}

	suite.queue.Add(msg1)
	suite.queue.Add(msg2)

	// Should get first message
	retrieved := suite.queue.GetNext()
	require.NotNil(suite.T(), retrieved)
	assert.Equal(suite.T(), 1001, retrieved.ID)
	assert.Equal(suite.T(), 1, suite.queue.Size())

	// Should get second message
	retrieved = suite.queue.GetNext()
	require.NotNil(suite.T(), retrieved)
	assert.Equal(suite.T(), 1002, retrieved.ID)
	assert.Equal(suite.T(), 0, suite.queue.Size())

	// Queue should be empty now
	retrieved = suite.queue.GetNext()
	assert.Nil(suite.T(), retrieved)
}

func (suite *MessageQueueTestSuite) TestSize() {
	assert.Equal(suite.T(), 0, suite.queue.Size())

	suite.queue.Add(QueuedMessage{ID: 1001, CID: 1, Phone: "1234567890", Message: `{"text": "Hello1"}`, Priority: 1, WID: "wid1"})
	assert.Equal(suite.T(), 1, suite.queue.Size())

	suite.queue.Add(QueuedMessage{ID: 1002, CID: 1, Phone: "1234567890", Message: `{"text": "Hello2"}`, Priority: 1, WID: "wid1"})
	assert.Equal(suite.T(), 2, suite.queue.Size())

	suite.queue.GetNext()
	assert.Equal(suite.T(), 1, suite.queue.Size())
}

func (suite *MessageQueueTestSuite) TestIsEmpty() {
	assert.True(suite.T(), suite.queue.IsEmpty())

	suite.queue.Add(QueuedMessage{ID: 1001, CID: 1, Phone: "1234567890", Message: `{"text": "Hello1"}`, Priority: 1, WID: "wid1"})
	assert.False(suite.T(), suite.queue.IsEmpty())

	suite.queue.GetNext()
	assert.True(suite.T(), suite.queue.IsEmpty())
}

func (suite *MessageQueueTestSuite) TestIsFull() {
	assert.False(suite.T(), suite.queue.IsFull())

	// Fill to capacity
	for i := 0; i < 100; i++ {
		message := QueuedMessage{
			ID:       3000 + i,
			CID:      1,
			Phone:    "1234567890",
			Message:  `{"text": "Hello"}`,
			Priority: 1,
			WID:      "wid1",
		}
		suite.queue.Add(message)
	}

	assert.True(suite.T(), suite.queue.IsFull())

	// Remove one message
	suite.queue.GetNext()
	assert.False(suite.T(), suite.queue.IsFull())
}

func (suite *MessageQueueTestSuite) TestClear() {
	// Add some messages
	for i := 0; i < 5; i++ {
		message := QueuedMessage{
			ID:       3000 + i,
			CID:      1,
			Phone:    "1234567890",
			Message:  `{"text": "Hello"}`,
			Priority: 1,
			WID:      "wid1",
		}
		suite.queue.Add(message)
	}

	assert.Equal(suite.T(), 5, suite.queue.Size())
	assert.False(suite.T(), suite.queue.IsEmpty())

	suite.queue.Clear()

	assert.Equal(suite.T(), 0, suite.queue.Size())
	assert.True(suite.T(), suite.queue.IsEmpty())
}

func (suite *MessageQueueTestSuite) TestConcurrentAccess() {
	const numGoroutines = 10
	const messagesPerGoroutine = 10

	var wg sync.WaitGroup

	// Concurrent adds
	for i := 0; i < numGoroutines; i++ {
		wg.Add(1)
		go func(routineID int) {
			defer wg.Done()
			for j := 0; j < messagesPerGoroutine; j++ {
				message := QueuedMessage{
					ID:       4000 + routineID*100 + j,
					CID:      1,
					Phone:    "1234567890",
					Message:  `{"text": "Hello"}`,
					Priority: 1,
					WID:      "wid1",
				}
				suite.queue.Add(message)
			}
		}(i)
	}

	wg.Wait()

	// Should have exactly 100 messages (queue capacity)
	assert.Equal(suite.T(), 100, suite.queue.Size())

	// Concurrent reads
	retrievedCount := 0
	var retrievedMutex sync.Mutex

	for i := 0; i < numGoroutines; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for j := 0; j < messagesPerGoroutine; j++ {
				message := suite.queue.GetNext()
				if message != nil {
					retrievedMutex.Lock()
					retrievedCount++
					retrievedMutex.Unlock()
				}
			}
		}()
	}

	wg.Wait()

	// Should have retrieved exactly 100 messages
	assert.Equal(suite.T(), 100, retrievedCount)
	assert.True(suite.T(), suite.queue.IsEmpty())
}

func (suite *MessageQueueTestSuite) TestPriorityOrdering() {
	// Add messages with different priorities (though current implementation is FIFO)
	highPriority := QueuedMessage{ID: 5001, CID: 1, Phone: "1234567890", Message: `{"text": "High"}`, Priority: 1, WID: "wid1"}
	lowPriority := QueuedMessage{ID: 5002, CID: 1, Phone: "1234567890", Message: `{"text": "Low"}`, Priority: 2, WID: "wid1"}

	suite.queue.Add(lowPriority)
	suite.queue.Add(highPriority)

	// Current implementation is FIFO, so should get low priority first (added first)
	first := suite.queue.GetNext()
	require.NotNil(suite.T(), first)
	assert.Equal(suite.T(), 5002, first.ID)

	second := suite.queue.GetNext()
	require.NotNil(suite.T(), second)
	assert.Equal(suite.T(), 5001, second.ID)
}

func TestMessageQueueTestSuite(t *testing.T) {
	suite.Run(t, new(MessageQueueTestSuite))
}

// SessionMessageStateTestSuite tests the SessionMessageState functionality
type SessionMessageStateTestSuite struct {
	suite.Suite
	state *SessionMessageState
}

func (suite *SessionMessageStateTestSuite) SetupTest() {
	suite.state = NewSessionMessageState()
}

func (suite *SessionMessageStateTestSuite) TestNewSessionMessageState() {
	state := NewSessionMessageState()

	assert.NotNil(suite.T(), state)
	assert.False(suite.T(), state.IsSending)
	assert.True(suite.T(), state.LastSentTime.IsZero())
}

func (suite *SessionMessageStateTestSuite) TestSetSending() {
	assert.False(suite.T(), suite.state.IsSending)
	assert.True(suite.T(), suite.state.LastSentTime.IsZero())

	suite.state.SetSending(true)
	assert.True(suite.T(), suite.state.IsSending)
	// LastSentTime is NOT set by SetSending - only by SetLastSentTime after message processing
	assert.True(suite.T(), suite.state.LastSentTime.IsZero())

	suite.state.SetSending(false)
	assert.False(suite.T(), suite.state.IsSending)
	// LastSentTime should still be zero (not set yet)
	assert.True(suite.T(), suite.state.LastSentTime.IsZero())
}

func (suite *SessionMessageStateTestSuite) TestSetLastSentTime() {
	assert.True(suite.T(), suite.state.LastSentTime.IsZero())

	// Simulate message processing cycle
	suite.state.SetSending(true)
	assert.True(suite.T(), suite.state.IsSending)
	assert.True(suite.T(), suite.state.LastSentTime.IsZero())

	// After successful message send, set LastSentTime
	suite.state.SetLastSentTime(time.Now())
	assert.False(suite.T(), suite.state.LastSentTime.IsZero())

	suite.state.SetSending(false)
	assert.False(suite.T(), suite.state.IsSending)
	// LastSentTime should remain set
	assert.False(suite.T(), suite.state.LastSentTime.IsZero())
}

func (suite *SessionMessageStateTestSuite) TestCanSendNow_FirstMessage() {
	// First message should always be sendable
	canSend := suite.state.CanSendNow(0, 0, 0)
	assert.True(suite.T(), canSend)
}

func (suite *SessionMessageStateTestSuite) TestCanSendNow_WhileSending() {
	// Set as sending
	suite.state.SetSending(true)

	// Should not be able to send while already sending
	canSend := suite.state.CanSendNow(0, 0, 0)
	assert.False(suite.T(), canSend)

	// Simulate message send completion
	suite.state.SetLastSentTime(time.Now())
	suite.state.SetSending(false)

	// Should NOT be able to send again immediately due to 3-second default delay
	canSend = suite.state.CanSendNow(0, 0, 0)
	assert.False(suite.T(), canSend)
}

func (suite *SessionMessageStateTestSuite) TestCanSendNow_WithDelay() {
	// Send first message - simulate message processing cycle
	suite.state.SetSending(true)
	suite.state.SetLastSentTime(time.Now()) // Set after message sent
	suite.state.SetSending(false)

	// Should NOT be able to send immediately after due to 3-second default delay
	canSend := suite.state.CanSendNow(0, 0, 0)
	assert.False(suite.T(), canSend)

	// Should not be able to send immediately with 5-10 second random delay either
	canSend = suite.state.CanSendNow(1, 5, 10)
	assert.False(suite.T(), canSend)
}

func (suite *SessionMessageStateTestSuite) TestConcurrentAccess() {
	const numGoroutines = 50
	var wg sync.WaitGroup

	// Test concurrent SetSending calls
	for i := 0; i < numGoroutines; i++ {
		wg.Add(1)
		go func(id int) {
			defer wg.Done()
			for j := 0; j < 10; j++ {
				suite.state.SetSending(true)
				suite.state.SetSending(false)
				suite.state.CanSendNow(0, 0, 0)
			}
		}(i)
	}

	wg.Wait()

	// Should not panic and state should be consistent
	assert.False(suite.T(), suite.state.IsSending)
}

func TestSessionMessageStateTestSuite(t *testing.T) {
	suite.Run(t, new(SessionMessageStateTestSuite))
}

// TestContains tests the Contains method for checking message existence
func (suite *MessageQueueTestSuite) TestContains() {
	// Empty queue should return false
	assert.False(suite.T(), suite.queue.Contains(1, 0))

	// Add a message
	message := QueuedMessage{
		ID:       123,
		CID:      456,
		Phone:    "1234567890",
		Message:  `{"text": "Hello"}`,
		Priority: 1,
		WID:      "wid1",
	}
	suite.queue.Add(message)

	// Should find the message
	assert.True(suite.T(), suite.queue.Contains(123, 456))

	// Should not find non-existent messages
	assert.False(suite.T(), suite.queue.Contains(123, 0))   // Wrong CID
	assert.False(suite.T(), suite.queue.Contains(999, 456)) // Wrong ID
	assert.False(suite.T(), suite.queue.Contains(999, 999)) // Both wrong

	// After removing, should not find it
	suite.queue.GetNext()
	assert.False(suite.T(), suite.queue.Contains(123, 456))
}

func (suite *MessageQueueTestSuite) TestContains_MultipleMessages() {
	// Add multiple messages
	messages := []QueuedMessage{
		{ID: 1, CID: 0, Phone: "111", Message: `{}`, Priority: 1, WID: "wid1"},
		{ID: 2, CID: 0, Phone: "222", Message: `{}`, Priority: 1, WID: "wid1"},
		{ID: 3, CID: 100, Phone: "333", Message: `{}`, Priority: 1, WID: "wid1"},
		{ID: 3, CID: 200, Phone: "444", Message: `{}`, Priority: 1, WID: "wid1"}, // Same ID, different CID
	}

	for _, msg := range messages {
		suite.queue.Add(msg)
	}

	// Check all messages exist
	assert.True(suite.T(), suite.queue.Contains(1, 0))
	assert.True(suite.T(), suite.queue.Contains(2, 0))
	assert.True(suite.T(), suite.queue.Contains(3, 100))
	assert.True(suite.T(), suite.queue.Contains(3, 200))

	// Check that same ID with wrong CID returns false
	assert.False(suite.T(), suite.queue.Contains(1, 100))
	assert.False(suite.T(), suite.queue.Contains(3, 0))
}

func (suite *MessageQueueTestSuite) TestContains_AfterClear() {
	// Add a message
	message := QueuedMessage{ID: 1, CID: 0, Phone: "111", Message: `{}`, Priority: 1, WID: "wid1"}
	suite.queue.Add(message)

	assert.True(suite.T(), suite.queue.Contains(1, 0))

	// Clear the queue
	suite.queue.Clear()

	// Should not find the message anymore
	assert.False(suite.T(), suite.queue.Contains(1, 0))
}

func (suite *MessageQueueTestSuite) TestContains_NilMessageSet() {
	// Create a queue with nil messageSet (simulating edge case)
	queue := &MessageQueue{
		Messages:   []QueuedMessage{},
		MaxSize:    100,
		messageSet: nil, // Nil map
	}

	// Should return false without panic
	assert.False(suite.T(), queue.Contains(1, 0))
}

// Additional unit tests for edge cases
func TestMessageQueue_EdgeCases(t *testing.T) {
	t.Run("Full queue behavior", func(t *testing.T) {
		queue := NewMessageQueue()

		// Fill to capacity (100 messages)
		for i := 0; i < 100; i++ {
			message := QueuedMessage{ID: 7000 + i, CID: 1, Phone: "1234567890", Message: `{"text": "Hello"}`, Priority: 1, WID: "wid1"}
			result := queue.Add(message)
			assert.True(t, result)
		}

		assert.True(t, queue.IsFull())

		// Try to add one more - should fail
		message := QueuedMessage{ID: 6001, CID: 1, Phone: "1234567890", Message: `{"text": "Hello"}`, Priority: 1, WID: "wid1"}
		result := queue.Add(message)
		assert.False(t, result)

		// Retrieve one message to make space
		retrieved := queue.GetNext()
		require.NotNil(t, retrieved)
		assert.False(t, queue.IsFull())

		// Now should be able to add again
		result = queue.Add(message)
		assert.True(t, result)
	})

	t.Run("Empty batch add", func(t *testing.T) {
		queue := NewMessageQueue()

		added := queue.AddBatch([]QueuedMessage{})
		assert.Equal(t, 0, added)
		assert.True(t, queue.IsEmpty())
	})

	t.Run("Nil batch add", func(t *testing.T) {
		queue := NewMessageQueue()

		added := queue.AddBatch(nil)
		assert.Equal(t, 0, added)
		assert.True(t, queue.IsEmpty())
	})

	t.Run("Single message operations", func(t *testing.T) {
		queue := NewMessageQueue()

		message1 := QueuedMessage{ID: 1001, CID: 1, Phone: "1234567890", Message: `{"text": "Hello1"}`, Priority: 1, WID: "wid1"}
		message2 := QueuedMessage{ID: 1002, CID: 1, Phone: "1234567890", Message: `{"text": "Hello2"}`, Priority: 1, WID: "wid1"}

		assert.True(t, queue.Add(message1))
		assert.False(t, queue.IsEmpty())
		assert.False(t, queue.IsFull())

		assert.True(t, queue.Add(message2))
		assert.Equal(t, 2, queue.Size())

		retrieved := queue.GetNext()
		require.NotNil(t, retrieved)
		assert.Equal(t, 1001, retrieved.ID)
		assert.Equal(t, 1, queue.Size())

		retrieved = queue.GetNext()
		require.NotNil(t, retrieved)
		assert.Equal(t, 1002, retrieved.ID)
		assert.True(t, queue.IsEmpty())
	})
}
