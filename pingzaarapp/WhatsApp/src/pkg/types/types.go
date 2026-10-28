package types

import (
	cryptoRand "crypto/rand"
	"database/sql"
	"encoding/json"
	"fmt"
	"math/big"
	"strconv"
	"sync"
	"time"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/store"
	"go.mau.fi/whatsmeow/types"
)

// SessionStatus represents the current state of a WhatsApp session
type SessionStatus int

const (
	StatusDisconnected SessionStatus = iota
	StatusConnecting
	StatusConnected
	StatusLoggedOut
)

// QueuedMessage represents a message in the sending queue
// This matches the format expected from PHP /whatsapp/messages endpoint
//
// IMPORTANT: The server uses a two-tier message sending system:
// 1. Queue Mode: Messages fetched from PHP /whatsapp/messages endpoint (normal priority)
//   - These messages are queued and sent with rate limiting/delays
//   - Priority field defaults to 0 for queued messages
//
// 2. Priority Mode: Messages sent via POST /chats/send (immediate send)
//   - Bypass queue and send immediately without delay
//   - Priority is set internally, not from PHP response
//
// PHP API Quirk: PHP returns id/cid/priority as JSON strings ("5807") instead of numbers (5807)
// due to PDO default behavior. The custom UnmarshalJSON method below handles this by accepting
// both string and numeric types for defensive parsing.
type QueuedMessage struct {
	ID       int    `json:"id"`       // Message ID from PHP (accepts string or number via custom unmarshal)
	CID      int    `json:"cid"`      // Campaign ID (accepts string or number via custom unmarshal)
	CStatus  int    `json:"cstatus"`  // Campaign status: 1=running, 2=paused (accepts string or number via custom unmarshal)
	Phone    string `json:"phone"`    // Recipient phone number
	Message  string `json:"message"`  // JSON-encoded message content
	Priority int    `json:"priority"` // Send priority (0=queue default, 1=high for priority mode)
	WID      string `json:"wid"`      // WhatsApp ID
}

// UnmarshalJSON implements custom JSON unmarshaling for QueuedMessage
// to handle PHP's string-based numeric fields (id, cid, cstatus, priority).
//
// PHP's PDO returns database columns as strings by default, so JSON responses
// contain "id": "5807" instead of "id": 5807. This method accepts both formats
// for maximum compatibility:
// - String format: {"id": "5807", "cid": "0", "cstatus": "1"} (current PHP behavior)
// - Numeric format: {"id": 5807, "cid": 0, "cstatus": 1} (correct JSON, future-proof)
// - Mixed format: {"id": 5807, "cid": "0", "cstatus": 1} (also supported)
//
// Invalid or missing values default to safe values:
// - ID: 0 (will fail validation elsewhere if truly invalid)
// - CID: 0 (valid - no campaign)
// - CStatus: 1 (valid - running campaign, safe default)
// - Priority: 0 (valid - normal queue priority)
func (qm *QueuedMessage) UnmarshalJSON(data []byte) error {
	// Use type alias to avoid infinite recursion
	type Alias QueuedMessage

	// Temporary struct with interface{} for flexible numeric fields
	aux := &struct {
		ID       interface{} `json:"id"`
		CID      interface{} `json:"cid"`
		CStatus  interface{} `json:"cstatus"`
		Priority interface{} `json:"priority"`
		*Alias
	}{
		Alias: (*Alias)(qm),
	}

	// Parse JSON into temporary struct
	if err := json.Unmarshal(data, &aux); err != nil {
		return err
	}

	// Convert flexible types to integers with defaults
	qm.ID = convertToInt(aux.ID, 0)
	qm.CID = convertToInt(aux.CID, 0)
	qm.CStatus = convertToInt(aux.CStatus, 1) // Default to 1 (running) for safety
	qm.Priority = convertToInt(aux.Priority, 0)

	return nil
}

