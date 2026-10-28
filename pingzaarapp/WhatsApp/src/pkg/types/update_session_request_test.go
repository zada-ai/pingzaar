package types

import (
	"encoding/json"
	"sync"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestUpdateSessionRequest_StringValues tests form submission with string values
func TestUpdateSessionRequest_StringValues(t *testing.T) {
	jsonData := `{
		"wsid": "test-session-123",
		"receive_chats": "2",
		"random_send": "1",
		"random_min": "3",
		"random_max": "10"
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err)
	assert.Equal(t, "test-session-123", req.WSID)
	assert.Equal(t, "2", req.ReceiveChats, "String should be preserved")
	assert.Equal(t, "1", req.RandomSend)
	assert.Equal(t, "3", req.RandomMin)
	assert.Equal(t, "10", req.RandomMax)
}

// TestUpdateSessionRequest_NumericValues tests JavaScript API with numeric values
func TestUpdateSessionRequest_NumericValues(t *testing.T) {
	jsonData := `{
		"wsid": "test-session-123",
		"receive_chats": 2,
		"random_send": 1,
		"random_min": 3,
		"random_max": 10
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err, "Numeric values should be accepted and converted to strings")
	assert.Equal(t, "test-session-123", req.WSID)
	assert.Equal(t, "2", req.ReceiveChats, "Number should be converted to string")
	assert.Equal(t, "1", req.RandomSend)
	assert.Equal(t, "3", req.RandomMin)
	assert.Equal(t, "10", req.RandomMax)
}

// TestUpdateSessionRequest_MixedTypes tests mixed string and numeric in same payload
func TestUpdateSessionRequest_MixedTypes(t *testing.T) {
	jsonData := `{
		"wsid": "test-session-123",
		"receive_chats": 2,
		"random_send": "1",
		"random_min": 5,
		"random_max": "15"
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err, "Mixed types should be handled")
	assert.Equal(t, "2", req.ReceiveChats, "Number converted to string")
	assert.Equal(t, "1", req.RandomSend, "String preserved")
	assert.Equal(t, "5", req.RandomMin, "Number converted to string")
	assert.Equal(t, "15", req.RandomMax, "String preserved")
}

// TestUpdateSessionRequest_FloatValues tests float to string conversion
func TestUpdateSessionRequest_FloatValues(t *testing.T) {
	jsonData := `{
		"wsid": "test-session-123",
		"receive_chats": 2.0,
		"random_send": 1.5,
		"random_min": 3.9,
		"random_max": 10.1
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err)
	assert.Equal(t, "2", req.ReceiveChats, "Float 2.0 should become '2'")
	assert.Equal(t, "1.5", req.RandomSend)
	assert.Equal(t, "3.9", req.RandomMin)
	assert.Equal(t, "10.1", req.RandomMax)
}

// TestUpdateSessionRequest_BooleanValues tests boolean conversion
func TestUpdateSessionRequest_BooleanValues(t *testing.T) {
	jsonData := `{
		"wsid": "test-session-123",
		"receive_chats": true,
		"random_send": false,
		"random_min": true,
		"random_max": false
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err)
	assert.Equal(t, "1", req.ReceiveChats, "true → '1'")
	assert.Equal(t, "0", req.RandomSend, "false → '0'")
	assert.Equal(t, "1", req.RandomMin)
	assert.Equal(t, "0", req.RandomMax)
}

// TestUpdateSessionRequest_NullValues tests null field handling
func TestUpdateSessionRequest_NullValues(t *testing.T) {
	jsonData := `{
		"wsid": "test-session-123",
		"receive_chats": null,
		"random_send": null,
		"random_min": "1",
		"random_max": "5"
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err)
	assert.Equal(t, "", req.ReceiveChats, "null → empty string")
	assert.Equal(t, "", req.RandomSend, "null → empty string")
	assert.Equal(t, "1", req.RandomMin)
	assert.Equal(t, "5", req.RandomMax)
}

// TestUpdateSessionRequest_EmptyStrings tests empty string values
func TestUpdateSessionRequest_EmptyStrings(t *testing.T) {
	jsonData := `{
		"wsid": "test-session-123",
		"receive_chats": "",
		"random_send": "",
		"random_min": "1",
		"random_max": "5"
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err)
	assert.Equal(t, "", req.ReceiveChats)
	assert.Equal(t, "", req.RandomSend)
}

// TestUpdateSessionRequest_LargeNumbers tests very large numeric values
func TestUpdateSessionRequest_LargeNumbers(t *testing.T) {
	jsonData := `{
		"wsid": "test-session-123",
		"receive_chats": 999999999,
		"random_send": 2147483647,
		"random_min": "1",
		"random_max": "5"
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err)
	assert.Equal(t, "999999999", req.ReceiveChats)
	assert.Equal(t, "2147483647", req.RandomSend)
}

// TestUpdateSessionRequest_NegativeNumbers tests negative numeric values
func TestUpdateSessionRequest_NegativeNumbers(t *testing.T) {
	jsonData := `{
		"wsid": "test-session-123",
		"receive_chats": -1,
		"random_send": "-2",
		"random_min": -3,
		"random_max": "-4"
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err)
	assert.Equal(t, "-1", req.ReceiveChats)
	assert.Equal(t, "-2", req.RandomSend)
	assert.Equal(t, "-3", req.RandomMin)
	assert.Equal(t, "-4", req.RandomMax)
}

// TestUpdateSessionRequest_ZeroValues tests zero values
func TestUpdateSessionRequest_ZeroValues(t *testing.T) {
	jsonData := `{
		"wsid": "test-session-123",
		"receive_chats": 0,
		"random_send": "0",
		"random_min": 0,
		"random_max": "0"
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err)
	assert.Equal(t, "0", req.ReceiveChats)
	assert.Equal(t, "0", req.RandomSend)
	assert.Equal(t, "0", req.RandomMin)
	assert.Equal(t, "0", req.RandomMax)
}

// TestUpdateSessionRequest_WSIDPreservation tests WSID field is always preserved
func TestUpdateSessionRequest_WSIDPreservation(t *testing.T) {
	testCases := []struct {
		name string
		wsid string
	}{
		{"UUID format", "550e8400-e29b-41d4-a716-446655440000"},
		{"Simple string", "session-123"},
		{"With special chars", "user@site_123-session"},
		{"Numeric string", "12345"},
		{"Empty string", ""},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			jsonData := `{
				"wsid": "` + tc.wsid + `",
				"receive_chats": 2,
				"random_send": "1",
				"random_min": 1,
				"random_max": "5"
			}`

			var req UpdateSessionRequest
			err := json.Unmarshal([]byte(jsonData), &req)

			require.NoError(t, err)
			assert.Equal(t, tc.wsid, req.WSID, "WSID should always be preserved as-is")
		})
	}
}

