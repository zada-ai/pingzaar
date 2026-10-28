package whatsapp

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/sirupsen/logrus"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"whatsapp-server/pkg/types"
)

// TestFetchMessagesOnDemand_WithStringIDs tests fetching messages with PHP's string-based IDs
func TestFetchMessagesOnDemand_WithStringIDs(t *testing.T) {
	// Create mock PHP server that returns string IDs (real PHP behavior)
	mockServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Verify query parameters
		assert.Equal(t, "test-token", r.URL.Query().Get("system_token"))
		assert.Equal(t, "test-uid", r.URL.Query().Get("uid"))
		assert.Equal(t, "test-hash", r.URL.Query().Get("hash"))
		assert.Equal(t, "test-unique", r.URL.Query().Get("unique"))

		// Return PHP response with string IDs
		response := map[string]interface{}{
			"status":  200,
			"message": false,
			"data": map[string]interface{}{
				"site_name":     "SMS",
				"receive_chats": "2",
				"random_send":   "1",
				"random_min":    "1",
				"random_max":    "5",
				"messages": []map[string]interface{}{
					{
						"id":       "5807",
						"cid":      "0",
						"cstatus":  "1",
						"uid":      "1",
						"wid":      "5213111830210:1@s.whatsapp.net",
						"unique":   "test-unique",
						"phone":    "+5213349426465",
						"message":  `{"text":"This is a test message"}`,
						"priority": "1",
					},
					{
						"id":      "5808",
						"cid":     "123",
						"phone":   "+1234567890",
						"message": `{"text":"Second message"}`,
						"wid":     "1234567890:1@s.whatsapp.net",
					},
				},
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	}))
	defer mockServer.Close()

	// Create test session
	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SystemToken: "test-token",
			SiteURL:     mockServer.URL,
			UID:         "test-uid",
			Hash:        "test-hash",
			Unique:      "test-unique",
		},
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}

	// Create session manager and fetcher
	logger := logrus.New()
	logger.SetLevel(logrus.DebugLevel)

	sessionMgr := &SessionManager{
		logger: logger,
	}
	sessionMgr.sessions.Store(session.ID, session)

	fetcher := NewMessageFetcher(sessionMgr, logger)

	// Execute fetch
	err := fetcher.FetchMessagesOnDemand(session)

	// Verify results
	require.NoError(t, err, "Fetch should succeed with string IDs")
	assert.Equal(t, 2, session.MessageQueue.Size(), "Should have 2 messages in queue")

	// Verify first message was parsed correctly
	msg1 := session.MessageQueue.GetNext()
	require.NotNil(t, msg1)
	assert.Equal(t, 5807, msg1.ID, "ID should be converted from string to int")
	assert.Equal(t, 0, msg1.CID, "CID should be converted from string to int")
	assert.Equal(t, 1, msg1.Priority, "Priority should be converted from string to int")
	assert.Equal(t, "+5213349426465", msg1.Phone)
	assert.Equal(t, `{"text":"This is a test message"}`, msg1.Message)
	assert.Equal(t, "5213111830210:1@s.whatsapp.net", msg1.WID)

	// Verify second message
	msg2 := session.MessageQueue.GetNext()
	require.NotNil(t, msg2)
	assert.Equal(t, 5808, msg2.ID)
	assert.Equal(t, 123, msg2.CID)
	assert.Equal(t, 0, msg2.Priority, "Missing priority should default to 0")
	assert.Equal(t, "+1234567890", msg2.Phone)

	// Verify session cache was updated
	assert.Equal(t, 2, session.Cache.ReceiveChats)
	assert.Equal(t, 1, session.Cache.RandomSend)
	assert.Equal(t, 1, session.Cache.RandomMin)
	assert.Equal(t, 5, session.Cache.RandomMax)
}

