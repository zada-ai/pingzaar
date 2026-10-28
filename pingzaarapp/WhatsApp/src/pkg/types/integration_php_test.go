package types

import (
	"encoding/json"
	"fmt"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestIntegration_FullPHPResponseParsing tests parsing complete PHP response
func TestIntegration_FullPHPResponseParsing(t *testing.T) {
	// This is the EXACT format from PHP server that caused both errors
	phpResponse := `{
		"status": 200,
		"message": false,
		"data": {
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
		}
	}`

	// Step 1: Parse outer response structure
	var response PHPMessagesResponse
	err := json.Unmarshal([]byte(phpResponse), &response)
	require.NoError(t, err, "Outer response should parse")

	// Step 2: Parse data field (THIS WAS FAILING WITH BOTH ERRORS)
	var data PHPMessagesData
	err = json.Unmarshal(response.Data, &data)
	require.NoError(t, err, "Data field should parse (WAS FAILING BEFORE FIX)")

	// Step 3: Verify config fields parsed correctly
	assert.Equal(t, "SMS", data.SiteName)
	assert.Equal(t, "2", data.ReceiveChats, "Numeric 2 should become string '2'")
	assert.Equal(t, "1", data.RandomSend, "Numeric 1 should become string '1'")
	assert.Equal(t, "1", data.RandomMin, "Numeric 1 should become string '1'")
	assert.Equal(t, "5", data.RandomMax, "Numeric 5 should become string '5'")

	// Step 4: Verify messages parsed correctly
	require.Len(t, data.Messages, 1)
	msg := data.Messages[0]
	assert.Equal(t, 5807, msg.ID, "String '5807' should become int 5807")
	assert.Equal(t, 0, msg.CID, "String '0' should become int 0")
	assert.Equal(t, "+5213349426465", msg.Phone)
	assert.Equal(t, `{"text":"This is a test message"}`, msg.Message)

	t.Log("✓ Full PHP response parsing works end-to-end!")
}

// TestIntegration_UpdateSessionFlow tests the full update session flow
func TestIntegration_UpdateSessionFlow(t *testing.T) {
	// Simulate POST request from PHP frontend
	postData := `{
		"wsid": "user1_site2_session123",
		"receive_chats": 3,
		"random_send": 0,
		"random_min": 2,
		"random_max": 8
	}`

	// Parse request
	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(postData), &req)
	require.NoError(t, err, "POST data should parse")

	// Verify fields
	assert.Equal(t, "user1_site2_session123", req.WSID)
	assert.Equal(t, "3", req.ReceiveChats, "Numeric 3 should become string '3'")
	assert.Equal(t, "0", req.RandomSend, "Numeric 0 should become string '0'")
	assert.Equal(t, "2", req.RandomMin, "Numeric 2 should become string '2'")
	assert.Equal(t, "8", req.RandomMax, "Numeric 8 should become string '8'")

	t.Log("✓ Update session request flow works!")
}

// TestIntegration_MixedPayloadTypes tests PHP sending both formats in same response
func TestIntegration_MixedPayloadTypes(t *testing.T) {
	// Real-world scenario: PHP mixes string and numeric types inconsistently
	phpResponse := `{
		"status": 200,
		"message": false,
		"data": {
			"site_name": "Mixed Site",
			"receive_chats": 2,
			"random_send": "1",
			"random_min": 5,
			"random_max": "10",
			"messages": [
				{"id": "100", "cid": 1, "phone": "+1111", "message": "{}", "wid": "test1@s.whatsapp.net"},
				{"id": 101, "cid": "2", "phone": "+2222", "message": "{}", "wid": "test2@s.whatsapp.net"}
			]
		}
	}`

	var response PHPMessagesResponse
	err := json.Unmarshal([]byte(phpResponse), &response)
	require.NoError(t, err)

	var data PHPMessagesData
	err = json.Unmarshal(response.Data, &data)
	require.NoError(t, err, "Mixed types should parse successfully")

	// Verify config fields normalized to strings
	assert.Equal(t, "2", data.ReceiveChats, "Number → string")
	assert.Equal(t, "1", data.RandomSend, "String preserved")
	assert.Equal(t, "5", data.RandomMin, "Number → string")
	assert.Equal(t, "10", data.RandomMax, "String preserved")

	// Verify messages with mixed ID types
	require.Len(t, data.Messages, 2)
	assert.Equal(t, 100, data.Messages[0].ID, "String ID → int")
	assert.Equal(t, 1, data.Messages[0].CID, "Numeric CID preserved")
	assert.Equal(t, 101, data.Messages[1].ID, "Numeric ID preserved")
	assert.Equal(t, 2, data.Messages[1].CID, "String CID → int")

	t.Log("✓ Mixed payload types handled correctly!")
}