// convertToInt converts various types to int with a fallback default value.
// Handles the following input types:
// - int: returned as-is
// - float64: converted to int (JSON numbers parse as float64)
// - string: parsed with strconv.Atoi, defaults on error
// - nil/other: returns defaultVal
//
// This function is used by UnmarshalJSON to handle PHP's inconsistent
// numeric type encoding in JSON responses.
func convertToInt(val interface{}, defaultVal int) int {
	if val == nil {
		return defaultVal
	}

	switch v := val.(type) {
	case int:
		return v
	case float64:
		// JSON numbers are decoded as float64 by default
		return int(v)
	case string:
		// Handle string numbers from PHP
		if v == "" {
			return defaultVal
		}
		if parsed, err := strconv.Atoi(v); err == nil {
			return parsed
		}
		// Invalid string, return default
		return defaultVal
	default:
		// Unknown type, return default
		return defaultVal
	}
}

// MessageQueue manages a queue of messages to be sent for a session
// Thread-safe with maximum 100 message limit per session
type MessageQueue struct {
	Messages   []QueuedMessage `json:"-"`
	MaxSize    int             `json:"-"` // Maximum queue size (100 messages)
	Mu         sync.RWMutex    `json:"-"`
	messageSet map[string]bool `json:"-"` // O(1) duplicate detection using "ID:CID" as key
}

// SessionMessageState tracks the sending state for a session
type SessionMessageState struct {
	LastSentTime     time.Time  // When the last message was sent
	IsSending        bool       // Whether a message is currently being sent
	SendingStartTime time.Time  // When the current send operation started (for timeout detection)
	SendMutex        sync.Mutex // Ensures one-at-a-time sending per session
	NextDelaySeconds int        // Pre-calculated delay for stable timing (set once per message cycle)
	DelayInitialized bool       // Whether NextDelaySeconds has been set for current cycle
}

// Session represents a WhatsApp session
type Session struct {
	ID             string            `json:"id"`
	Client         *whatsmeow.Client `json:"-"`
	DeviceStore    *store.Device     `json:"-"`
	DeviceJID      *types.JID        `json:"-"`
	EventHandlerID uint32            `json:"-"`
	Cache          *SessionCache     `json:"cache"`
	Status         SessionStatus     `json:"status"`
	CreatedAt      time.Time         `json:"created_at"`
	LastActivity   time.Time         `json:"last_activity"`
	DBPath         string            `json:"-"`

	// Synchronization
	Mu         sync.RWMutex `json:"-"` // Protects Status and LastActivity from concurrent access
	FetchMutex sync.Mutex   `json:"-"` // Prevents concurrent message fetches

	// Message queue and sending state
	MessageQueue *MessageQueue        `json:"-"`
	MessageState *SessionMessageState `json:"-"`

	// Campaign pause state tracking (survives stop/start but not restart)
	PausedCampaigns map[int]bool `json:"-"` // map[CID]isPaused - for immediate pause response
	CampaignMutex   sync.RWMutex `json:"-"` // Protects PausedCampaigns from concurrent access

	// FirstConnectionNotified tracks if we've sent the initial connection notification
	// Prevents duplicate link success notifications on reconnection (Node.js behavior)
	FirstConnectionNotified bool `json:"-"`

	// QueueDB is the persistent database connection for message queue operations
	// Avoids opening/closing connection on every operation (connection pooling)
	QueueDB *sql.DB `json:"-"`
}

// SessionCache holds the session metadata from the client
type SessionCache struct {
	SystemToken string `json:"system_token"`
	SiteUnique  string `json:"site_unique"`
	SiteURL     string `json:"site_url"`
	APIToken    string `json:"api_token"`
	WSID        string `json:"wsid"`
	UID         string `json:"uid"`
	Hash        string `json:"hash"`
	Unique      string `json:"unique"`
	OS          string `json:"os"`

	// Account settings from PHP (updated via /whatsapp/messages response)
	ReceiveChats int `json:"receive_chats"` // Whether to receive chats (1=yes, 2=no)
	RandomSend   int `json:"random_send"`   // Delay type (1=random, 2=fixed)
	RandomMin    int `json:"random_min"`    // Minimum delay in seconds
	RandomMax    int `json:"random_max"`    // Maximum delay in seconds
}

