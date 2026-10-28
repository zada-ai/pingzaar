package types

import (
	"encoding/json"
	"sync"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestPHPMessagesData_StringValues tests original behavior with string values
func TestPHPMessagesData_StringValues(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": "2",
		"random_send": "1",
		"random_min": "1",
		"random_max": "5",
		"messages": [
			{"id": "5807", "cid": "123", "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}
		]
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "Test Site", data.SiteName)
	assert.Equal(t, "2", data.ReceiveChats, "String should be preserved")
	assert.Equal(t, "1", data.RandomSend)
	assert.Equal(t, "1", data.RandomMin)
	assert.Equal(t, "5", data.RandomMax)
	assert.Len(t, data.Messages, 1)
	assert.Equal(t, 5807, data.Messages[0].ID)
}

// TestPHPMessagesData_NumericValues tests new behavior causing the error
func TestPHPMessagesData_NumericValues(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 2,
		"random_send": 1,
		"random_min": 1,
		"random_max": 5,
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err, "Numeric values should be accepted and converted to strings")
	assert.Equal(t, "2", data.ReceiveChats, "Number should be converted to string")
	assert.Equal(t, "1", data.RandomSend)
	assert.Equal(t, "1", data.RandomMin)
	assert.Equal(t, "5", data.RandomMax)
}

// TestPHPMessagesData_MixedTypes tests mixed string and numeric in same payload
func TestPHPMessagesData_MixedTypes(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 2,
		"random_send": "1",
		"random_min": 3,
		"random_max": "10",
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err, "Mixed types should be handled")
	assert.Equal(t, "2", data.ReceiveChats, "Number converted to string")
	assert.Equal(t, "1", data.RandomSend, "String preserved")
	assert.Equal(t, "3", data.RandomMin, "Number converted to string")
	assert.Equal(t, "10", data.RandomMax, "String preserved")
}

// TestPHPMessagesData_FloatValues tests float to string conversion
func TestPHPMessagesData_FloatValues(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 2.0,
		"random_send": 1.5,
		"random_min": 3.9,
		"random_max": 10.1,
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "2", data.ReceiveChats, "Float 2.0 should become '2'")
	assert.Equal(t, "1.5", data.RandomSend)
	assert.Equal(t, "3.9", data.RandomMin)
	assert.Equal(t, "10.1", data.RandomMax)
}

// TestPHPMessagesData_BooleanValues tests boolean conversion
func TestPHPMessagesData_BooleanValues(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": true,
		"random_send": false,
		"random_min": true,
		"random_max": false,
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "1", data.ReceiveChats, "true → '1'")
	assert.Equal(t, "0", data.RandomSend, "false → '0'")
	assert.Equal(t, "1", data.RandomMin)
	assert.Equal(t, "0", data.RandomMax)
}

// TestPHPMessagesData_NullValues tests null field handling
func TestPHPMessagesData_NullValues(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": null,
		"random_send": null,
		"random_min": "1",
		"random_max": "5",
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "", data.ReceiveChats, "null → empty string")
	assert.Equal(t, "", data.RandomSend, "null → empty string")
	assert.Equal(t, "1", data.RandomMin)
	assert.Equal(t, "5", data.RandomMax)
}

// TestPHPMessagesData_EmptyStrings tests empty string values
func TestPHPMessagesData_EmptyStrings(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": "",
		"random_send": "",
		"random_min": "1",
		"random_max": "5",
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "", data.ReceiveChats)
	assert.Equal(t, "", data.RandomSend)
}

// TestPHPMessagesData_LargeNumbers tests very large numeric values
func TestPHPMessagesData_LargeNumbers(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 999999999,
		"random_send": 2147483647,
		"random_min": "1",
		"random_max": "5",
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "999999999", data.ReceiveChats)
	assert.Equal(t, "2147483647", data.RandomSend)
}

// TestPHPMessagesData_NegativeNumbers tests negative numeric values
func TestPHPMessagesData_NegativeNumbers(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": -1,
		"random_send": "-2",
		"random_min": -3,
		"random_max": "-4",
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "-1", data.ReceiveChats)
	assert.Equal(t, "-2", data.RandomSend)
	assert.Equal(t, "-3", data.RandomMin)
	assert.Equal(t, "-4", data.RandomMax)
}

// TestPHPMessagesData_ZeroValues tests zero values
func TestPHPMessagesData_ZeroValues(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 0,
		"random_send": "0",
		"random_min": 0,
		"random_max": "0",
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "0", data.ReceiveChats)
	assert.Equal(t, "0", data.RandomSend)
	assert.Equal(t, "0", data.RandomMin)
	assert.Equal(t, "0", data.RandomMax)
}

