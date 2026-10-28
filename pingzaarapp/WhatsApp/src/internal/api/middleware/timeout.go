// Package middleware holds gin middleware shared across API routes.
package middleware

import (
	"context"
	"errors"
	"net/http"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
)

// Timeout returns a gin middleware that applies a per-request deadline to the
// request context. Handlers that honour c.Request.Context() will see
// cancellation when the deadline fires. If the handler returns after the
// deadline without having written a response, the middleware replies with
// 504 Gateway Timeout. Paths whose gin FullPath starts with any of
// skipPrefixes bypass the deadline entirely (e.g. long-lived QR scans or
// large media downloads).
func Timeout(d time.Duration, skipPrefixes ...string) gin.HandlerFunc {
	return func(c *gin.Context) {
		fullPath := c.FullPath()
		for _, prefix := range skipPrefixes {
			if prefix != "" && strings.HasPrefix(fullPath, prefix) {
				c.Next()
				return
			}
		}

		ctx, cancel := context.WithTimeout(c.Request.Context(), d)
		defer cancel()
		c.Request = c.Request.WithContext(ctx)

		c.Next()

		// Only emit a 504 if the handler did NOT already start writing a
		// response. Overwriting a streamed response would corrupt the wire
		// (e.g. /files/download serving a binary).
		if errors.Is(ctx.Err(), context.DeadlineExceeded) && !c.Writer.Written() {
			c.AbortWithStatusJSON(http.StatusGatewayTimeout, gin.H{
				"error": "request timed out",
			})
		}
	}
}
