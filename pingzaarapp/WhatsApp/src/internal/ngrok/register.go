package ngrok

import (
	"fmt"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/sirupsen/logrus"

	"whatsapp-server/internal/whatsapp"
)

// RegisterWithSites registers the ngrok URL with all configured servers
// Matches Node.js behavior: parallel requests, silent error handling
func RegisterWithSites(sites []string, secretKey string, ngrokURL string, logger *logrus.Logger) {
	if len(sites) == 0 {
		logger.Warn("No servers configured for registration")
		return
	}

	logger.WithFields(logrus.Fields{
		"sites": len(sites),
		"url":   ngrokURL,
	}).Info("Registering ngrok URL with servers...")

	var wg sync.WaitGroup

	// Register with each site in parallel (matches Node.js behavior)
	for _, site := range sites {
		wg.Add(1)
		siteURL := strings.TrimSpace(site)
		whatsapp.SafeGo(logger, "ngrok_register_site", func() {
			defer wg.Done()
			registerSingleSite(siteURL, secretKey, ngrokURL, logger)
		})
	}

	wg.Wait()

	logger.Info("Site registration completed")
}

// registerSingleSite registers the ngrok URL with a single server
func registerSingleSite(siteURL string, secretKey string, ngrokURL string, logger *logrus.Logger) {
	// Trim trailing slashes
	siteURL = strings.TrimRight(siteURL, "/")

	// Build registration URL
	registerURL := fmt.Sprintf("%s/whatsapp/server?key=%s&url=%s&port=443",
		siteURL, secretKey, ngrokURL)

	// Create HTTP client with timeout (5 seconds)
	client := &http.Client{
		Timeout: 5 * time.Second,
	}

	// Create GET request with User-Agent header
	req, err := http.NewRequest("GET", registerURL, nil)
	if err != nil {
		logger.WithFields(logrus.Fields{
			"site":  siteURL,
			"error": err.Error(),
		}).Warn("Failed to create registration request")
		return
	}

	// Add User-Agent header to prevent 406 errors
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36")

	// Make GET request (matches Node.js axios.get behavior)
	resp, err := client.Do(req)
	if err != nil {
		// Silent error handling - log warning but don't fail (matches Node.js)
		logger.WithFields(logrus.Fields{
			"site":  siteURL,
			"error": err.Error(),
		}).Warn("Failed to register with server")
		return
	}
	defer resp.Body.Close()

	// Check response status
	if resp.StatusCode >= 200 && resp.StatusCode < 300 {
		logger.WithFields(logrus.Fields{
			"site":   siteURL,
			"status": resp.StatusCode,
		}).Info("Successfully registered with server")
	} else {
		logger.WithFields(logrus.Fields{
			"site":   siteURL,
			"status": resp.StatusCode,
		}).Warn("Unexpected response from server")
	}
}
