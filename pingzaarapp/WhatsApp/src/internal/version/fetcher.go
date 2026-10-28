package version

import (
	"context"
	"fmt"
	"net/http"
	"time"

	"github.com/sirupsen/logrus"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/store"
)

// Fetcher handles fetching the latest WhatsApp version
type Fetcher struct {
	logger     *logrus.Logger
	httpClient *http.Client
	timeout    time.Duration
}

// Option is a functional option for configuring the Fetcher
type Option func(*Fetcher)

// WithTimeout sets the timeout for version fetching
func WithTimeout(timeout time.Duration) Option {
	return func(f *Fetcher) {
		f.timeout = timeout
	}
}

// WithHTTPClient sets a custom HTTP client
func WithHTTPClient(client *http.Client) Option {
	return func(f *Fetcher) {
		f.httpClient = client
	}
}

// NewFetcher creates a new version fetcher with the given options
func NewFetcher(logger *logrus.Logger, opts ...Option) *Fetcher {
	f := &Fetcher{
		logger:  logger,
		timeout: 10 * time.Second, // Default timeout
	}

	for _, opt := range opts {
		opt(f)
	}

	return f
}

// Fetch retrieves the latest WhatsApp version without setting it
func (f *Fetcher) Fetch(ctx context.Context) (store.WAVersionContainer, error) {
	// Create context with timeout
	ctx, cancel := context.WithTimeout(ctx, f.timeout)
	defer cancel()

	// Use whatsmeow's built-in function to fetch latest version
	latestVersion, err := whatsmeow.GetLatestVersion(ctx, f.httpClient)
	if err != nil {
		return store.WAVersionContainer{}, fmt.Errorf("failed to fetch WhatsApp version: %w", err)
	}

	return *latestVersion, nil
}

// FetchAndSet fetches the latest version and sets it globally
// Returns nil on success, error on failure (does NOT crash - graceful fallback)
func (f *Fetcher) FetchAndSet(ctx context.Context) error {
	f.logger.Info("Fetching latest WhatsApp version...")

	latestVersion, err := f.Fetch(ctx)
	if err != nil {
		f.logger.WithError(err).Warn("Failed to fetch WhatsApp version, using hardcoded version")
		f.logCurrentVersion()
		return err
	}

	// Get current version for comparison
	currentVersion := store.GetWAVersion()

	// Set the new version globally
	store.SetWAVersion(latestVersion)

	f.logger.WithFields(logrus.Fields{
		"previous_version": currentVersion.String(),
		"new_version":      latestVersion.String(),
		"updated":          latestVersion != currentVersion,
	}).Info("WhatsApp version updated")

	return nil
}

// logCurrentVersion logs the current WhatsApp version
func (f *Fetcher) logCurrentVersion() {
	currentVersion := store.GetWAVersion()
	f.logger.WithField("version", currentVersion.String()).
		Info("Using WhatsApp version")
}

// MustFetchAndSet is like FetchAndSet but logs error and continues
// This is a convenience method for startup that never returns an error
func (f *Fetcher) MustFetchAndSet(ctx context.Context) {
	if err := f.FetchAndSet(ctx); err != nil {
		// Error already logged in FetchAndSet, just continue
		// Server will use the hardcoded version
	}
}