// CreateSessionRequest represents the request to create a new session
type CreateSessionRequest struct {
	SystemToken string `json:"system_token" form:"system_token" binding:"required"`
	SiteUnique  string `json:"site_unique" form:"site_unique" binding:"required"`
	SiteURL     string `json:"site_url" form:"site_url" binding:"required"`
	APIToken    string `json:"api_token" form:"api_token" binding:"required"`
	WSID        string `json:"wsid" form:"wsid" binding:"required"`
	OS          string `json:"os" form:"os" binding:"required"`
	Unique      string `json:"unique" form:"unique" binding:"required"`
	UID         string `json:"uid" form:"uid" binding:"required"`
	Hash        string `json:"hash" form:"hash" binding:"required"`
}

// SendMessageRequest represents the request to send a message
type SendMessageRequest struct {
	ID        string `json:"id" form:"id" binding:"required"`
	Recipient string `json:"recipient" form:"recipient" binding:"required"`
	Message   string `json:"message" form:"message" binding:"required"`
	Type      string `json:"type" form:"type"`
}

// UpdateSessionRequest represents the request to update session settings
type UpdateSessionRequest struct {
	WSID         string `json:"wsid" form:"wsid" binding:"required"`
	ReceiveChats string `json:"receive_chats" form:"receive_chats" binding:"required"` // Accepts string or number, normalized to string
	RandomSend   string `json:"random_send" form:"random_send" binding:"required"`     // Accepts string or number, normalized to string
	RandomMin    string `json:"random_min" form:"random_min" binding:"required"`       // Accepts string or number, normalized to string
	RandomMax    string `json:"random_max" form:"random_max" binding:"required"`       // Accepts string or number, normalized to string
}

// UnmarshalJSON implements custom JSON unmarshaling for UpdateSessionRequest
// to handle PHP's inconsistent numeric field encoding (string or number).
//
// PHP frontend forms can send POST data with numeric fields as either strings or numbers:
// - Form submission: {"receive_chats": "2"} (string from form input)
// - JavaScript API: {"receive_chats": 2} (number from JSON.stringify)
// - Mixed format: {"receive_chats": 2, "random_send": "1"} (both types in same payload)
//
// This method normalizes all numeric fields to strings so that the existing
// parseIntSafe() conversion logic throughout the codebase continues to work unchanged.
func (usr *UpdateSessionRequest) UnmarshalJSON(data []byte) error {
	// Use type alias to avoid infinite recursion
	type Alias UpdateSessionRequest

	// Temporary struct with interface{} for flexible numeric fields
	aux := &struct {
		ReceiveChats interface{} `json:"receive_chats"`
		RandomSend   interface{} `json:"random_send"`
		RandomMin    interface{} `json:"random_min"`
		RandomMax    interface{} `json:"random_max"`
		*Alias
	}{
		Alias: (*Alias)(usr),
	}

	// Parse JSON into temporary struct
	if err := json.Unmarshal(data, &aux); err != nil {
		return err
	}

	// Normalize all numeric fields to strings (so parseIntSafe works)
	usr.ReceiveChats = convertToString(aux.ReceiveChats)
	usr.RandomSend = convertToString(aux.RandomSend)
	usr.RandomMin = convertToString(aux.RandomMin)
	usr.RandomMax = convertToString(aux.RandomMax)

	return nil
}

// MessageType represents different types of WhatsApp messages
type MessageType int

const (
	MessageTypeUnknown MessageType = iota
	MessageTypeText
	MessageTypeImage
	MessageTypeVideo
	MessageTypeAudio
	MessageTypeDocument
	MessageTypeLocation
)

// IncomingMessage represents a message received from WhatsApp
type IncomingMessage struct {
	SessionID   string      `json:"session_id"`
	From        string      `json:"from"`
	Chat        string      `json:"chat"`
	MessageID   string      `json:"message_id"`
	Timestamp   time.Time   `json:"timestamp"`
	IsGroup     bool        `json:"is_group"`
	MessageType MessageType `json:"message_type"`
	Content     string      `json:"content"`
	MediaPath   string      `json:"media_path,omitempty"`
}

// APIResponse represents a standard API response format
type APIResponse struct {
	Status  int         `json:"status"`
	Message interface{} `json:"message"`
	Data    interface{} `json:"data"`
}

