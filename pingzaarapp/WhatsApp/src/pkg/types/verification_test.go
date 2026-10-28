package types

import (
	"encoding/json"
	"math"
	"strconv"
	"sync"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestUnmarshalJSON_FieldPreservation verifies all fields are correctly unmarshaled
func TestUnmarshalJSON_FieldPreservation(t *testing.T) {
	jsonData := `{
		"id": "5807",
		"cid": "123",
		"phone": "+1234567890",
		"message": "{\"text\":\"Test message\"}",
		"priority": "1",
		"wid": "test@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err)

	// Verify converted fields
	assert.Equal(t, 5807, msg.ID, "ID should be converted correctly")
	assert.Equal(t, 123, msg.CID, "CID should be converted correctly")
	assert.Equal(t, 1, msg.Priority, "Priority should be converted correctly")

	// Verify non-converted fields are preserved
	assert.Equal(t, "+1234567890", msg.Phone, "Phone should be preserved")
	assert.Equal(t, `{"text":"Test message"}`, msg.Message, "Message should be preserved")
	assert.Equal(t, "test@s.whatsapp.net", msg.WID, "WID should be preserved")
}

// TestUnmarshalJSON_IntOverflow tests behavior with very large numbers
func TestUnmarshalJSON_IntOverflow(t *testing.T) {
	testCases := []struct {
		name       string
		jsonData   string
		shouldPass bool
	}{
		{
			name:       "Max int32",
			jsonData:   `{"id": "2147483647", "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			shouldPass: true,
		},
		{
			name:       "Larger than max int32 (but valid int on 64-bit)",
			jsonData:   `{"id": "9999999999", "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			shouldPass: true, // strconv.Atoi can handle this on 64-bit systems
		},
		{
			name:       "Extremely large number (overflow)",
			jsonData:   `{"id": "99999999999999999999999999", "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			shouldPass: true, // Should default to 0, not error
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			var msg QueuedMessage
			err := json.Unmarshal([]byte(tc.jsonData), &msg)

			if tc.shouldPass {
				assert.NoError(t, err, "Should not error on large numbers")
				// If it overflows, it should default to 0
			} else {
				assert.Error(t, err)
			}
		})
	}
}

