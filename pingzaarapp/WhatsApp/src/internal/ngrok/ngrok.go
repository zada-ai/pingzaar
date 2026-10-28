package ngrok

import (
	"context"
	"fmt"
	"net"

	"github.com/sirupsen/logrus"
	"golang.ngrok.com/ngrok"
	ngrokConfig "golang.ngrok.com/ngrok/config"

	"whatsapp-server/internal/config"
)

// TunnelManager manages ngrok tunnel lifecycle
type TunnelManager struct {
	cfg      *config.Config
	logger   *logrus.Logger
	listener net.Listener
	tunnel   ngrok.Tunnel
	ctx      context.Context
	cancel   context.CancelFunc
}

// NewTunnelManager creates a new ngrok tunnel manager
func NewTunnelManager(cfg *config.Config, logger *logrus.Logger) (*TunnelManager, error) {
	if !cfg.Ngrok.Enabled {
		return nil, fmt.Errorf("ngrok is not enabled in configuration")
	}

	if cfg.Ngrok.Authtoken == "" {
		return nil, fmt.Errorf("ngrok authtoken is required")
	}

	ctx, cancel := context.WithCancel(context.Background())

	return &TunnelManager{
		cfg:    cfg,
		logger: logger,
		ctx:    ctx,
		cancel: cancel,
	}, nil
}

// Start establishes the ngrok tunnel
func (tm *TunnelManager) Start(ctx context.Context) error {
	tm.logger.Info("Connecting to ngrok service...")

	// Create ngrok session with authtoken
	listener, err := ngrok.Listen(ctx,
		ngrokConfig.HTTPEndpoint(),
		ngrok.WithAuthtoken(tm.cfg.Ngrok.Authtoken),
	)

	if err != nil {
		tm.logger.WithError(err).Error("Failed to establish ngrok tunnel")
		return fmt.Errorf("ngrok connection failed: %w", err)
	}

	tm.listener = listener
	tm.tunnel = listener.(ngrok.Tunnel)

	return nil
}

// GetPublicURL returns the public URL of the ngrok tunnel
func (tm *TunnelManager) GetPublicURL() string {
	if tm.tunnel == nil {
		return ""
	}
	return tm.tunnel.URL()
}

// GetListener returns the ngrok listener for serving HTTP traffic
func (tm *TunnelManager) GetListener() net.Listener {
	return tm.listener
}

// Close gracefully closes the ngrok tunnel
func (tm *TunnelManager) Close() error {
	if tm.cancel != nil {
		tm.cancel()
	}

	if tm.listener != nil {
		if err := tm.listener.Close(); err != nil {
			tm.logger.WithError(err).Warn("Error closing ngrok listener")
			return err
		}
	}

	tm.logger.Info("Ngrok tunnel closed successfully")
	return nil
}

// IsConnected checks if the tunnel is currently connected
func (tm *TunnelManager) IsConnected() bool {
	return tm.tunnel != nil && tm.GetPublicURL() != ""
}
