package whatsapp

import (
	"runtime/debug"
	"strconv"

	"github.com/sirupsen/logrus"
)

// SafeGo launches a goroutine with panic recovery to prevent server crashes.
// Any panic in the goroutine will be logged but won't crash the entire server.
func SafeGo(logger *logrus.Logger, name string, fn func()) {
	go func() {
		defer func() {
			if r := recover(); r != nil {
				logger.WithFields(logrus.Fields{
					"panic":     r,
					"goroutine": name,
					"stack":     string(debug.Stack()),
				}).Error("Goroutine panic recovered - server continues running")
			}
		}()
		fn()
	}()
}

// parseIntSafe safely converts a string to int with a default fallback.
// Used for parsing PHP responses that send numeric values as strings.
// Returns defaultValue if the string is empty or cannot be parsed.
func parseIntSafe(value string, defaultValue int) int {
	if value == "" {
		return defaultValue
	}
	if intValue, err := strconv.Atoi(value); err == nil {
		return intValue
	}
	return defaultValue
}
