package helpers

import "github.com/gin-gonic/gin"

// Response sends a standardized JSON response matching the Node.js format:
// { status: int, message: string|false, data: any|false }
func Response(c *gin.Context, status int, message interface{}, data interface{}) {
	// Ensure message is string or false
	if message == nil || message == "" {
		message = false
	}

	// Ensure data is never nil (use false instead)
	if data == nil {
		data = false
	}

	c.JSON(status, gin.H{
		"status":  status,
		"message": message,
		"data":    data,
	})
}