// TestPHPMessagesData_WithMessages tests integration with QueuedMessage array
func TestPHPMessagesData_WithMessages(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 2,
		"random_send": "1",
		"random_min": 1,
		"random_max": "5",
		"messages": [
			{"id": "100", "cid": 1, "phone": "+1111", "message": "{}", "wid": "test1@s.whatsapp.net"},
			{"id": 101, "cid": "2", "phone": "+2222", "message": "{}", "wid": "test2@s.whatsapp.net"},
			{"id": "102", "cid": "3", "phone": "+3333", "message": "{}", "wid": "test3@s.whatsapp.net"}
		]
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "2", data.ReceiveChats)
	assert.Len(t, data.Messages, 3)

	// Verify messages parsed correctly with mixed ID types
	assert.Equal(t, 100, data.Messages[0].ID)
	assert.Equal(t, 1, data.Messages[0].CID)

	assert.Equal(t, 101, data.Messages[1].ID)
	assert.Equal(t, 2, data.Messages[1].CID)

	assert.Equal(t, 102, data.Messages[2].ID)
	assert.Equal(t, 3, data.Messages[2].CID)
}

// TestPHPMessagesData_RealWorldResponse tests actual PHP server response format
func TestPHPMessagesData_RealWorldResponse(t *testing.T) {
	// This is the exact format from PHP server that caused the error
	jsonData := `{
		"site_name": "SMS",
		"receive_chats": 2,
		"random_send": 1,
		"random_min": 1,
		"random_max": 5,
		"messages": [
			{
				"id": "5807",
				"cid": "0",
				"cstatus": "1",
				"uid": "1",
				"wid": "5213111830210:1@qs.whatsapp.net",
				"unique": "1760047248c4ca4238a0b923820dcc509a6f75849b68e83090718c4",
				"phone": "+5213349426465",
				"message": "{\"text\":\"This is a test message\"}"
			}
		]
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err, "Real-world PHP response should parse successfully")
	assert.Equal(t, "SMS", data.SiteName)
	assert.Equal(t, "2", data.ReceiveChats, "Numeric 2 should become string '2'")
	assert.Equal(t, "1", data.RandomSend)
	assert.Equal(t, "1", data.RandomMin)
	assert.Equal(t, "5", data.RandomMax)
	assert.Len(t, data.Messages, 1)
	assert.Equal(t, 5807, data.Messages[0].ID)
	assert.Equal(t, "+5213349426465", data.Messages[0].Phone)
}

// TestPHPMessagesData_BackwardCompatibility tests that old code still works
func TestPHPMessagesData_BackwardCompatibility(t *testing.T) {
	// Create struct directly (not from JSON)
	data := PHPMessagesData{
		SiteName:     "Test",
		ReceiveChats: "2",
		RandomSend:   "1",
		RandomMin:    "1",
		RandomMax:    "5",
		Messages: []QueuedMessage{
			{ID: 100, CID: 1, Phone: "+1234", Message: "{}", WID: "test@s.whatsapp.net"},
		},
	}

	assert.Equal(t, "2", data.ReceiveChats)
	assert.Len(t, data.Messages, 1)

	// Test marshaling
	jsonData, err := json.Marshal(data)
	require.NoError(t, err)
	assert.Contains(t, string(jsonData), `"receive_chats":"2"`)
}

// TestPHPMessagesData_RoundTrip tests marshal/unmarshal consistency
func TestPHPMessagesData_RoundTrip(t *testing.T) {
	original := PHPMessagesData{
		SiteName:     "Test Site",
		ReceiveChats: "2",
		RandomSend:   "1",
		RandomMin:    "3",
		RandomMax:    "10",
		Messages: []QueuedMessage{
			{ID: 100, CID: 1, Phone: "+1234", Message: "{}", WID: "test@s.whatsapp.net"},
		},
	}

	// Marshal to JSON
	data, err := json.Marshal(original)
	require.NoError(t, err)

	// Unmarshal back
	var result PHPMessagesData
	err = json.Unmarshal(data, &result)
	require.NoError(t, err)

	// Verify equality
	assert.Equal(t, original.SiteName, result.SiteName)
	assert.Equal(t, original.ReceiveChats, result.ReceiveChats)
	assert.Equal(t, original.RandomSend, result.RandomSend)
	assert.Equal(t, original.RandomMin, result.RandomMin)
	assert.Equal(t, original.RandomMax, result.RandomMax)
	assert.Equal(t, len(original.Messages), len(result.Messages))
}

// TestPHPMessagesData_ConcurrentSafety tests concurrent unmarshaling
func TestPHPMessagesData_ConcurrentSafety(t *testing.T) {
	const numGoroutines = 100
	const iterations = 10

	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 2,
		"random_send": "1",
		"random_min": 1,
		"random_max": "5",
		"messages": []
	}`

	var wg sync.WaitGroup
	errors := make(chan error, numGoroutines*iterations)

	for i := 0; i < numGoroutines; i++ {
		wg.Add(1)
		go func(routineID int) {
			defer wg.Done()
			for j := 0; j < iterations; j++ {
				var data PHPMessagesData
				if err := json.Unmarshal([]byte(jsonData), &data); err != nil {
					errors <- err
					return
				}

				// Verify values
				if data.ReceiveChats != "2" || data.RandomSend != "1" {
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

// TestPHPMessagesData_InvalidJSON tests malformed JSON handling
func TestPHPMessagesData_InvalidJSON(t *testing.T) {
	testCases := []struct {
		name     string
		jsonData string
	}{
		{
			name:     "Missing closing brace",
			jsonData: `{"site_name": "Test", "receive_chats": "2"`,
		},
		{
			name:     "Invalid JSON syntax",
			jsonData: `{site_name: "Test", receive_chats: 2}`,
		},
		{
			name:     "Trailing comma",
			jsonData: `{"site_name": "Test", "receive_chats": "2",}`,
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			var data PHPMessagesData
			err := json.Unmarshal([]byte(tc.jsonData), &data)
			assert.Error(t, err, "Should error on malformed JSON")
		})
	}
}

// TestPHPMessagesData_FieldPreservation tests all fields are correctly unmarshaled
func TestPHPMessagesData_FieldPreservation(t *testing.T) {
	jsonData := `{
		"site_name": "My Custom Site Name",
		"receive_chats": 3,
		"random_send": "0",
		"random_min": 2,
		"random_max": "8",
		"messages": [
			{"id": "999", "cid": "456", "phone": "+9876543210", "message": "{\"text\":\"Hello\"}", "wid": "wid@s.whatsapp.net"}
		]
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)

	// Verify converted fields
	assert.Equal(t, "3", data.ReceiveChats, "Numeric 3 should become '3'")
	assert.Equal(t, "0", data.RandomSend, "String '0' should be preserved")
	assert.Equal(t, "2", data.RandomMin, "Numeric 2 should become '2'")
	assert.Equal(t, "8", data.RandomMax, "String '8' should be preserved")

	// Verify non-converted fields
	assert.Equal(t, "My Custom Site Name", data.SiteName)
	assert.Len(t, data.Messages, 1)
	assert.Equal(t, 999, data.Messages[0].ID)
}

// TestPHPMessagesData_SpecialCharacters tests strings with special characters
func TestPHPMessagesData_SpecialCharacters(t *testing.T) {
	jsonData := `{
		"site_name": "Test 🚀 Site™",
		"receive_chats": "2",
		"random_send": "1",
		"random_min": "1",
		"random_max": "5",
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "Test 🚀 Site™", data.SiteName)
	assert.Equal(t, "2", data.ReceiveChats)
}

// TestPHPMessagesData_EmptyMessages tests empty messages array
func TestPHPMessagesData_EmptyMessages(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 2,
		"random_send": 1,
		"random_min": 1,
		"random_max": 5,
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "2", data.ReceiveChats)
	assert.Empty(t, data.Messages, "Empty messages array should be handled")
}

// TestPHPMessagesData_MissingMessagesField tests when messages field is omitted
func TestPHPMessagesData_MissingMessagesField(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 2,
		"random_send": 1,
		"random_min": 1,
		"random_max": 5
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "2", data.ReceiveChats)
	assert.Nil(t, data.Messages, "Missing messages field should result in nil slice")
}

// TestPHPMessagesData_ExtraFields tests handling of unexpected fields
func TestPHPMessagesData_ExtraFields(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 2,
		"random_send": 1,
		"random_min": 1,
		"random_max": 5,
		"messages": [],
		"extra_field": "should be ignored",
		"another_field": 999
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err, "Extra fields should be ignored")
	assert.Equal(t, "2", data.ReceiveChats)
}

// TestPHPMessagesData_LeadingZeros tests numeric strings with leading zeros
func TestPHPMessagesData_LeadingZeros(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": "002",
		"random_send": "001",
		"random_min": "0010",
		"random_max": "0005",
		"messages": []
	}`

	var data PHPMessagesData
	err := json.Unmarshal([]byte(jsonData), &data)

	require.NoError(t, err)
	assert.Equal(t, "002", data.ReceiveChats, "Leading zeros should be preserved in strings")
	assert.Equal(t, "001", data.RandomSend)
	assert.Equal(t, "0010", data.RandomMin)
	assert.Equal(t, "0005", data.RandomMax)
}

// TestPHPMessagesData_MemoryEfficiency tests for unnecessary allocations
func TestPHPMessagesData_MemoryEfficiency(t *testing.T) {
	jsonData := `{
		"site_name": "Test Site",
		"receive_chats": 2,
		"random_send": "1",
		"random_min": 1,
		"random_max": "5",
		"messages": []
	}`

	// Run unmarshal multiple times
	for i := 0; i < 1000; i++ {
		var data PHPMessagesData
		err := json.Unmarshal([]byte(jsonData), &data)
		require.NoError(t, err)
	}

	// If this completes without OOM, we're good
	// This is a basic sanity check, not a true memory profiling test
}

// BenchmarkPHPMessagesData_Unmarshal benchmarks the custom unmarshal performance
func BenchmarkPHPMessagesData_Unmarshal(b *testing.B) {
	jsonData := []byte(`{
		"site_name": "Test Site",
		"receive_chats": 2,
		"random_send": "1",
		"random_min": 1,
		"random_max": "5",
		"messages": [
			{"id": "5807", "cid": "123", "phone": "+1234567890", "message": "{\"text\":\"Test\"}", "wid": "test@s.whatsapp.net"}
		]
	}`)

	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		var data PHPMessagesData
		json.Unmarshal(jsonData, &data)
	}
}