// TestUpdateSessionRequest_RealWorldPOSTRequest tests typical POST request format
func TestUpdateSessionRequest_RealWorldPOSTRequest(t *testing.T) {
	// This simulates a real POST request from PHP frontend
	jsonData := `{
		"wsid": "user1_site2_550e8400",
		"receive_chats": 2,
		"random_send": 1,
		"random_min": 1,
		"random_max": 5
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err, "Real-world POST request should parse successfully")
	assert.Equal(t, "user1_site2_550e8400", req.WSID)
	assert.Equal(t, "2", req.ReceiveChats, "Numeric 2 should become string '2'")
	assert.Equal(t, "1", req.RandomSend)
	assert.Equal(t, "1", req.RandomMin)
	assert.Equal(t, "5", req.RandomMax)
}

// TestUpdateSessionRequest_BackwardCompatibility tests that old code still works
func TestUpdateSessionRequest_BackwardCompatibility(t *testing.T) {
	// Create struct directly (not from JSON)
	req := UpdateSessionRequest{
		WSID:         "test-session",
		ReceiveChats: "2",
		RandomSend:   "1",
		RandomMin:    "3",
		RandomMax:    "10",
	}

	assert.Equal(t, "test-session", req.WSID)
	assert.Equal(t, "2", req.ReceiveChats)

	// Test marshaling
	jsonData, err := json.Marshal(req)
	require.NoError(t, err)
	assert.Contains(t, string(jsonData), `"receive_chats":"2"`)
	assert.Contains(t, string(jsonData), `"wsid":"test-session"`)
}

// TestUpdateSessionRequest_RoundTrip tests marshal/unmarshal consistency
func TestUpdateSessionRequest_RoundTrip(t *testing.T) {
	original := UpdateSessionRequest{
		WSID:         "test-session-456",
		ReceiveChats: "2",
		RandomSend:   "1",
		RandomMin:    "5",
		RandomMax:    "15",
	}

	// Marshal to JSON
	data, err := json.Marshal(original)
	require.NoError(t, err)

	// Unmarshal back
	var result UpdateSessionRequest
	err = json.Unmarshal(data, &result)
	require.NoError(t, err)

	// Verify equality
	assert.Equal(t, original, result, "Round-trip should preserve all data")
}

// TestUpdateSessionRequest_ConcurrentSafety tests concurrent unmarshaling
func TestUpdateSessionRequest_ConcurrentSafety(t *testing.T) {
	const numGoroutines = 100
	const iterations = 10

	jsonData := `{
		"wsid": "test-session-789",
		"receive_chats": 2,
		"random_send": "1",
		"random_min": 1,
		"random_max": "5"
	}`

	var wg sync.WaitGroup
	errors := make(chan error, numGoroutines*iterations)

	for i := 0; i < numGoroutines; i++ {
		wg.Add(1)
		go func(routineID int) {
			defer wg.Done()
			for j := 0; j < iterations; j++ {
				var req UpdateSessionRequest
				if err := json.Unmarshal([]byte(jsonData), &req); err != nil {
					errors <- err
					return
				}

				// Verify values
				if req.ReceiveChats != "2" || req.RandomSend != "1" {
					errors <- assert.AnError
					return
				}
			}
		}(i)
	}

	wg.Wait()
	close(errors)

	// Check for any errors
	errorCount := 0
	for err := range errors {
		if err != nil {
			t.Errorf("Concurrent unmarshal failed: %v", err)
			errorCount++
		}
	}

	assert.Equal(t, 0, errorCount, "Should have no errors in concurrent unmarshaling")
}

// TestUpdateSessionRequest_InvalidJSON tests malformed JSON handling
func TestUpdateSessionRequest_InvalidJSON(t *testing.T) {
	testCases := []struct {
		name     string
		jsonData string
	}{
		{
			name:     "Missing closing brace",
			jsonData: `{"wsid": "test", "receive_chats": "2"`,
		},
		{
			name:     "Invalid JSON syntax",
			jsonData: `{wsid: "test", receive_chats: 2}`,
		},
		{
			name:     "Trailing comma",
			jsonData: `{"wsid": "test", "receive_chats": "2",}`,
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			var req UpdateSessionRequest
			err := json.Unmarshal([]byte(tc.jsonData), &req)
			assert.Error(t, err, "Should error on malformed JSON")
		})
	}
}

// TestUpdateSessionRequest_FieldPreservation tests all fields are correctly unmarshaled
func TestUpdateSessionRequest_FieldPreservation(t *testing.T) {
	jsonData := `{
		"wsid": "complex-session-id-with-uuid-550e8400",
		"receive_chats": 3,
		"random_send": "0",
		"random_min": 2,
		"random_max": "8"
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err)

	// Verify all fields
	assert.Equal(t, "complex-session-id-with-uuid-550e8400", req.WSID)
	assert.Equal(t, "3", req.ReceiveChats, "Numeric 3 should become '3'")
	assert.Equal(t, "0", req.RandomSend, "String '0' should be preserved")
	assert.Equal(t, "2", req.RandomMin, "Numeric 2 should become '2'")
	assert.Equal(t, "8", req.RandomMax, "String '8' should be preserved")
}

// TestUpdateSessionRequest_ExtraFields tests handling of unexpected fields
func TestUpdateSessionRequest_ExtraFields(t *testing.T) {
	jsonData := `{
		"wsid": "test-session",
		"receive_chats": 2,
		"random_send": "1",
		"random_min": 1,
		"random_max": "5",
		"extra_field": "should be ignored",
		"another_field": 999
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err, "Extra fields should be ignored")
	assert.Equal(t, "test-session", req.WSID)
	assert.Equal(t, "2", req.ReceiveChats)
}

// TestUpdateSessionRequest_LeadingZeros tests numeric strings with leading zeros
func TestUpdateSessionRequest_LeadingZeros(t *testing.T) {
	jsonData := `{
		"wsid": "test-session",
		"receive_chats": "002",
		"random_send": "001",
		"random_min": "0010",
		"random_max": "0005"
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(jsonData), &req)

	require.NoError(t, err)
	assert.Equal(t, "002", req.ReceiveChats, "Leading zeros should be preserved in strings")
	assert.Equal(t, "001", req.RandomSend)
	assert.Equal(t, "0010", req.RandomMin)
	assert.Equal(t, "0005", req.RandomMax)
}

// BenchmarkUpdateSessionRequest_Unmarshal benchmarks the custom unmarshal performance
func BenchmarkUpdateSessionRequest_Unmarshal(b *testing.B) {
	jsonData := []byte(`{
		"wsid": "test-session-benchmark-550e8400",
		"receive_chats": 2,
		"random_send": "1",
		"random_min": 3,
		"random_max": "10"
	}`)

	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		var req UpdateSessionRequest
		json.Unmarshal(jsonData, &req)
	}
}
