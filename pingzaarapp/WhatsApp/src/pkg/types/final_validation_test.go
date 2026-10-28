package types

import (
	"encoding/json"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestFinalValidation_ExactErrorScenario tests the EXACT error scenario from the logs
// This reproduces the error: "json: cannot unmarshal string into Go struct field...id of type int"
func TestFinalValidation_ExactErrorScenario(t *testing.T) {
	// This is the EXACT JSON that was failing before the fix
	// Extracted from error log: response_body={"status":200,"message":false,"data":{...}}
	exactPHPResponse := `{
		"status": 200,
		"message": false,
		"data": {
			"site_name": "SMS",
			"receive_chats": "2",
			"random_send": "1",
			"random_min": "1",
			"random_max": "5",
			"messages": [{
				"id": "5807",
				"cid": "0",
				"cstatus": "1",
				"uid": "1",
				"wid": "5213111830210:1@qs.whatsapp.net",
				"unique": "1760047248c4ca4238a0b923820dcc509a6f75849b68e83090718c4",
				"phone": "+5213349426465",
				"message": "{\"text\":\"This is a test message\"}"
			}]
		}
	}`

	// Parse the PHP response structure
	var phpResponse struct {
		Status  int             `json:"status"`
		Message interface{}     `json:"message"`
		Data    json.RawMessage `json:"data"`
	}

	err := json.Unmarshal([]byte(exactPHPResponse), &phpResponse)
	require.NoError(t, err, "First parse should succeed")

	// Parse the data field - THIS WAS FAILING BEFORE THE FIX
	var data struct {
		SiteName     string          `json:"site_name"`
		ReceiveChats string          `json:"receive_chats"`
		RandomSend   string          `json:"random_send"`
		RandomMin    string          `json:"random_min"`
		RandomMax    string          `json:"random_max"`
		Messages     []QueuedMessage `json:"messages"`
	}

	err = json.Unmarshal(phpResponse.Data, &data)
	require.NoError(t, err, "Second parse should succeed (THIS WAS FAILING BEFORE FIX)")

	// Verify the message was parsed correctly
	require.Len(t, data.Messages, 1, "Should have 1 message")

	msg := data.Messages[0]
	assert.Equal(t, 5807, msg.ID, "ID should be converted from string to int")
	assert.Equal(t, 0, msg.CID, "CID should be converted from string to int")
	assert.Equal(t, "+5213349426465", msg.Phone, "Phone should be preserved")
	assert.Equal(t, `{"text":"This is a test message"}`, msg.Message, "Message should be preserved")
	assert.Equal(t, "5213111830210:1@qs.whatsapp.net", msg.WID, "WID should be preserved")

	t.Log("✓ The exact error scenario from logs now works correctly!")
}

// TestFinalValidation_BeforeAndAfter demonstrates the fix
func TestFinalValidation_BeforeAndAfter(t *testing.T) {
	testCases := []struct {
		name        string
		description string
		jsonData    string
		shouldPass  bool
	}{
		{
			name:        "Before Fix - Would Fail",
			description: "PHP sends string IDs - would fail without custom UnmarshalJSON",
			jsonData: `{
				"id": "5807",
				"cid": "0",
				"phone": "+1234",
				"message": "{}",
				"wid": "test@s.whatsapp.net"
			}`,
			shouldPass: true, // Now passes with our fix!
		},
		{
			name:        "After Fix - Still Works",
			description: "Numeric IDs work (future-proof)",
			jsonData: `{
				"id": 5807,
				"cid": 0,
				"phone": "+1234",
				"message": "{}",
				"wid": "test@s.whatsapp.net"
			}`,
			shouldPass: true,
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			var msg QueuedMessage
			err := json.Unmarshal([]byte(tc.jsonData), &msg)

			if tc.shouldPass {
				assert.NoError(t, err, tc.description)
				assert.Equal(t, 5807, msg.ID)
				assert.Equal(t, 0, msg.CID)
			} else {
				assert.Error(t, err)
			}
		})
	}
}

// TestFinalValidation_BackwardCompatibility ensures existing code still works
func TestFinalValidation_BackwardCompatibility(t *testing.T) {
	// Test that creating QueuedMessage directly still works
	directCreate := QueuedMessage{
		ID:       5807,
		CID:      123,
		Phone:    "+1234567890",
		Message:  `{"text":"Test"}`,
		Priority: 1,
		WID:      "test@s.whatsapp.net",
	}

	assert.Equal(t, 5807, directCreate.ID)
	assert.Equal(t, 123, directCreate.CID)

	// Test that marshaling to JSON works
	jsonData, err := json.Marshal(directCreate)
	require.NoError(t, err)

	// Verify JSON contains numeric values (not strings)
	assert.Contains(t, string(jsonData), `"id":5807`)
	assert.Contains(t, string(jsonData), `"cid":123`)

	// Test that unmarshaling works
	var unmarshaled QueuedMessage
	err = json.Unmarshal(jsonData, &unmarshaled)
	require.NoError(t, err)

	assert.Equal(t, directCreate, unmarshaled)
}