// TestIntegration_EmptyResponse tests PHP returning no messages
func TestIntegration_EmptyResponse(t *testing.T) {
	phpResponse := `{
		"status": 200,
		"message": false,
		"data": {
			"site_name": "Empty Site",
			"receive_chats": 2,
			"random_send": 1,
			"random_min": 1,
			"random_max": 5,
			"messages": []
		}
	}`

	var response PHPMessagesResponse
	err := json.Unmarshal([]byte(phpResponse), &response)
	require.NoError(t, err)

	var data PHPMessagesData
	err = json.Unmarshal(response.Data, &data)
	require.NoError(t, err)

	assert.Equal(t, "2", data.ReceiveChats)
	assert.Empty(t, data.Messages, "Empty messages array should be handled")

	t.Log("✓ Empty response handled correctly!")
}

// TestIntegration_ErrorResponse tests PHP error response format
func TestIntegration_ErrorResponse(t *testing.T) {
	// PHP returns boolean false for data field on error
	phpResponse := `{
		"status": 400,
		"message": "Invalid session",
		"data": false
	}`

	var response PHPMessagesResponse
	err := json.Unmarshal([]byte(phpResponse), &response)
	require.NoError(t, err, "Error response should parse outer structure")

	assert.Equal(t, 400, response.Status)
	assert.Equal(t, "Invalid session", response.Message)

	// Attempt to parse data - should fail gracefully
	var data PHPMessagesData
	err = json.Unmarshal(response.Data, &data)
	assert.Error(t, err, "Boolean data field should not parse as PHPMessagesData")

	t.Log("✓ Error response handled correctly!")
}

// TestIntegration_LargeMessageBatch tests parsing many messages at once
func TestIntegration_LargeMessageBatch(t *testing.T) {
	// Build response with 50 messages
	messagesJSON := `[`
	for i := 1; i <= 50; i++ {
		if i > 1 {
			messagesJSON += `,`
		}
		// Mix string and numeric IDs
		var idValue string
		if i%2 == 0 {
			idValue = fmt.Sprintf("%d", i) // Numeric ID
		} else {
			idValue = fmt.Sprintf(`"%d"`, i) // String ID
		}
		messagesJSON += `{"id": ` + idValue + `, "cid": "0", "phone": "+1234", "message": "{}", "wid": "test@s.whatsapp.net"}`
	}
	messagesJSON += `]`

	phpResponse := `{
		"status": 200,
		"message": false,
		"data": {
			"site_name": "Batch Site",
			"receive_chats": 2,
			"random_send": 1,
			"random_min": 1,
			"random_max": 5,
			"messages": ` + messagesJSON + `
		}
	}`

	var response PHPMessagesResponse
	err := json.Unmarshal([]byte(phpResponse), &response)
	require.NoError(t, err)

	var data PHPMessagesData
	err = json.Unmarshal(response.Data, &data)
	require.NoError(t, err, "Large batch should parse")

	assert.Equal(t, "2", data.ReceiveChats)
	assert.Len(t, data.Messages, 50, "All 50 messages should be parsed")

	// Verify first and last messages
	assert.Equal(t, 1, data.Messages[0].ID)
	assert.Equal(t, 50, data.Messages[49].ID)

	t.Log("✓ Large message batch handled correctly!")
}