// SessionEvent represents an event for the event-driven processor
type SessionEvent struct {
	SessionID string    `json:"session_id"`
	EventType string    `json:"event_type"` // "messages_added", "scheduled_send", "retry_send", "periodic_cleanup"
	Timestamp time.Time `json:"timestamp"`
}

// PHPMessagesResponse represents the response from PHP /whatsapp/messages endpoint
// The Data field uses json.RawMessage to handle both success (object) and error (bool) responses
type PHPMessagesResponse struct {
	Status  int             `json:"status"`
	Message interface{}     `json:"message"` // Can be string or bool
	Data    json.RawMessage `json:"data"`    // Can be object or bool
}

// PHPMessagesData represents the data field when the response is successful
//
// IMPORTANT: PHP is inconsistent - can send numeric fields as EITHER strings OR numbers
// - Old behavior: {"receive_chats": "2", "random_send": "1", ...} (strings)
// - New behavior: {"receive_chats": 2, "random_send": 1, ...} (numbers)
// - Mixed behavior: {"receive_chats": 2, "random_send": "1", ...} (mixed)
//
// Custom UnmarshalJSON normalizes everything to strings for parseIntSafe() compatibility.
type PHPMessagesData struct {
	SiteName     string          `json:"site_name"`
	ReceiveChats string          `json:"receive_chats"` // Accepts string or number, normalized to string
	RandomSend   string          `json:"random_send"`   // Accepts string or number, normalized to string
	RandomMin    string          `json:"random_min"`    // Accepts string or number, normalized to string
	RandomMax    string          `json:"random_max"`    // Accepts string or number, normalized to string
	Messages     []QueuedMessage `json:"messages"`
}

// UnmarshalJSON implements custom JSON unmarshaling for PHPMessagesData
// to handle PHP's inconsistent numeric field encoding (string or number).
//
// PHP can send config fields as either strings or numbers depending on version/context:
// - String format: {"receive_chats": "2"} (original behavior)
// - Number format: {"receive_chats": 2} (newer behavior)
// - Mixed format: {"receive_chats": 2, "random_send": "1"} (also occurs)
//
// This method normalizes all numeric fields to strings so that the existing
// parseIntSafe() conversion logic in fetcher.go continues to work unchanged.
func (pmd *PHPMessagesData) UnmarshalJSON(data []byte) error {
	// Use type alias to avoid infinite recursion
	type Alias PHPMessagesData

	// Temporary struct with interface{} for flexible numeric fields
	aux := &struct {
		ReceiveChats interface{} `json:"receive_chats"`
		RandomSend   interface{} `json:"random_send"`
		RandomMin    interface{} `json:"random_min"`
		RandomMax    interface{} `json:"random_max"`
		*Alias
	}{
		Alias: (*Alias)(pmd),
	}

	// Parse JSON into temporary struct
	if err := json.Unmarshal(data, &aux); err != nil {
		return err
	}

	// Normalize all numeric fields to strings (so parseIntSafe works)
	pmd.ReceiveChats = convertToString(aux.ReceiveChats)
	pmd.RandomSend = convertToString(aux.RandomSend)
	pmd.RandomMin = convertToString(aux.RandomMin)
	pmd.RandomMax = convertToString(aux.RandomMax)

	return nil
}

// convertToString converts an interface{} value to a string representation.
// This helper normalizes PHP's inconsistent numeric field encoding (string or number)
// into consistent string format for compatibility with parseIntSafe().
//
// Conversion rules:
// - string: returned as-is
// - int/int8/int16/int32/int64: converted to decimal string
// - uint/uint8/uint16/uint32/uint64: converted to decimal string
// - float32/float64: converted to decimal string (e.g., "2.5")
// - bool: "1" for true, "0" for false
// - nil: empty string ""
// - other types: empty string "" (safe fallback)
func convertToString(val interface{}) string {
	if val == nil {
		return ""
	}

	switch v := val.(type) {
	case string:
		return v

	case int:
		return strconv.Itoa(v)
	case int8:
		return strconv.FormatInt(int64(v), 10)
	case int16:
		return strconv.FormatInt(int64(v), 10)
	case int32:
		return strconv.FormatInt(int64(v), 10)
	case int64:
		return strconv.FormatInt(v, 10)

	case uint:
		return strconv.FormatUint(uint64(v), 10)
	case uint8:
		return strconv.FormatUint(uint64(v), 10)
	case uint16:
		return strconv.FormatUint(uint64(v), 10)
	case uint32:
		return strconv.FormatUint(uint64(v), 10)
	case uint64:
		return strconv.FormatUint(v, 10)

	case float32:
		return strconv.FormatFloat(float64(v), 'f', -1, 32)
	case float64:
		return strconv.FormatFloat(v, 'f', -1, 64)

	case bool:
		if v {
			return "1"
		}
		return "0"

	default:
		// For any unexpected types, return empty string as safe fallback
		return ""
	}
}