// TestFetchMessagesOnDemand_WithNumericIDs tests fetching with proper numeric IDs
func TestFetchMessagesOnDemand_WithNumericIDs(t *testing.T) {
	// Create mock PHP server that returns numeric IDs (correct format)
	mockServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		response := map[string]interface{}{
			"status":  200,
			"message": false,
			"data": map[string]interface{}{
				"site_name":     "SMS",
				"receive_chats": "2",
				"random_send":   "1",
				"random_min":    "1",
				"random_max":    "5",
				"messages": []map[string]interface{}{
					{
						"id":       5807,
						"cid":      0,
						"phone":    "+5213349426465",
						"message":  `{"text":"Test"}`,
						"priority": 1,
						"wid":      "test@s.whatsapp.net",
					},
				},
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	}))
	defer mockServer.Close()

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SystemToken: "test-token",
			SiteURL:     mockServer.URL,
			UID:         "test-uid",
			Hash:        "test-hash",
			Unique:      "test-unique",
		},
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel) // Reduce log noise

	sessionMgr := &SessionManager{
		logger: logger,
	}
	sessionMgr.sessions.Store(session.ID, session)

	fetcher := NewMessageFetcher(sessionMgr, logger)
	err := fetcher.FetchMessagesOnDemand(session)

	require.NoError(t, err, "Fetch should succeed with numeric IDs")
	assert.Equal(t, 1, session.MessageQueue.Size())

	msg := session.MessageQueue.GetNext()
	require.NotNil(t, msg)
	assert.Equal(t, 5807, msg.ID)
	assert.Equal(t, 0, msg.CID)
	assert.Equal(t, 1, msg.Priority)
}

// TestFetchMessagesOnDemand_RealWorldPHPResponse tests with exact error log format
func TestFetchMessagesOnDemand_RealWorldPHPResponse(t *testing.T) {
	// This is the exact PHP response format from the error logs
	mockServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Simulate exact PHP response that was causing the error
		responseJSON := `{
			"status": 200,
			"message": false,
			"data": {
				"site_name": "SMS",
				"receive_chats": "2",
				"random_send": "1",
				"random_min": "1",
				"random_max": "5",
				"messages": [
					{
						"id": "5807",
						"cid": "0",
						"cstatus": "1",
						"uid": "1",
						"wid": "5213111830210:1@s.whatsapp.net",
						"unique": "1760047248c4ca4238a0b923820dcc509a6f75849b68e83090718c4",
						"phone": "+5213349426465",
						"message": "{\"text\":\"This is a test message\"}"
					}
				]
			}
		}`

		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(responseJSON))
	}))
	defer mockServer.Close()

	session := &types.Session{
		ID: "1760847248c4ca4238a0b923820dcc509a6f75849b68e83090718c4",
		Cache: &types.SessionCache{
			SystemToken: "test-token",
			SiteURL:     mockServer.URL,
			UID:         "test-uid",
			Hash:        "test-hash",
			Unique:      "1760047248c4ca4238a0b923820dcc509a6f75849b68e83090718c4",
		},
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	sessionMgr := &SessionManager{
		logger: logger,
	}
	sessionMgr.sessions.Store(session.ID, session)

	fetcher := NewMessageFetcher(sessionMgr, logger)
	err := fetcher.FetchMessagesOnDemand(session)

	// This should now succeed (was failing before the fix)
	require.NoError(t, err, "Should parse real PHP response without error")
	assert.Equal(t, 1, session.MessageQueue.Size(), "Should have 1 message")

	msg := session.MessageQueue.GetNext()
	require.NotNil(t, msg, "Message should be parsed")
	assert.Equal(t, 5807, msg.ID, "ID should be 5807")
	assert.Equal(t, 0, msg.CID, "CID should be 0")
	assert.Equal(t, "+5213349426465", msg.Phone)
	assert.Equal(t, `{"text":"This is a test message"}`, msg.Message)
}

