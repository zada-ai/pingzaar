package whatsapp

import (
	"fmt"
	"io"
	"sync"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	waLog "go.mau.fi/whatsmeow/util/log"
)

// recordingLogger is a waLog.Logger fake that captures every call for assertion.
type recordingLogger struct {
	mu    sync.Mutex
	calls []loggedCall
	mod   string
}

type loggedCall struct {
	Level     string
	Formatted string
	Module    string
}

func (r *recordingLogger) record(level, msg string, args ...interface{}) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.calls = append(r.calls, loggedCall{
		Level:     level,
		Formatted: fmt.Sprintf(msg, args...),
		Module:    r.mod,
	})
}

func (r *recordingLogger) Errorf(msg string, args ...interface{}) { r.record("ERROR", msg, args...) }
func (r *recordingLogger) Warnf(msg string, args ...interface{})  { r.record("WARN", msg, args...) }
func (r *recordingLogger) Infof(msg string, args ...interface{})  { r.record("INFO", msg, args...) }
func (r *recordingLogger) Debugf(msg string, args ...interface{}) { r.record("DEBUG", msg, args...) }
func (r *recordingLogger) Sub(module string) waLog.Logger {
	return &recordingLogger{mu: sync.Mutex{}, mod: module, calls: nil}
}

func (r *recordingLogger) snapshot() []loggedCall {
	r.mu.Lock()
	defer r.mu.Unlock()
	out := make([]loggedCall, len(r.calls))
	copy(out, r.calls)
	return out
}

func countLevel(calls []loggedCall, level string) int {
	n := 0
	for _, c := range calls {
		if c.Level == level {
			n++
		}
	}
	return n
}

func TestQuietLogger_Errorf_DowngradesEOFToDebug(t *testing.T) {
	rec := &recordingLogger{}
	q := &quietLogger{inner: rec}

	q.Errorf("Error reading from websocket: %v", io.EOF)

	calls := rec.snapshot()
	require.Len(t, calls, 1)
	assert.Equal(t, "DEBUG", calls[0].Level, "EOF error should be downgraded to Debug")
	assert.Contains(t, calls[0].Formatted, "EOF")
}

func TestQuietLogger_Errorf_DowngradesUnexpectedEOF(t *testing.T) {
	rec := &recordingLogger{}
	q := &quietLogger{inner: rec}

	q.Errorf("failed to read frame header: unexpected EOF")

	calls := rec.snapshot()
	require.Len(t, calls, 1)
	assert.Equal(t, "DEBUG", calls[0].Level)
}

func TestQuietLogger_Errorf_DowngradesClosedConnection(t *testing.T) {
	rec := &recordingLogger{}
	q := &quietLogger{inner: rec}

	q.Errorf("socket error: %s", "use of closed network connection")

	calls := rec.snapshot()
	require.Len(t, calls, 1)
	assert.Equal(t, "DEBUG", calls[0].Level)
}

func TestQuietLogger_Errorf_ForwardsNonEOFErrors(t *testing.T) {
	rec := &recordingLogger{}
	q := &quietLogger{inner: rec}

	q.Errorf("pairing failed: %v", fmt.Errorf("something else"))

	calls := rec.snapshot()
	require.Len(t, calls, 1)
	assert.Equal(t, "ERROR", calls[0].Level, "non-EOF error should stay at Error level")
}

func TestQuietLogger_Warnf_PassesThrough(t *testing.T) {
	rec := &recordingLogger{}
	q := &quietLogger{inner: rec}

	q.Warnf("some warning: %d", 42)
	q.Infof("some info")
	q.Debugf("some debug")

	calls := rec.snapshot()
	require.Len(t, calls, 3)
	assert.Equal(t, "WARN", calls[0].Level)
	assert.Equal(t, "INFO", calls[1].Level)
	assert.Equal(t, "DEBUG", calls[2].Level)
}

func TestQuietLogger_Sub_CascadesFilter(t *testing.T) {
	rec := &recordingLogger{}
	q := &quietLogger{inner: rec}

	child := q.Sub("child")

	// Child must be a quietLogger to preserve the filter.
	_, ok := child.(*quietLogger)
	require.True(t, ok, "Sub should return *quietLogger so filter cascades")
}

func TestQuietLogger_Errorf_EmptyArgsFormattedCorrectly(t *testing.T) {
	rec := &recordingLogger{}
	q := &quietLogger{inner: rec}

	q.Errorf("raw EOF message")

	calls := rec.snapshot()
	require.Len(t, calls, 1)
	assert.Equal(t, "DEBUG", calls[0].Level)
}

func TestNewQuietClientLog_ReturnsQuietLogger(t *testing.T) {
	l := NewQuietClientLog("TestClient", "ERROR", false)
	_, ok := l.(*quietLogger)
	require.True(t, ok, "NewQuietClientLog should return a *quietLogger")
}