// MessageSendStatus represents the status codes for message sending
type MessageSendStatus int

const (
	StatusPending MessageSendStatus = 1 // Message is queued
	StatusSending MessageSendStatus = 2 // Message is being sent
	StatusSent    MessageSendStatus = 3 // Message sent successfully
	StatusFailed  MessageSendStatus = 4 // Message send failed
)

// NewMessageQueue creates a new message queue with default max size of 100
func NewMessageQueue() *MessageQueue {
	return &MessageQueue{
		Messages:   make([]QueuedMessage, 0),
		MaxSize:    100, // Legacy Node.js server limit
		messageSet: make(map[string]bool),
	}
}

// messageKey generates a unique key for a message (ID:CID)
func messageKey(id, cid int) string {
	return fmt.Sprintf("%d:%d", id, cid)
}

// NewSessionMessageState creates a new session message state
func NewSessionMessageState() *SessionMessageState {
	return &SessionMessageState{
		LastSentTime: time.Time{}, // Zero time means never sent
		IsSending:    false,
	}
}

// MessageQueue methods

// Add adds a message to the queue
// Returns false if queue is full or duplicate, true if added successfully
func (mq *MessageQueue) Add(message QueuedMessage) bool {
	mq.Mu.Lock()
	defer mq.Mu.Unlock()

	if len(mq.Messages) >= mq.MaxSize {
		return false // Queue is full
	}

	// Ensure messageSet is initialized (safety for pre-existing queues)
	if mq.messageSet == nil {
		mq.messageSet = make(map[string]bool)
		// Rebuild set from existing messages
		for _, msg := range mq.Messages {
			mq.messageSet[messageKey(msg.ID, msg.CID)] = true
		}
	}

	// O(1) duplicate check using map
	key := messageKey(message.ID, message.CID)
	if mq.messageSet[key] {
		return false // Duplicate found, don't add
	}

	mq.Messages = append(mq.Messages, message)
	mq.messageSet[key] = true
	return true
}

// AddBatch adds multiple messages to the queue
// Returns the number of messages successfully added
// Uses O(1) duplicate detection via internal map
func (mq *MessageQueue) AddBatch(messages []QueuedMessage) int {
	mq.Mu.Lock()
	defer mq.Mu.Unlock()

	// Ensure messageSet is initialized (safety for pre-existing queues)
	if mq.messageSet == nil {
		mq.messageSet = make(map[string]bool)
		// Rebuild set from existing messages
		for _, msg := range mq.Messages {
			mq.messageSet[messageKey(msg.ID, msg.CID)] = true
		}
	}

	added := 0
	for _, message := range messages {
		if len(mq.Messages) >= mq.MaxSize {
			break // Queue is full
		}

		// O(1) duplicate check using map
		key := messageKey(message.ID, message.CID)
		if !mq.messageSet[key] {
			mq.Messages = append(mq.Messages, message)
			mq.messageSet[key] = true
			added++
		}
	}

	return added
}

// GetNext gets the next message from the queue (FIFO)
// Returns nil if queue is empty
func (mq *MessageQueue) GetNext() *QueuedMessage {
	mq.Mu.Lock()
	defer mq.Mu.Unlock()

	if len(mq.Messages) == 0 {
		return nil
	}

	message := mq.Messages[0]
	mq.Messages = mq.Messages[1:]

	// Remove from tracking map
	key := messageKey(message.ID, message.CID)
	delete(mq.messageSet, key)

	return &message
}