// TestUnmarshalJSON_FloatTruncation tests float to int conversion
func TestUnmarshalJSON_FloatTruncation(t *testing.T) {
	testCases := []struct {
		name     string
		jsonData string
		expected int
	}{
		{
			name:     "Float with decimal",
			jsonData: `{"id": 5807.9, "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			expected: 5807, // Truncates, doesn't round
		},
		{
			name:     "Float 0.5",
			jsonData: `{"id": 0.5, "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			expected: 0,
		},
		{
			name:     "Negative float",
			jsonData: `{"id": -5807.9, "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			expected: -5807,
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			var msg QueuedMessage
			err := json.Unmarshal([]byte(tc.jsonData), &msg)

			require.NoError(t, err)
			assert.Equal(t, tc.expected, msg.ID, "Float should be truncated to int")
		})
	}
}

// TestUnmarshalJSON_ConcurrentSafety tests concurrent unmarshaling
func TestUnmarshalJSON_ConcurrentSafety(t *testing.T) {
	const numGoroutines = 100
	const iterations = 10

	jsonData := `{
		"id": "5807",
		"cid": "123",
		"phone": "+1234567890",
		"message": "{}",
		"priority": "1",
		"wid": "test@s.whatsapp.net"
	}`

	var wg sync.WaitGroup
	errors := make(chan error, numGoroutines*iterations)

	for i := 0; i < numGoroutines; i++ {
		wg.Add(1)
		go func(routineID int) {
			defer wg.Done()
			for j := 0; j < iterations; j++ {
				var msg QueuedMessage
				if err := json.Unmarshal([]byte(jsonData), &msg); err != nil {
					errors <- err
					return
				}

				// Verify values
				if msg.ID != 5807 || msg.CID != 123 || msg.Priority != 1 {
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

// TestUnmarshalJSON_SpecialCases tests special numeric values
func TestUnmarshalJSON_SpecialCases(t *testing.T) {
	testCases := []struct {
		name        string
		jsonData    string
		expectedID  int
		expectedCID int
	}{
		{
			name:        "Zero values",
			jsonData:    `{"id": 0, "cid": "0", "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			expectedID:  0,
			expectedCID: 0,
		},
		{
			name:        "Negative values",
			jsonData:    `{"id": -1, "cid": "-1", "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			expectedID:  -1,
			expectedCID: -1,
		},
		{
			name:        "Mixed zero and non-zero",
			jsonData:    `{"id": "0", "cid": 5807, "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			expectedID:  0,
			expectedCID: 5807,
		},
		{
			name:        "Whitespace in string",
			jsonData:    `{"id": " 5807 ", "cid": "123", "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			expectedID:  0, // strconv.Atoi fails on whitespace, defaults to 0
			expectedCID: 123,
		},
		{
			name:        "Leading zeros",
			jsonData:    `{"id": "0005807", "cid": "000123", "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`,
			expectedID:  5807,
			expectedCID: 123,
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			var msg QueuedMessage
			err := json.Unmarshal([]byte(tc.jsonData), &msg)

			require.NoError(t, err)
			assert.Equal(t, tc.expectedID, msg.ID, "ID should match expected")
			assert.Equal(t, tc.expectedCID, msg.CID, "CID should match expected")
		})
	}
}

// TestUnmarshalJSON_MalformedJSON tests invalid JSON handling
func TestUnmarshalJSON_MalformedJSON(t *testing.T) {
	testCases := []struct {
		name     string
		jsonData string
	}{
		{
			name:     "Missing closing brace",
			jsonData: `{"id": "5807", "phone": "+1234"`,
		},
		{
			name:     "Invalid JSON syntax",
			jsonData: `{id: 5807, phone: "+1234"}`,
		},
		{
			name:     "Trailing comma",
			jsonData: `{"id": "5807", "phone": "+1234",}`,
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			var msg QueuedMessage
			err := json.Unmarshal([]byte(tc.jsonData), &msg)

			assert.Error(t, err, "Should error on malformed JSON")
		})
	}
}

// TestUnmarshalJSON_RoundTrip tests marshal/unmarshal consistency
func TestUnmarshalJSON_RoundTrip(t *testing.T) {
	original := QueuedMessage{
		ID:       5807,
		CID:      123,
		Phone:    "+1234567890",
		Message:  `{"text":"Test"}`,
		Priority: 1,
		WID:      "test@s.whatsapp.net",
	}

	// Marshal to JSON
	data, err := json.Marshal(original)
	require.NoError(t, err)

	// Unmarshal back
	var result QueuedMessage
	err = json.Unmarshal(data, &result)
	require.NoError(t, err)

	// Verify equality
	assert.Equal(t, original, result, "Round-trip should preserve all data")
}

// TestConvertToInt_EdgeCases tests edge cases for type conversion
func TestConvertToInt_EdgeCases(t *testing.T) {
	testCases := []struct {
		name     string
		input    interface{}
		defVal   int
		expected int
	}{
		{
			name:     "Very large positive int",
			input:    int(2147483647),
			defVal:   0,
			expected: 2147483647,
		},
		{
			name:     "Very large negative int",
			input:    int(-2147483648),
			defVal:   0,
			expected: -2147483648,
		},
		{
			name:     "Float64 max safe integer",
			input:    float64(9007199254740991), // Math.pow(2, 53) - 1
			defVal:   0,
			expected: 9007199254740991,
		},
		{
			name:     "Float64 infinity",
			input:    math.Inf(1),
			defVal:   99,
			expected: int(math.Inf(1)), // Will overflow/wrap
		},
		{
			name:     "Float64 negative infinity",
			input:    math.Inf(-1),
			defVal:   99,
			expected: int(math.Inf(-1)), // Will overflow/wrap
		},
		{
			name:     "Float64 NaN",
			input:    math.NaN(),
			defVal:   99,
			expected: 0, // NaN converts to 0 in Go
		},
		{
			name:     "String with plus sign",
			input:    "+5807",
			defVal:   0,
			expected: 5807,
		},
		{
			name:     "String with scientific notation",
			input:    "5.807e3",
			defVal:   99,
			expected: 99, // strconv.Atoi doesn't parse scientific notation
		},
		{
			name:     "String hexadecimal",
			input:    "0x5807",
			defVal:   99,
			expected: 99, // strconv.Atoi doesn't parse hex
		},
		{
			name:     "Empty interface",
			input:    struct{}{},
			defVal:   99,
			expected: 99,
		},
		{
			name:     "Array (unsupported)",
			input:    []int{1, 2, 3},
			defVal:   99,
			expected: 99,
		},
		{
			name:     "Map (unsupported)",
			input:    map[string]int{"a": 1},
			defVal:   99,
			expected: 99,
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			result := convertToInt(tc.input, tc.defVal)
			assert.Equal(t, tc.expected, result)
		})
	}
}

// TestUnmarshalJSON_MemoryEfficiency tests for unnecessary allocations
func TestUnmarshalJSON_MemoryEfficiency(t *testing.T) {
	jsonData := `{
		"id": "5807",
		"cid": "123",
		"phone": "+1234567890",
		"message": "{}",
		"priority": "1",
		"wid": "test@s.whatsapp.net"
	}`

	// Run unmarshal multiple times
	for i := 0; i < 1000; i++ {
		var msg QueuedMessage
		err := json.Unmarshal([]byte(jsonData), &msg)
		require.NoError(t, err)
	}

	// If this completes without OOM, we're good
	// This is a basic sanity check, not a true memory profiling test
}

// TestUnmarshalJSON_JSONNumberHandling tests basic unmarshaling
func TestUnmarshalJSON_JSONNumberHandling(t *testing.T) {
	jsonData := `{
		"id": "5807",
		"cid": "123",
		"phone": "+1234",
		"message": "{}",
		"wid": "test@s.whatsapp.net"
	}`

	var msg QueuedMessage
	err := json.Unmarshal([]byte(jsonData), &msg)

	require.NoError(t, err)
	assert.Equal(t, 5807, msg.ID)
	assert.Equal(t, 123, msg.CID)
}

// BenchmarkUnmarshalJSON benchmarks the custom unmarshal performance
func BenchmarkUnmarshalJSON(b *testing.B) {
	jsonData := []byte(`{
		"id": "5807",
		"cid": "123",
		"phone": "+1234567890",
		"message": "{\"text\":\"This is a test message\"}",
		"priority": "1",
		"wid": "test@s.whatsapp.net"
	}`)

	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		var msg QueuedMessage
		json.Unmarshal(jsonData, &msg)
	}
}

// BenchmarkConvertToInt benchmarks the type conversion function
func BenchmarkConvertToInt(b *testing.B) {
	testValues := []interface{}{
		"5807",
		5807,
		5807.0,
		nil,
	}

	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		for _, val := range testValues {
			convertToInt(val, 0)
		}
	}
}

// TestConvertToInt_Overflow tests string number overflow
func TestConvertToInt_Overflow(t *testing.T) {
	// Test overflow behavior with extremely large string
	largeNum := "99999999999999999999999999999999"
	result := convertToInt(largeNum, 0)

	// strconv.Atoi will fail on overflow, so it should default to 0
	assert.Equal(t, 0, result, "Overflow should default to 0")

	// Test max int value
	maxInt := strconv.Itoa(int(^uint(0) >> 1))
	result = convertToInt(maxInt, 99)
	assert.NotEqual(t, 99, result, "Max int should parse correctly")
}
