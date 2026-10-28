package main

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/sirupsen/logrus"
	_ "modernc.org/sqlite"

	"whatsapp-server/internal/api"
	"whatsapp-server/internal/config"
	"whatsapp-server/internal/logging"
	"whatsapp-server/internal/ngrok"
	"whatsapp-server/internal/storage"
	"whatsapp-server/internal/version"
	"whatsapp-server/internal/whatsapp"
)

// Version of the WhatsApp Server.
//
// Deliberately a var, not a const, so the Makefile can stamp it at build time
// with -X main.Version. It defaults to the Zender version file, so a release
// binary always reports the same version as the platform it ships inside.
// This placeholder only ever survives into a build made outside the Makefile.
var Version = "0.0.0-dev"

func main() {
	// Setup logger
	logger := logrus.New()
	logger.SetFormatter(&logging.PrettyFormatter{
		TimestampFormat: "15:04:05.000",
	})

	// Load configuration with CLI flags support (CLI takes priority)
	cfg, err := config.LoadWithCLI(Version)
	if err != nil {
		fmt.Printf("\n❌ ERROR: %s\n\n", err)
		fmt.Println("Run with --help to see usage information")
		os.Exit(1)
	}

	// Fetch latest WhatsApp version before any sessions are created
	// Uses graceful fallback - never crashes on failure
	versionFetcher := version.NewFetcher(logger)
	versionFetcher.MustFetchAndSet(context.Background())

	if cfg.Server.Debug {
		logger.SetLevel(logrus.DebugLevel)
		logger.Info("Debug mode enabled")
	}

	logger.WithFields(logrus.Fields{
		"version": Version,
		"port":    cfg.Server.Port,
	}).Info("Starting WhatsApp Server")

	// Initialize storage
	_, err = storage.NewStore("./storage", logger)
	if err != nil {
		logger.WithError(err).Fatal("Failed to initialize storage")
	}

	// Initialize session manager
	sessionMgr, err := whatsapp.NewSessionManager(cfg, logger)
	if err != nil {
		logger.WithError(err).Fatal("Failed to create session manager")
	}

	// Run startup cleanup to remove stale sessions and orphaned files
	logger.Info("Running startup cleanup...")
	if err := sessionMgr.StartupCleanup(context.Background()); err != nil {
		logger.WithError(err).Warn("Startup cleanup completed with errors (non-critical, continuing)")
	}

	// Recover existing sessions from database
	if err := sessionMgr.RecoverSessions(context.Background()); err != nil {
		logger.WithError(err).Error("Failed to recover sessions")
	}

	// Start periodic cleanup for inactive sessions (24h threshold)
	whatsapp.SafeGo(logger, "session_cleanup", func() {
		// Run immediate cleanup check for recovered sessions
		logger.Info("Running immediate session cleanup check")
		sessionMgr.CleanupSessions()

		// Then start periodic cleanup (every 1 hour)
		ticker := time.NewTicker(1 * time.Hour)
		defer ticker.Stop()

		logger.Info("Started periodic session cleanup (checks every 1 hour, deletes after 24h inactivity)")

		for range ticker.C {
			sessionMgr.CleanupSessions()
		}
	})

	// Setup HTTP router
	router := api.SetupRouter(cfg, sessionMgr, logger, Version)

	// Create HTTP server
	server := &http.Server{
		Handler:      router,
		ReadTimeout:  30 * time.Second,
		WriteTimeout: 30 * time.Second,
		IdleTimeout:  120 * time.Second,
	}

	// Start ngrok tunnel if enabled
	var tunnelMgr *ngrok.TunnelManager
	var serverHost string

	if cfg.Ngrok.Enabled {
		logger.Info("Starting ngrok tunnel...")
		tunnelMgr, err = ngrok.NewTunnelManager(cfg, logger)
		if err != nil {
			logger.WithError(err).Fatal("Failed to initialize ngrok tunnel")
		}

		if err := tunnelMgr.Start(context.Background()); err != nil {
			logger.WithError(err).Fatal("Ngrok connection failed")
		}

		publicURL := tunnelMgr.GetPublicURL()
		logger.WithField("url", publicURL).Info("Ngrok tunnel established")
		serverHost = publicURL

		// Start server with ngrok listener
		whatsapp.SafeGo(logger, "http_server_ngrok", func() {
			logger.Info("HTTP server serving on ngrok tunnel")
			if err := server.Serve(tunnelMgr.GetListener()); err != nil && err != http.ErrServerClosed {
				logger.WithError(err).Fatal("HTTP server failed")
			}
		})

		// Small delay to ensure tunnel is fully established
		time.Sleep(500 * time.Millisecond)

		// Register with servers (parallel, non-blocking)
		whatsapp.SafeGo(logger, "ngrok_register", func() {
			ngrok.RegisterWithSites(cfg.Ngrok.Sites, cfg.Server.Secret, publicURL, logger)
		})
	} else {
		// Normal server startup without ngrok
		serverHost = cfg.Server.Host
		server.Addr = fmt.Sprintf("%s:%d", cfg.Server.Host, cfg.Server.Port)

		whatsapp.SafeGo(logger, "http_server", func() {
			logger.WithFields(logrus.Fields{
				"address": server.Addr,
			}).Info("HTTP server starting")

			if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
				logger.WithError(err).Fatal("HTTP server failed to start")
			}
		})
	}

	// Print startup info
	fmt.Println()
	fmt.Printf("💬 WHATSAPP SERVER IS RUNNING (v%s)\n", Version)
	if cfg.Ngrok.Enabled {
		fmt.Println("Requests are being handled by Ngrok service.")
		fmt.Println("The following has been saved automatically to your server settings.")
		fmt.Printf("📡 Server URL:  %s\n", serverHost)
		fmt.Printf("🔌 Server Port: 443\n")
	} else {
		fmt.Println("Please make sure that the server is accessible from the internet.")
		fmt.Printf("📡 Server URL:  http://%s\n", serverHost)
		fmt.Printf("🔌 Server Port: %d\n", cfg.Server.Port)
	}
	fmt.Println()

	// Wait for interrupt signal to gracefully shutdown
	quit := make(chan os.Signal, 1)
	signal.Notify(quit, os.Interrupt, syscall.SIGTERM)
	<-quit

	logger.Info("Shutting down server...")

	// Close ngrok tunnel first
	if tunnelMgr != nil {
		logger.Info("Closing ngrok tunnel...")
		if err := tunnelMgr.Close(); err != nil {
			logger.WithError(err).Warn("Error closing ngrok tunnel")
		}
	}

	// Give outstanding requests 30 seconds to complete
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	// Attempt graceful shutdown
	if err := server.Shutdown(ctx); err != nil {
		logger.WithError(err).Error("Server forced to shutdown")
	} else {
		logger.Info("Server exited gracefully")
	}

	fmt.Println("👋 Server shutdown complete")
}