// GetBatch gets multiple messages from the queue (FIFO)
// Returns up to 'count' messages, or fewer if queue has less
func (mq *MessageQueue) GetBatch(count int) []QueuedMessage {
	mq.Mu.Lock()
	defer mq.Mu.Unlock()

	if len(mq.Messages) == 0 {
		return []QueuedMessage{}
	}

	if count > len(mq.Messages) {
		count = len(mq.Messages)
	}

	batch := make([]QueuedMessage, count)
	copy(batch, mq.Messages[:count])

	// Remove from tracking map
	for _, msg := range mq.Messages[:count] {
		key := messageKey(msg.ID, msg.CID)
		delete(mq.messageSet, key)
	}

	mq.Messages = mq.Messages[count:]

	return batch
}

// Remove removes a specific message from the queue by ID
// Returns true if message was found and removed, false otherwise
func (mq *MessageQueue) Remove(messageID int) bool {
	mq.Mu.Lock()
	defer mq.Mu.Unlock()

	for i, msg := range mq.Messages {
		if msg.ID == messageID {
			// Remove from tracking map
			key := messageKey(msg.ID, msg.CID)
			delete(mq.messageSet, key)

			// Remove message at index i
			mq.Messages = append(mq.Messages[:i], mq.Messages[i+1:]...)
			return true
		}
	}

	return false
}

// RemoveMessage removes a specific message object from the queue
// Returns true if message was found and removed, false otherwise
// Uses both ID and CID for precise matching (same ID can exist with different CID)
func (mq *MessageQueue) RemoveMessage(message QueuedMessage) bool {
	mq.Mu.Lock()
	defer mq.Mu.Unlock()

	// Remove from tracking map (O(1) operation)
	key := messageKey(message.ID, message.CID)
	delete(mq.messageSet, key)

	for i, msg := range mq.Messages {
		if msg.ID == message.ID && msg.CID == message.CID {
			// Remove message at index i
			mq.Messages = append(mq.Messages[:i], mq.Messages[i+1:]...)
			return true
		}
	}

	return false
}

// Size returns the current number of messages in the queue
func (mq *MessageQueue) Size() int {
	mq.Mu.RLock()
	defer mq.Mu.RUnlock()

	return len(mq.Messages)
}

// IsFull returns true if the queue has reached its maximum capacity
func (mq *MessageQueue) IsFull() bool {
	mq.Mu.RLock()
	defer mq.Mu.RUnlock()

	return len(mq.Messages) >= mq.MaxSize
}

// AvailableCapacity returns how many more messages can be added to the queue
// Thread-safe: uses read lock to get consistent value
func (mq *MessageQueue) AvailableCapacity() int {
	mq.Mu.RLock()
	defer mq.Mu.RUnlock()

	available := mq.MaxSize - len(mq.Messages)
	if available < 0 {
		return 0
	}
	return available
}

// IsEmpty returns true if the queue has no messages
func (mq *MessageQueue) IsEmpty() bool {
	mq.Mu.RLock()
	defer mq.Mu.RUnlock()

	return len(mq.Messages) == 0
}

// GetAvailableSpace returns how many more messages can be added to the queue
func (mq *MessageQueue) GetAvailableSpace() int {
	mq.Mu.RLock()
	defer mq.Mu.RUnlock()

	return mq.MaxSize - len(mq.Messages)
}

// PeekNext returns the next message without removing it from the queue
// Returns nil if queue is empty
func (mq *MessageQueue) PeekNext() *QueuedMessage {
	mq.Mu.RLock()
	defer mq.Mu.RUnlock()

	if len(mq.Messages) == 0 {
		return nil
	}

	// Return a copy to prevent external modification
	message := mq.Messages[0]
	return &message
}

// GetAll returns a copy of all messages in the queue without modifying the queue
func (mq *MessageQueue) GetAll() []QueuedMessage {
	mq.Mu.RLock()
	defer mq.Mu.RUnlock()

	if len(mq.Messages) == 0 {
		return []QueuedMessage{}
	}

	// Return a copy to prevent external modification
	result := make([]QueuedMessage, len(mq.Messages))
	copy(result, mq.Messages)
	return result
}

