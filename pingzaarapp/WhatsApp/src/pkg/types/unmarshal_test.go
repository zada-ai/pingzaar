package types

import (
	"encoding/json"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestQueuedMessage_UnmarshalJSON_StringIDs tests parsing PHP's string-based IDs
func TestQueuedMessage_UnmarshalJSON_StringIDs(t *testing.T) {
	jsonData := `{
		"id": "5807",
		"cid": "0",
		"phone": "+5213349426465",
		"message": "{\"text\":\"This is a test message\"}",
		"priority": "1",
		"wid": "5213111830210:1@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should parse PHP string format without error")
	assert.Equal(t, 5807, msg.ID, "ID should be converted from string to int")
	assert.Equal(t, 0, msg.CID, "CID should be converted from string to int")
	assert.Equal(t, 1, msg.Priority, "Priority should be converted from string to int")
	assert.Equal(t, "+5213349426465", msg.Phone)
	assert.Equal(t, `{"text":"This is a test message"}`, msg.Message)
	assert.Equal(t, "5213111830210:1@s.whatsapp.net", msg.WID)
}

// TestQueuedMessage_UnmarshalJSON_NumericIDs tests parsing correct numeric IDs
func TestQueuedMessage_UnmarshalJSON_NumericIDs(t *testing.T) {
	jsonData := `{
		"id": 5807,
		"cid": 0,
		"phone": "+5213349426465",
		"message": "{\"text\":\"This is a test message\"}",
		"priority": 1,
		"wid": "5213111830210:1@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should parse numeric format without error")
	assert.Equal(t, 5807, msg.ID)
	assert.Equal(t, 0, msg.CID)
	assert.Equal(t, 1, msg.Priority)
	assert.Equal(t, "+5213349426465", msg.Phone)
}

// TestQueuedMessage_UnmarshalJSON_MixedTypes tests mixed string/numeric types
func TestQueuedMessage_UnmarshalJSON_MixedTypes(t *testing.T) {
	jsonData := `{
		"id": 5807,
		"cid": "0",
		"phone": "+5213349426465",
		"message": "{\"text\":\"Test\"}",
		"priority": "1",
		"wid": "test@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should handle mixed types")
	assert.Equal(t, 5807, msg.ID, "Numeric ID should work")
	assert.Equal(t, 0, msg.CID, "String CID should convert")
	assert.Equal(t, 1, msg.Priority, "String priority should convert")
}

// TestQueuedMessage_UnmarshalJSON_Float64Numbers tests JSON numeric parsing
func TestQueuedMessage_UnmarshalJSON_Float64Numbers(t *testing.T) {
	jsonData := `{
		"id": 5807.0,
		"cid": 0.0,
		"phone": "+1234567890",
		"message": "{}",
		"priority": 2.0,
		"wid": "test@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should handle float64 numbers")
	assert.Equal(t, 5807, msg.ID)
	assert.Equal(t, 0, msg.CID)
	assert.Equal(t, 2, msg.Priority)
}

// TestQueuedMessage_UnmarshalJSON_MissingFields tests default values
func TestQueuedMessage_UnmarshalJSON_MissingFields(t *testing.T) {
	jsonData := `{
		"phone": "+1234567890",
		"message": "{}",
		"wid": "test@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should handle missing numeric fields")
	assert.Equal(t, 0, msg.ID, "Missing ID should default to 0")
	assert.Equal(t, 0, msg.CID, "Missing CID should default to 0")
	assert.Equal(t, 0, msg.Priority, "Missing priority should default to 0")
	assert.Equal(t, "+1234567890", msg.Phone)
}

// TestQueuedMessage_UnmarshalJSON_InvalidStrings tests error handling
func TestQueuedMessage_UnmarshalJSON_InvalidStrings(t *testing.T) {
	jsonData := `{
		"id": "invalid",
		"cid": "not-a-number",
		"phone": "+1234567890",
		"message": "{}",
		"priority": "bad",
		"wid": "test@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should not error on invalid strings")
	assert.Equal(t, 0, msg.ID, "Invalid ID string should default to 0")
	assert.Equal(t, 0, msg.CID, "Invalid CID string should default to 0")
	assert.Equal(t, 0, msg.Priority, "Invalid priority string should default to 0")
}

// TestQueuedMessage_UnmarshalJSON_NullValues tests null handling
func TestQueuedMessage_UnmarshalJSON_NullValues(t *testing.T) {
	jsonData := `{
		"id": null,
		"cid": null,
		"phone": "+1234567890",
		"message": "{}",
		"priority": null,
		"wid": "test@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should handle null values")
	assert.Equal(t, 0, msg.ID, "Null ID should default to 0")
	assert.Equal(t, 0, msg.CID, "Null CID should default to 0")
	assert.Equal(t, 0, msg.Priority, "Null priority should default to 0")
}

// TestQueuedMessage_UnmarshalJSON_EmptyStrings tests empty string handling
func TestQueuedMessage_UnmarshalJSON_EmptyStrings(t *testing.T) {
	jsonData := `{
		"id": "",
		"cid": "",
		"phone": "+1234567890",
		"message": "{}",
		"priority": "",
		"wid": "test@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should handle empty strings")
	assert.Equal(t, 0, msg.ID, "Empty ID string should default to 0")
	assert.Equal(t, 0, msg.CID, "Empty CID string should default to 0")
	assert.Equal(t, 0, msg.Priority, "Empty priority string should default to 0")
}

// TestQueuedMessage_UnmarshalJSON_NegativeNumbers tests negative values
func TestQueuedMessage_UnmarshalJSON_NegativeNumbers(t *testing.T) {
	jsonData := `{
		"id": -1,
		"cid": "-5",
		"phone": "+1234567890",
		"message": "{}",
		"priority": -10,
		"wid": "test@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should handle negative numbers")
	assert.Equal(t, -1, msg.ID, "Negative ID should be preserved")
	assert.Equal(t, -5, msg.CID, "Negative CID should be converted")
	assert.Equal(t, -10, msg.Priority, "Negative priority should be preserved")
}

// TestQueuedMessage_UnmarshalJSON_RealWorldPHPResponse tests actual PHP response format
func TestQueuedMessage_UnmarshalJSON_RealWorldPHPResponse(t *testing.T) {
	// This is the exact format from the error logs
	jsonData := `{
		"id": "5807",
		"cid": "0",
		"cstatus": "1",
		"uid": "1",
		"wid": "5213111830210:1@s.whatsapp.net",
		"unique": "1760047248c4ca4238a0b923820dcc509a6f75849b68e83090718c4",
		"phone": "+5213349426465",
		"message": "{\"text\":\"This is a test message\"}"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should parse real PHP response")
	assert.Equal(t, 5807, msg.ID)
	assert.Equal(t, 0, msg.CID)
	assert.Equal(t, 0, msg.Priority, "Missing priority should default to 0")
	assert.Equal(t, "+5213349426465", msg.Phone)
	assert.Equal(t, `{"text":"This is a test message"}`, msg.Message)
	assert.Equal(t, "5213111830210:1@s.whatsapp.net", msg.WID)
}

// TestQueuedMessage_UnmarshalJSON_LargeNumbers tests large ID values
func TestQueuedMessage_UnmarshalJSON_LargeNumbers(t *testing.T) {
	jsonData := `{
		"id": "999999999",
		"cid": "123456789",
		"phone": "+1234567890",
		"message": "{}",
		"priority": "999",
		"wid": "test@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err, "Should handle large numbers")
	assert.Equal(t, 999999999, msg.ID)
	assert.Equal(t, 123456789, msg.CID)
	assert.Equal(t, 999, msg.Priority)
}

// TestQueuedMessage_UnmarshalJSON_BackwardCompatibility tests that numeric format still works
func TestQueuedMessage_UnmarshalJSON_BackwardCompatibility(t *testing.T) {
	// Test that if PHP is fixed in the future, old code still works
	testCases := []struct {
		name     string
		jsonData string
		expected QueuedMessage
	}{
		{
			name: "Current PHP format (strings)",
			jsonData: `{
				"id": "100",
				"cid": "5",
				"phone": "+1234",
				"message": "{}",
				"priority": "1",
				"wid": "test@s.whatsapp.net"
			}`,
			expected: QueuedMessage{
				ID:       100,
				CID:      5,
				CStatus:  1, // Default to 1 (active) when not provided
				Phone:    "+1234",
				Message:  "{}",
				Priority: 1,
				WID:      "test@s.whatsapp.net",
			},
		},
		{
			name: "Future PHP format (numbers)",
			jsonData: `{
				"id": 100,
				"cid": 5,
				"phone": "+1234",
				"message": "{}",
				"priority": 1,
				"wid": "test@s.whatsapp.net"
			}`,
			expected: QueuedMessage{
				ID:       100,
				CID:      5,
				CStatus:  1, // Default to 1 (active) when not provided
				Phone:    "+1234",
				Message:  "{}",
				Priority: 1,
				WID:      "test@s.whatsapp.net",
			},
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			var msg QueuedMessage
			err := json.Unmarshal([]byte(tc.jsonData), &msg)

			require.NoError(t, err)
			assert.Equal(t, tc.expected, msg)
		})
	}
}

// TestConvertToInt tests the helper function directly
func TestConvertToInt(t *testing.T) {
	testCases := []struct {
		name     string
		input    interface{}
		defVal   int
		expected int
	}{
		{"int value", 42, 0, 42},
		{"float64 value", 42.0, 0, 42},
		{"float64 decimal", 42.7, 0, 42},
		{"string number", "42", 0, 42},
		{"string negative", "-42", 0, -42},
		{"empty string", "", 99, 99},
		{"invalid string", "invalid", 99, 99},
		{"nil value", nil, 99, 99},
		{"zero int", 0, 99, 0},
		{"zero string", "0", 99, 0},
		{"bool (unsupported)", true, 99, 99},
		{"negative int", -123, 0, -123},
		{"large number", "999999999", 0, 999999999},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			result := convertToInt(tc.input, tc.defVal)
			assert.Equal(t, tc.expected, result)
		})
	}
}

// TestQueuedMessage_UnmarshalJSON_InvalidJSON tests malformed JSON
func TestQueuedMessage_UnmarshalJSON_InvalidJSON(t *testing.T) {
	jsonData := `{invalid json`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	assert.Error(t, err, "Should error on invalid JSON")
}

// TestQueuedMessage_MarshalJSON tests that marshaling still works correctly
func TestQueuedMessage_MarshalJSON(t *testing.T) {
	msg := QueuedMessage{
		ID:       5807,
		CID:      0,
		Phone:    "+5213349426465",
		Message:  `{"text":"Test"}`,
		Priority: 1,
		WID:      "test@s.whatsapp.net",
	}

	data, err := json.Marshal(msg)

	require.NoError(t, err, "Should marshal without error")

	var unmarshaled QueuedMessage
	err = json.Unmarshal(data, &unmarshaled)

	require.NoError(t, err, "Should unmarshal marshaled data")
	assert.Equal(t, msg, unmarshaled, "Round-trip should preserve data")
}
