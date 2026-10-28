package whatsapp

import (
	"fmt"
	"strings"

	waLog "go.mau.fi/whatsmeow/util/log"
)

// quietLogger wraps a waLog.Logger and downgrades transient websocket-disconnect
// error messages to Debug. whatsmeow's auto-reconnect typically recovers within
// a second, so these do not represent an actionable error.
type quietLogger struct {
	inner waLog.Logger
}

// noisyErrorMarkers are lowercase substrings that indicate a transient
// disconnect that whatsmeow will recover from on its own.
var noisyErrorMarkers = []string{
	"eof",
	"use of closed network connection",
}

func (q *quietLogger) isNoisyError(formatted string) bool {
	lower := strings.ToLower(formatted)
	for _, marker := range noisyErrorMarkers {
		if strings.Contains(lower, marker) {
			return true
		}
	}
	return false
}

// Errorf routes EOF / closed-connection messages to Debug; everything else is
// forwarded unchanged to the inner logger.
func (q *quietLogger) Errorf(msg string, args ...interface{}) {
	formatted := fmt.Sprintf(msg, args...)
	if q.isNoisyError(formatted) {
		q.inner.Debugf("%s", formatted)
		return
	}
	q.inner.Errorf(msg, args...)
}

func (q *quietLogger) Warnf(msg string, args ...interface{})  { q.inner.Warnf(msg, args...) }
func (q *quietLogger) Infof(msg string, args ...interface{})  { q.inner.Infof(msg, args...) }
func (q *quietLogger) Debugf(msg string, args ...interface{}) { q.inner.Debugf(msg, args...) }

// Sub returns a new quietLogger wrapping the inner logger's sub-logger so the
// filter cascades through the logger hierarchy.
func (q *quietLogger) Sub(module string) waLog.Logger {
	return &quietLogger{inner: q.inner.Sub(module)}
}

// NewQuietClientLog constructs a waLog.Stdout logger wrapped with the
// EOF/closed-connection filter. Intended for the whatsmeow Client logger where
// idle-timeout disconnects are expected and handled automatically.
func NewQuietClientLog(module, level string, colorful bool) waLog.Logger {
	return &quietLogger{inner: waLog.Stdout(module, level, colorful)}
}