// TestFinalValidation_QueueIntegration tests integration with MessageQueue
func TestFinalValidation_QueueIntegration(t *testing.T) {
	// Create a message queue
	queue := NewMessageQueue()

	// Parse messages from PHP response
	phpJSON := `[
		{"id": "100", "cid": "1", "phone": "+1234", "message": "{}", "wid": "test1@s.whatsapp.net"},
		{"id": "101", "cid": "2", "phone": "+5678", "message": "{}", "wid": "test2@s.whatsapp.net"}
	]`

	var messages []QueuedMessage
	err := json.Unmarshal([]byte(phpJSON), &messages)
	require.NoError(t, err, "Should parse PHP messages")

	// Add to queue
	for _, msg := range messages {
		added := queue.Add(msg)
		assert.True(t, added, "Should add message to queue")
	}

	assert.Equal(t, 2, queue.Size())

	// Retrieve and verify
	msg1 := queue.GetNext()
	require.NotNil(t, msg1)
	assert.Equal(t, 100, msg1.ID)
	assert.Equal(t, 1, msg1.CID)

	msg2 := queue.GetNext()
	require.NotNil(t, msg2)
	assert.Equal(t, 101, msg2.ID)
	assert.Equal(t, 2, msg2.CID)
}

// TestFinalValidation_NoSideEffects ensures the fix doesn't affect other JSON operations
func TestFinalValidation_NoSideEffects(t *testing.T) {
	// Test other structs aren't affected
	type OtherStruct struct {
		ID    int    `json:"id"`
		Value string `json:"value"`
	}

	jsonData := `{"id": "123", "value": "test"}`
	var other OtherStruct
	err := json.Unmarshal([]byte(jsonData), &other)

	// This should FAIL because OtherStruct doesn't have custom UnmarshalJSON
	assert.Error(t, err, "Other structs should not be affected by our fix")

	// But with numeric ID it should work
	jsonData = `{"id": 123, "value": "test"}`
	err = json.Unmarshal([]byte(jsonData), &other)
	assert.NoError(t, err)
	assert.Equal(t, 123, other.ID)
}

// TestFinalValidation_RealWorldUsage simulates real-world usage
func TestFinalValidation_RealWorldUsage(t *testing.T) {
	// Simulate fetcher receiving PHP response
	phpResponse := []byte(`{
		"status": 200,
		"message": false,
		"data": {
			"site_name": "SMS",
			"receive_chats": "2",
			"random_send": "1",
			"random_min": "1",
			"random_max": "5",
			"messages": [
				{"id": "5807", "cid": "0", "phone": "+1234567890", "message": "{\"text\":\"Message 1\"}", "wid": "wid1@s.whatsapp.net"},
				{"id": "5808", "cid": "123", "phone": "+9876543210", "message": "{\"text\":\"Message 2\"}", "wid": "wid2@s.whatsapp.net"},
				{"id": "5809", "cid": "456", "phone": "+5555555555", "message": "{\"text\":\"Message 3\"}", "wid": "wid3@s.whatsapp.net"}
			]
		}
	}`)

	// Parse outer response
	var response struct {
		Status  int             `json:"status"`
		Message interface{}     `json:"message"`
		Data    json.RawMessage `json:"data"`
	}
	err := json.Unmarshal(phpResponse, &response)
	require.NoError(t, err)

	// Parse data field
	var data struct {
		SiteName     string          `json:"site_name"`
		ReceiveChats string          `json:"receive_chats"`
		RandomSend   string          `json:"random_send"`
		RandomMin    string          `json:"random_min"`
		RandomMax    string          `json:"random_max"`
		Messages     []QueuedMessage `json:"messages"`
	}
	err = json.Unmarshal(response.Data, &data)
	require.NoError(t, err, "Real-world PHP response should parse successfully")

	// Verify all messages parsed correctly
	assert.Len(t, data.Messages, 3)
	assert.Equal(t, 5807, data.Messages[0].ID)
	assert.Equal(t, 5808, data.Messages[1].ID)
	assert.Equal(t, 5809, data.Messages[2].ID)

	// Simulate adding to message queue
	queue := NewMessageQueue()
	added := queue.AddBatch(data.Messages)
	assert.Equal(t, 3, added, "All messages should be added to queue")

	// Simulate processing messages
	for i := 0; i < 3; i++ {
		msg := queue.GetNext()
		require.NotNil(t, msg)
		assert.Greater(t, msg.ID, 0, "Message ID should be valid")
		t.Logf("Processed message ID=%d, CID=%d, Phone=%s", msg.ID, msg.CID, msg.Phone)
	}

	assert.True(t, queue.IsEmpty(), "Queue should be empty after processing")

	t.Log("✓ Real-world usage scenario works perfectly!")
}