// TestFetchMessagesOnDemand_EmptyMessages tests empty message array
func TestFetchMessagesOnDemand_EmptyMessages(t *testing.T) {
	mockServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		response := map[string]interface{}{
			"status":  200,
			"message": false,
			"data": map[string]interface{}{
				"site_name":     "SMS",
				"receive_chats": "2",
				"random_send":   "1",
				"random_min":    "1",
				"random_max":    "5",
				"messages":      []interface{}{}, // Empty array
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	}))
	defer mockServer.Close()

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SystemToken: "test-token",
			SiteURL:     mockServer.URL,
			UID:         "test-uid",
			Hash:        "test-hash",
			Unique:      "test-unique",
		},
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	sessionMgr := &SessionManager{
		logger: logger,
	}
	sessionMgr.sessions.Store(session.ID, session)

	fetcher := NewMessageFetcher(sessionMgr, logger)
	err := fetcher.FetchMessagesOnDemand(session)

	require.NoError(t, err)
	assert.Equal(t, 0, session.MessageQueue.Size(), "Queue should remain empty")
}

// TestFetchMessagesOnDemand_MixedFormats tests mixed string/numeric types
func TestFetchMessagesOnDemand_MixedFormats(t *testing.T) {
	mockServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		response := map[string]interface{}{
			"status":  200,
			"message": false,
			"data": map[string]interface{}{
				"site_name":     "SMS",
				"receive_chats": "2",
				"random_send":   "1",
				"random_min":    "1",
				"random_max":    "5",
				"messages": []map[string]interface{}{
					{
						"id":      5807, // Numeric
						"cid":     "0",  // String
						"phone":   "+1234",
						"message": "{}",
						"wid":     "test@s.whatsapp.net",
					},
					{
						"id":      "5808", // String
						"cid":     123,    // Numeric
						"phone":   "+5678",
						"message": "{}",
						"wid":     "test2@s.whatsapp.net",
					},
				},
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	}))
	defer mockServer.Close()

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SystemToken: "test-token",
			SiteURL:     mockServer.URL,
			UID:         "test-uid",
			Hash:        "test-hash",
			Unique:      "test-unique",
		},
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	sessionMgr := &SessionManager{
		logger: logger,
	}
	sessionMgr.sessions.Store(session.ID, session)

	fetcher := NewMessageFetcher(sessionMgr, logger)
	err := fetcher.FetchMessagesOnDemand(session)

	require.NoError(t, err, "Should handle mixed formats")
	assert.Equal(t, 2, session.MessageQueue.Size())

	msg1 := session.MessageQueue.GetNext()
	assert.Equal(t, 5807, msg1.ID)
	assert.Equal(t, 0, msg1.CID)

	msg2 := session.MessageQueue.GetNext()
	assert.Equal(t, 5808, msg2.ID)
	assert.Equal(t, 123, msg2.CID)
}

// TestFetchMessagesOnDemand_InvalidIDs tests graceful handling of invalid values
func TestFetchMessagesOnDemand_InvalidIDs(t *testing.T) {
	mockServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		response := map[string]interface{}{
			"status":  200,
			"message": false,
			"data": map[string]interface{}{
				"site_name":     "SMS",
				"receive_chats": "2",
				"random_send":   "1",
				"random_min":    "1",
				"random_max":    "5",
				"messages": []map[string]interface{}{
					{
						"id":      "invalid", // Invalid string
						"cid":     "bad",     // Invalid string
						"phone":   "+1234",
						"message": "{}",
						"wid":     "test@s.whatsapp.net",
					},
				},
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	}))
	defer mockServer.Close()

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SystemToken: "test-token",
			SiteURL:     mockServer.URL,
			UID:         "test-uid",
			Hash:        "test-hash",
			Unique:      "test-unique",
		},
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	sessionMgr := &SessionManager{
		logger: logger,
	}
	sessionMgr.sessions.Store(session.ID, session)

	fetcher := NewMessageFetcher(sessionMgr, logger)
	err := fetcher.FetchMessagesOnDemand(session)

	require.NoError(t, err, "Should not error on invalid strings")
	assert.Equal(t, 1, session.MessageQueue.Size())

	msg := session.MessageQueue.GetNext()
	assert.Equal(t, 0, msg.ID, "Invalid ID should default to 0")
	assert.Equal(t, 0, msg.CID, "Invalid CID should default to 0")
}

