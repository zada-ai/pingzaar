package version

import (
	"context"
	"net/http"
	"testing"
	"time"

	"github.com/sirupsen/logrus"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"go.mau.fi/whatsmeow/store"
)

// TestFetchAndSetVersion_Success tests successful version fetch and set
func TestFetchAndSetVersion_Success(t *testing.T) {
	// Store original version to restore after test
	originalVersion := store.GetWAVersion()
	defer store.SetWAVersion(originalVersion)

	logger := logrus.New()
	logger.SetLevel(logrus.DebugLevel)

	fetcher := NewFetcher(logger)

	// Use real fetch (integration test)
	err := fetcher.FetchAndSet(context.Background())

	require.NoError(t, err, "Should fetch version successfully")

	// Verify version was set (should be different from zero)
	newVersion := store.GetWAVersion()
	assert.False(t, newVersion.IsZero(), "Version should not be zero")
	assert.Equal(t, uint32(2), newVersion[0], "Major version should be 2")
	assert.Equal(t, uint32(3000), newVersion[1], "Minor version should be 3000")
}

// TestFetchAndSetVersion_WithTimeout tests timeout handling
func TestFetchAndSetVersion_WithTimeout(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	fetcher := NewFetcher(logger, WithTimeout(1*time.Nanosecond))

	// Very short timeout should cause context deadline exceeded
	err := fetcher.FetchAndSet(context.Background())

	assert.Error(t, err, "Should fail with extremely short timeout")
}

// TestFetchAndSetVersion_ContextCancelled tests context cancellation
func TestFetchAndSetVersion_ContextCancelled(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	fetcher := NewFetcher(logger)

	ctx, cancel := context.WithCancel(context.Background())
	cancel() // Cancel immediately

	err := fetcher.FetchAndSet(ctx)

	assert.Error(t, err, "Should fail with cancelled context")
}

// TestFetchAndSetVersion_GracefulFallback tests graceful degradation on failure
func TestFetchAndSetVersion_GracefulFallback(t *testing.T) {
	// Store original version
	originalVersion := store.GetWAVersion()
	defer store.SetWAVersion(originalVersion)

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	fetcher := NewFetcher(logger, WithTimeout(1*time.Nanosecond))

	// Should fail but not panic
	err := fetcher.FetchAndSet(context.Background())

	assert.Error(t, err, "Should return error")

	// Version should remain unchanged (fallback behavior)
	currentVersion := store.GetWAVersion()
	assert.Equal(t, originalVersion, currentVersion,
		"Version should remain unchanged on failure")
}

// TestFetchAndSetVersion_WithCustomHTTPClient tests custom HTTP client injection
func TestFetchAndSetVersion_WithCustomHTTPClient(t *testing.T) {
	// Store original version
	originalVersion := store.GetWAVersion()
	defer store.SetWAVersion(originalVersion)

	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	// Custom client with very short timeout
	customClient := &http.Client{
		Timeout: 1 * time.Nanosecond,
	}

	fetcher := NewFetcher(logger, WithHTTPClient(customClient))

	err := fetcher.FetchAndSet(context.Background())

	assert.Error(t, err, "Should fail with short timeout client")
}

// TestNewFetcher_DefaultOptions tests default option values
func TestNewFetcher_DefaultOptions(t *testing.T) {
	logger := logrus.New()

	fetcher := NewFetcher(logger)

	assert.NotNil(t, fetcher, "Fetcher should not be nil")
	assert.NotNil(t, fetcher.logger, "Logger should be set")
	assert.Equal(t, 10*time.Second, fetcher.timeout, "Default timeout should be 10s")
}

// TestFetch_ReturnsVersionContainer tests Fetch returns correct type
func TestFetch_ReturnsVersionContainer(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	fetcher := NewFetcher(logger)

	result, err := fetcher.Fetch(context.Background())
	if err != nil {
		t.Skip("Skipping test - network unavailable")
	}

	// Verify it's a valid WAVersionContainer [3]uint32
	assert.Equal(t, uint32(2), result[0], "First element should be 2")
	assert.Equal(t, uint32(3000), result[1], "Second element should be 3000")
	assert.NotZero(t, result[2], "Third element should not be zero")
}

// TestMustFetchAndSet_NeverPanics tests that MustFetchAndSet never panics
func TestMustFetchAndSet_NeverPanics(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	// Use a very short timeout to force failure
	fetcher := NewFetcher(logger, WithTimeout(1*time.Nanosecond))

	// Should not panic even on failure
	assert.NotPanics(t, func() {
		fetcher.MustFetchAndSet(context.Background())
	}, "MustFetchAndSet should never panic")
}