// TestIntegration_QueueOperations tests message queue integration
func TestIntegration_QueueOperations(t *testing.T) {
	phpResponse := `{
		"status": 200,
		"message": false,
		"data": {
			"site_name": "Queue Site",
			"receive_chats": 2,
			"random_send": "1",
			"random_min": 1,
			"random_max": "5",
			"messages": [
				{"id": "1001", "cid": "100", "phone": "+1111", "message": "{\"text\":\"Msg 1\"}", "priority": "0", "wid": "test1@s.whatsapp.net"},
				{"id": "1002", "cid": "100", "phone": "+2222", "message": "{\"text\":\"Msg 2\"}", "priority": 1, "wid": "test2@s.whatsapp.net"},
				{"id": "1003", "cid": "100", "phone": "+3333", "message": "{\"text\":\"Msg 3\"}", "priority": "0", "wid": "test3@s.whatsapp.net"}
			]
		}
	}`

	// Parse response
	var response PHPMessagesResponse
	err := json.Unmarshal([]byte(phpResponse), &response)
	require.NoError(t, err)

	var data PHPMessagesData
	err = json.Unmarshal(response.Data, &data)
	require.NoError(t, err)

	// Create message queue and add messages
	queue := NewMessageQueue()
	added := queue.AddBatch(data.Messages)
	assert.Equal(t, 3, added, "All messages should be added")

	// Verify queue operations
	assert.Equal(t, 3, queue.Size())

	msg1 := queue.GetNext()
	require.NotNil(t, msg1)
	assert.Equal(t, 1001, msg1.ID)
	assert.Equal(t, 0, msg1.Priority, "String '0' should become int 0")

	msg2 := queue.GetNext()
	require.NotNil(t, msg2)
	assert.Equal(t, 1002, msg2.ID)
	assert.Equal(t, 1, msg2.Priority, "Numeric 1 preserved")

	t.Log("✓ Queue operations with parsed messages work!")
}

// TestIntegration_ConfigCompatibility tests config values work with parseIntSafe
func TestIntegration_ConfigCompatibility(t *testing.T) {
	phpResponse := `{
		"status": 200,
		"message": false,
		"data": {
			"site_name": "Config Site",
			"receive_chats": 3,
			"random_send": 0,
			"random_min": 2,
			"random_max": 10,
			"messages": []
		}
	}`

	var response PHPMessagesResponse
	err := json.Unmarshal([]byte(phpResponse), &response)
	require.NoError(t, err)

	var data PHPMessagesData
	err = json.Unmarshal(response.Data, &data)
	require.NoError(t, err)

	// Simulate parseIntSafe calls from fetcher.go
	receiveChats := parseIntSafe(data.ReceiveChats, 2)
	randomSend := parseIntSafe(data.RandomSend, 1)
	randomMin := parseIntSafe(data.RandomMin, 1)
	randomMax := parseIntSafe(data.RandomMax, 5)

	// Verify conversion works correctly
	assert.Equal(t, 3, receiveChats, "String '3' should parse to int 3")
	assert.Equal(t, 0, randomSend, "String '0' should parse to int 0")
	assert.Equal(t, 2, randomMin, "String '2' should parse to int 2")
	assert.Equal(t, 10, randomMax, "String '10' should parse to int 10")

	t.Log("✓ Config values compatible with parseIntSafe!")
}