// TestFetchMessagesOnDemand_NumericConfigValues tests PHP sending numeric config fields
func TestFetchMessagesOnDemand_NumericConfigValues(t *testing.T) {
	// This tests the SECOND error: "cannot unmarshal number into Go struct field...receive_chats of type string"
	mockServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		response := map[string]interface{}{
			"status":  200,
			"message": false,
			"data": map[string]interface{}{
				"site_name":     "SMS",
				"receive_chats": 2, // NUMERIC (not string) - this was causing the error
				"random_send":   1, // NUMERIC
				"random_min":    1, // NUMERIC
				"random_max":    5, // NUMERIC
				"messages": []map[string]interface{}{
					{
						"id":      "5807",
						"cid":     "0",
						"phone":   "+1234567890",
						"message": "{}",
						"wid":     "test@s.whatsapp.net",
					},
				},
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	}))
	defer mockServer.Close()

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SystemToken: "test-token",
			SiteURL:     mockServer.URL,
			UID:         "test-uid",
			Hash:        "test-hash",
			Unique:      "test-unique",
		},
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	sessionMgr := &SessionManager{
		logger: logger,
	}
	sessionMgr.sessions.Store(session.ID, session)

	fetcher := NewMessageFetcher(sessionMgr, logger)
	err := fetcher.FetchMessagesOnDemand(session)

	// This should now succeed (was failing with "cannot unmarshal number into...receive_chats of type string")
	require.NoError(t, err, "Should parse numeric config values without error")
	assert.Equal(t, 1, session.MessageQueue.Size(), "Should have 1 message")

	// Verify config values were parsed and converted correctly
	assert.Equal(t, 2, session.Cache.ReceiveChats, "Numeric 2 should be parsed as int 2")
	assert.Equal(t, 1, session.Cache.RandomSend, "Numeric 1 should be parsed as int 1")
	assert.Equal(t, 1, session.Cache.RandomMin, "Numeric 1 should be parsed as int 1")
	assert.Equal(t, 5, session.Cache.RandomMax, "Numeric 5 should be parsed as int 5")

	// Verify message was also parsed
	msg := session.MessageQueue.GetNext()
	require.NotNil(t, msg)
	assert.Equal(t, 5807, msg.ID)
}

// TestFetchMessagesOnDemand_MixedConfigTypes tests mixed string/numeric config values
func TestFetchMessagesOnDemand_MixedConfigTypes(t *testing.T) {
	mockServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		response := map[string]interface{}{
			"status":  200,
			"message": false,
			"data": map[string]interface{}{
				"site_name":     "SMS",
				"receive_chats": 2,    // Numeric
				"random_send":   "1",  // String
				"random_min":    3,    // Numeric
				"random_max":    "10", // String
				"messages":      []interface{}{},
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	}))
	defer mockServer.Close()

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SystemToken: "test-token",
			SiteURL:     mockServer.URL,
			UID:         "test-uid",
			Hash:        "test-hash",
			Unique:      "test-unique",
		},
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	sessionMgr := &SessionManager{
		logger: logger,
	}
	sessionMgr.sessions.Store(session.ID, session)

	fetcher := NewMessageFetcher(sessionMgr, logger)
	err := fetcher.FetchMessagesOnDemand(session)

	require.NoError(t, err, "Should handle mixed config types")
	assert.Equal(t, 2, session.Cache.ReceiveChats)
	assert.Equal(t, 1, session.Cache.RandomSend)
	assert.Equal(t, 3, session.Cache.RandomMin)
	assert.Equal(t, 10, session.Cache.RandomMax)
}