// Clear removes all messages from the queue
func (mq *MessageQueue) Clear() {
	mq.Mu.Lock()
	defer mq.Mu.Unlock()

	mq.Messages = mq.Messages[:0]         // Clear slice but keep capacity
	mq.messageSet = make(map[string]bool) // Reset tracking map
}

// SetMaxSize sets the maximum queue size
// If new size is smaller than current queue, older messages are removed
func (mq *MessageQueue) SetMaxSize(maxSize int) {
	mq.Mu.Lock()
	defer mq.Mu.Unlock()

	if maxSize < 1 {
		maxSize = 1 // Minimum queue size
	}

	mq.MaxSize = maxSize

	// If current queue exceeds new max size, remove oldest messages
	if len(mq.Messages) > maxSize {
		// Remove from tracking map for messages being dropped
		removeCount := len(mq.Messages) - maxSize
		for _, msg := range mq.Messages[:removeCount] {
			key := messageKey(msg.ID, msg.CID)
			delete(mq.messageSet, key)
		}
		mq.Messages = mq.Messages[removeCount:]
	}
}

// GetMaxSize returns the maximum queue capacity
func (mq *MessageQueue) GetMaxSize() int {
	mq.Mu.RLock()
	defer mq.Mu.RUnlock()

	return mq.MaxSize
}

// Contains checks if a message with given ID and CID exists in the queue
// Uses O(1) lookup via internal map for efficient checking
func (mq *MessageQueue) Contains(id, cid int) bool {
	mq.Mu.RLock()
	defer mq.Mu.RUnlock()

	// Ensure messageSet is initialized (safety for pre-existing queues)
	if mq.messageSet == nil {
		return false
	}

	key := messageKey(id, cid)
	return mq.messageSet[key]
}

// UpdateCampaignStatus updates the CStatus field for all messages with matching CID
// Used when stop/start campaign is called to update already-queued messages
// Returns the number of messages updated
func (mq *MessageQueue) UpdateCampaignStatus(cid int, newStatus int) int {
	mq.Mu.Lock()
	defer mq.Mu.Unlock()

	updated := 0
	for i := range mq.Messages {
		if mq.Messages[i].CID == cid {
			mq.Messages[i].CStatus = newStatus
			updated++
		}
	}
	return updated
}

// SessionMessageState methods

// CanSendNow checks if enough time has passed since last send based on account settings
func (sms *SessionMessageState) CanSendNow(randomSend, randomMin, randomMax int) bool {
	sms.SendMutex.Lock()
	defer sms.SendMutex.Unlock()

	if sms.IsSending {
		return false // Already sending a message
	}

	if sms.LastSentTime.IsZero() {
		return true // First message can be sent immediately
	}

	requiredDelay := calculateDelay(randomSend, randomMin, randomMax)
	timeSinceLastSend := time.Since(sms.LastSentTime)

	return timeSinceLastSend >= requiredDelay
}

// SetSending marks the session as currently sending a message
// NOTE: Does NOT modify LastSentTime - use SetLastSentTime() for that
func (sms *SessionMessageState) SetSending(sending bool) {
	sms.SendMutex.Lock()
	defer sms.SendMutex.Unlock()

	sms.IsSending = sending
	if sending {
		sms.SendingStartTime = time.Now()
		// DO NOT set LastSentTime here - it should only be set after successful message processing
	}
}

// InitializeBaseline atomically checks if LastSentTime is zero and sets it
// Returns true if baseline was set (first message), false if already set
// This prevents race conditions when multiple threads check LastSentTime
func (sms *SessionMessageState) InitializeBaseline() bool {
	sms.SendMutex.Lock()
	defer sms.SendMutex.Unlock()

	if sms.LastSentTime.IsZero() {
		sms.LastSentTime = time.Now()
		return true
	}
	return false
}

// TrySetSending atomically checks if not sending and sets sending to true
// Returns true if successfully set to sending, false if already sending
// This prevents race conditions where multiple threads check IsSending separately
// NOTE: Does NOT modify LastSentTime - that's set AFTER successful send via SetLastSentTime()
func (sms *SessionMessageState) TrySetSending() bool {
	sms.SendMutex.Lock()
	defer sms.SendMutex.Unlock()

	if sms.IsSending {
		return false // Already sending
	}

	sms.IsSending = true
	sms.SendingStartTime = time.Now()
	// DO NOT set LastSentTime here - it should only be set after successful message processing
	return true
}