// TestIntegration_MultipleResponseCycles tests parsing multiple responses
func TestIntegration_MultipleResponseCycles(t *testing.T) {
	// Simulate fetcher polling PHP server multiple times
	responses := []string{
		`{
			"status": 200,
			"message": false,
			"data": {
				"site_name": "Site1",
				"receive_chats": 2,
				"random_send": "1",
				"random_min": 1,
				"random_max": "5",
				"messages": [
					{"id": "100", "cid": "1", "phone": "+1111", "message": "{}", "wid": "test1@s.whatsapp.net"}
				]
			}
		}`,
		`{
			"status": 200,
			"message": false,
			"data": {
				"site_name": "Site2",
				"receive_chats": 3,
				"random_send": 0,
				"random_min": 2,
				"random_max": 8,
				"messages": [
					{"id": 101, "cid": 2, "phone": "+2222", "message": "{}", "wid": "test2@s.whatsapp.net"}
				]
			}
		}`,
		`{
			"status": 200,
			"message": false,
			"data": {
				"site_name": "Site3",
				"receive_chats": "1",
				"random_send": "1",
				"random_min": "1",
				"random_max": "3",
				"messages": []
			}
		}`,
	}

	totalMessages := 0
	for i, respJSON := range responses {
		var response PHPMessagesResponse
		err := json.Unmarshal([]byte(respJSON), &response)
		require.NoError(t, err, "Response %d should parse", i+1)

		var data PHPMessagesData
		err = json.Unmarshal(response.Data, &data)
		require.NoError(t, err, "Data %d should parse", i+1)

		// All config fields should be strings
		assert.IsType(t, "", data.ReceiveChats, "ReceiveChats should be string in response %d", i+1)
		assert.IsType(t, "", data.RandomSend, "RandomSend should be string in response %d", i+1)

		totalMessages += len(data.Messages)
	}

	assert.Equal(t, 2, totalMessages, "Should have parsed 2 messages across 3 responses")

	t.Log("✓ Multiple response cycles handled correctly!")
}

// TestIntegration_UpdateAndFetchFlow tests update session then fetch messages
func TestIntegration_UpdateAndFetchFlow(t *testing.T) {
	// Step 1: Update session settings
	updateReq := `{
		"wsid": "integration_test_session",
		"receive_chats": 2,
		"random_send": 1,
		"random_min": 3,
		"random_max": 10
	}`

	var req UpdateSessionRequest
	err := json.Unmarshal([]byte(updateReq), &req)
	require.NoError(t, err, "Update request should parse")

	assert.Equal(t, "2", req.ReceiveChats)
	assert.Equal(t, "1", req.RandomSend)

	// Step 2: Fetch messages with same settings
	fetchResp := `{
		"status": 200,
		"message": false,
		"data": {
			"site_name": "Integration Site",
			"receive_chats": 2,
			"random_send": 1,
			"random_min": 3,
			"random_max": 10,
			"messages": [
				{"id": "5000", "cid": "50", "phone": "+9999", "message": "{}", "wid": "test@s.whatsapp.net"}
			]
		}
	}`

	var response PHPMessagesResponse
	err = json.Unmarshal([]byte(fetchResp), &response)
	require.NoError(t, err)

	var data PHPMessagesData
	err = json.Unmarshal(response.Data, &data)
	require.NoError(t, err, "Fetch response should parse")

	// Verify settings match
	assert.Equal(t, req.ReceiveChats, data.ReceiveChats)
	assert.Equal(t, req.RandomSend, data.RandomSend)
	assert.Equal(t, req.RandomMin, data.RandomMin)
	assert.Equal(t, req.RandomMax, data.RandomMax)

	// Verify message
	require.Len(t, data.Messages, 1)
	assert.Equal(t, 5000, data.Messages[0].ID)

	t.Log("✓ Update and fetch flow works end-to-end!")
}

// parseIntSafe helper function (copied from fetcher.go for testing)
func parseIntSafe(s string, defaultVal int) int {
	if s == "" {
		return defaultVal
	}
	var result int
	_, err := fmt.Sscanf(s, "%d", &result)
	if err != nil {
		return defaultVal
	}
	return result
}