// TestFetchMessagesOnDemand_FloatConfigValues tests float config values
func TestFetchMessagesOnDemand_FloatConfigValues(t *testing.T) {
	mockServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		response := map[string]interface{}{
			"status":  200,
			"message": false,
			"data": map[string]interface{}{
				"site_name":     "SMS",
				"receive_chats": 2.0,  // Float
				"random_send":   1.5,  // Float with decimal
				"random_min":    3.9,  // Float with decimal
				"random_max":    10.0, // Float
				"messages":      []interface{}{},
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	}))
	defer mockServer.Close()

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SystemToken: "test-token",
			SiteURL:     mockServer.URL,
			UID:         "test-uid",
			Hash:        "test-hash",
			Unique:      "test-unique",
		},
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	sessionMgr := &SessionManager{
		logger: logger,
	}
	sessionMgr.sessions.Store(session.ID, session)

	fetcher := NewMessageFetcher(sessionMgr, logger)
	err := fetcher.FetchMessagesOnDemand(session)

	require.NoError(t, err, "Should handle float config values")
	// parseIntSafe uses fmt.Sscanf which fails on decimal points, returns defaults
	assert.Equal(t, 2, session.Cache.ReceiveChats, "Float 2.0 → string '2' → int 2")
	assert.Equal(t, 1, session.Cache.RandomSend, "Float 1.5 → string '1.5' → default 1 (parseIntSafe fails on decimal)")
	assert.Equal(t, 1, session.Cache.RandomMin, "Float 3.9 → string '3.9' → default 1 (parseIntSafe fails on decimal)")
	assert.Equal(t, 10, session.Cache.RandomMax, "Float 10.0 → string '10' → int 10")
}

// TestFetchMessagesOnDemand_BothErrorScenarios tests the scenario with BOTH original errors
func TestFetchMessagesOnDemand_BothErrorScenarios(t *testing.T) {
	// This combines BOTH error scenarios:
	// 1. String message IDs (first error)
	// 2. Numeric config values (second error)
	mockServer := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		response := map[string]interface{}{
			"status":  200,
			"message": false,
			"data": map[string]interface{}{
				"site_name":     "SMS",
				"receive_chats": 2, // ERROR 2: Numeric config value
				"random_send":   1, // ERROR 2: Numeric config value
				"random_min":    1, // ERROR 2: Numeric config value
				"random_max":    5, // ERROR 2: Numeric config value
				"messages": []map[string]interface{}{
					{
						"id":      "5807", // ERROR 1: String ID
						"cid":     "0",    // ERROR 1: String CID
						"phone":   "+5213349426465",
						"message": `{"text":"This is a test message"}`,
						"wid":     "5213111830210:1@s.whatsapp.net",
					},
				},
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	}))
	defer mockServer.Close()

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SystemToken: "test-token",
			SiteURL:     mockServer.URL,
			UID:         "test-uid",
			Hash:        "test-hash",
			Unique:      "test-unique",
		},
		MessageQueue: types.NewMessageQueue(),
		MessageState: types.NewSessionMessageState(),
	}

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	sessionMgr := &SessionManager{
		logger: logger,
	}
	sessionMgr.sessions.Store(session.ID, session)

	fetcher := NewMessageFetcher(sessionMgr, logger)
	err := fetcher.FetchMessagesOnDemand(session)

	// This should now succeed (was failing with BOTH errors before the comprehensive fix)
	require.NoError(t, err, "Should handle both error scenarios without any errors")

	// Verify config values
	assert.Equal(t, 2, session.Cache.ReceiveChats)
	assert.Equal(t, 1, session.Cache.RandomSend)
	assert.Equal(t, 1, session.Cache.RandomMin)
	assert.Equal(t, 5, session.Cache.RandomMax)

	// Verify message
	assert.Equal(t, 1, session.MessageQueue.Size())
	msg := session.MessageQueue.GetNext()
	require.NotNil(t, msg)
	assert.Equal(t, 5807, msg.ID)
	assert.Equal(t, 0, msg.CID)

	t.Log("✓ Both error scenarios are now fixed!")
}