// SetLastSentTime safely updates the last sent time
// Call this AFTER message has been successfully processed and reported
func (sms *SessionMessageState) SetLastSentTime(t time.Time) {
	sms.SendMutex.Lock()
	defer sms.SendMutex.Unlock()
	sms.LastSentTime = t
}

// InitializeNextDelay calculates and stores the delay for the next message cycle
// Uses crypto/rand for secure random number generation
// Returns the calculated delay in seconds
func (sms *SessionMessageState) InitializeNextDelay(randomSend, randomMin, randomMax int) int {
	sms.SendMutex.Lock()
	defer sms.SendMutex.Unlock()

	if randomSend == 1 && randomMax > randomMin {
		// Secure random delay between min and max
		delayRange := randomMax - randomMin + 1
		sms.NextDelaySeconds = randomMin + secureRandomInt(delayRange)
	} else {
		// Fixed delay - use default
		sms.NextDelaySeconds = 3
	}
	sms.DelayInitialized = true
	return sms.NextDelaySeconds
}

// GetNextDelay returns the pre-calculated delay for this message cycle
// If not initialized, returns default 3 seconds
func (sms *SessionMessageState) GetNextDelay() time.Duration {
	sms.SendMutex.Lock()
	defer sms.SendMutex.Unlock()

	if !sms.DelayInitialized || sms.NextDelaySeconds <= 0 {
		return 3 * time.Second // Default
	}
	return time.Duration(sms.NextDelaySeconds) * time.Second
}

// ResetDelayForNextCycle resets the delay initialization flag for the next message
// Call this after a message is successfully processed
func (sms *SessionMessageState) ResetDelayForNextCycle() {
	sms.SendMutex.Lock()
	defer sms.SendMutex.Unlock()
	sms.DelayInitialized = false
}

// secureRandomInt generates a cryptographically secure random int in [0, max)
// Uses crypto/rand for unpredictable, secure random numbers
func secureRandomInt(max int) int {
	if max <= 0 {
		return 0
	}
	// Use crypto/rand for secure random generation
	n, err := cryptoRand.Int(cryptoRand.Reader, big.NewInt(int64(max)))
	if err != nil {
		// Fallback to time-based if crypto/rand fails (shouldn't happen)
		return int(time.Now().UnixNano() % int64(max))
	}
	return int(n.Int64())
}

// calculateDelay calculates the delay based on account settings
// randomSend: 1=random delay, 2=fixed delay
// randomMin/randomMax: delay range in seconds
func calculateDelay(randomSend, randomMin, randomMax int) time.Duration {
	if randomSend == 1 && randomMax > randomMin {
		// Random delay between min and max using crypto/rand
		delayRange := randomMax - randomMin + 1
		randomOffset := secureRandomInt(delayRange)
		delaySeconds := randomMin + randomOffset
		return time.Duration(delaySeconds) * time.Second
	}

	// Fixed delay or invalid settings - use default
	return 3 * time.Second
}

// Campaign pause/resume methods for Session

// PauseCampaign marks a campaign as paused (will skip messages during processing)
// This state is lost on server restart but recovered from CStatus field in messages
func (s *Session) PauseCampaign(cid int) {
	s.CampaignMutex.Lock()
	defer s.CampaignMutex.Unlock()

	if s.PausedCampaigns == nil {
		s.PausedCampaigns = make(map[int]bool)
	}
	s.PausedCampaigns[cid] = true
}

// ResumeCampaign marks a campaign as active (will process messages normally)
func (s *Session) ResumeCampaign(cid int) {
	s.CampaignMutex.Lock()
	defer s.CampaignMutex.Unlock()

	delete(s.PausedCampaigns, cid)
}

// IsCampaignPaused checks if a campaign is currently paused
// Returns false for non-existent campaigns (default to active)
func (s *Session) IsCampaignPaused(cid int) bool {
	s.CampaignMutex.RLock()
	defer s.CampaignMutex.RUnlock()

	return s.PausedCampaigns[cid]
}
