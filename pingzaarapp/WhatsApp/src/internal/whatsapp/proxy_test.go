package whatsapp

import (
	"testing"

	"github.com/sirupsen/logrus"
	"whatsapp-server/internal/config"
)

func TestProxyDialer_Disabled(t *testing.T) {
	cfg := &config.Config{}
	cfg.Proxy.Enabled = false

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out) // Suppress test output
	pd := NewProxyDialer(cfg, logger)

	if pd.IsEnabled() {
		t.Fatal("Proxy should be disabled")
	}

	dialer, err := pd.CreateSOCKS5Dialer()
	if err != nil {
		t.Fatalf("Failed to create dialer for disabled proxy: %v", err)
	}
	if dialer == nil {
		t.Fatal("Dialer should not be nil even when disabled")
	}
}

func TestProxyDialer_SOCKS5_NoAuth(t *testing.T) {
	cfg := &config.Config{}
	cfg.Proxy.Enabled = true
	cfg.Proxy.Host = "127.0.0.1"
	cfg.Proxy.Port = 1080
	cfg.Proxy.Protocol = "socks5"

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out)
	pd := NewProxyDialer(cfg, logger)

	if !pd.IsEnabled() {
		t.Fatal("Proxy should be enabled")
	}

	if pd.GetProtocol() != "socks5" {
		t.Fatalf("Expected protocol 'socks5', got '%s'", pd.GetProtocol())
	}

	dialer, err := pd.CreateSOCKS5Dialer()
	if err != nil {
		t.Fatalf("Failed to create SOCKS5 dialer: %v", err)
	}
	if dialer == nil {
		t.Fatal("Dialer is nil")
	}
}

func TestProxyDialer_SOCKS5_WithAuth(t *testing.T) {
	cfg := &config.Config{}
	cfg.Proxy.Enabled = true
	cfg.Proxy.Host = "proxy.example.com"
	cfg.Proxy.Port = 1080
	cfg.Proxy.Protocol = "socks5"
	cfg.Proxy.Username = "testuser"
	cfg.Proxy.Password = "testpass"

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out)
	pd := NewProxyDialer(cfg, logger)

	dialer, err := pd.CreateSOCKS5Dialer()
	if err != nil {
		t.Fatalf("Failed to create SOCKS5 dialer with auth: %v", err)
	}
	if dialer == nil {
		t.Fatal("Dialer is nil")
	}
}

func TestProxyDialer_HTTP_NoAuth(t *testing.T) {
	cfg := &config.Config{}
	cfg.Proxy.Enabled = true
	cfg.Proxy.Host = "10.0.0.5"
	cfg.Proxy.Port = 8080
	cfg.Proxy.Protocol = "http"

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out)
	pd := NewProxyDialer(cfg, logger)

	if pd.GetProtocol() != "http" {
		t.Fatalf("Expected protocol 'http', got '%s'", pd.GetProtocol())
	}

	proxyFunc, err := pd.CreateHTTPProxy()
	if err != nil {
		t.Fatalf("Failed to create HTTP proxy: %v", err)
	}
	if proxyFunc == nil {
		t.Fatal("Proxy function is nil")
	}
}

func TestProxyDialer_HTTP_WithAuth(t *testing.T) {
	cfg := &config.Config{}
	cfg.Proxy.Enabled = true
	cfg.Proxy.Host = "proxy.corp.com"
	cfg.Proxy.Port = 3128
	cfg.Proxy.Protocol = "http"
	cfg.Proxy.Username = "corpuser"
	cfg.Proxy.Password = "corppass"

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out)
	pd := NewProxyDialer(cfg, logger)

	proxyFunc, err := pd.CreateHTTPProxy()
	if err != nil {
		t.Fatalf("Failed to create HTTP proxy with auth: %v", err)
	}
	if proxyFunc == nil {
		t.Fatal("Proxy function is nil")
	}
}

func TestProxyDialer_HTTPS(t *testing.T) {
	cfg := &config.Config{}
	cfg.Proxy.Enabled = true
	cfg.Proxy.Host = "secure-proxy.com"
	cfg.Proxy.Port = 443
	cfg.Proxy.Protocol = "https"

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out)
	pd := NewProxyDialer(cfg, logger)

	if pd.GetProtocol() != "https" {
		t.Fatalf("Expected protocol 'https', got '%s'", pd.GetProtocol())
	}

	proxyFunc, err := pd.CreateHTTPProxy()
	if err != nil {
		t.Fatalf("Failed to create HTTPS proxy: %v", err)
	}
	if proxyFunc == nil {
		t.Fatal("Proxy function is nil")
	}
}

func TestProxyDialer_ProtocolNormalization(t *testing.T) {
	cfg := &config.Config{}
	cfg.Proxy.Enabled = true
	cfg.Proxy.Host = "127.0.0.1"
	cfg.Proxy.Port = 1080
	cfg.Proxy.Protocol = "socks" // Should be normalized to socks5 in config validation

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out)
	pd := NewProxyDialer(cfg, logger)

	// Note: Protocol normalization happens in config.Validate()
	// This test just verifies the dialer can handle "socks" protocol
	dialer, err := pd.CreateSOCKS5Dialer()
	if err != nil {
		t.Fatalf("Failed to create SOCKS dialer: %v", err)
	}
	if dialer == nil {
		t.Fatal("Dialer is nil")
	}
}

func TestProxyDialer_HTTPProxyDisabled(t *testing.T) {
	cfg := &config.Config{}
	cfg.Proxy.Enabled = false

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out)
	pd := NewProxyDialer(cfg, logger)

	proxyFunc, err := pd.CreateHTTPProxy()
	if err != nil {
		t.Fatalf("Should not error when proxy disabled: %v", err)
	}
	if proxyFunc != nil {
		t.Fatal("Proxy function should be nil when proxy disabled")
	}
}

func TestProxyDialer_MultipleCreations(t *testing.T) {
	// Test that multiple dialer creations work correctly
	cfg := &config.Config{}
	cfg.Proxy.Enabled = true
	cfg.Proxy.Host = "127.0.0.1"
	cfg.Proxy.Port = 1080
	cfg.Proxy.Protocol = "socks5"

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out)
	pd := NewProxyDialer(cfg, logger)

	// Create multiple dialers
	for i := 0; i < 3; i++ {
		dialer, err := pd.CreateSOCKS5Dialer()
		if err != nil {
			t.Fatalf("Failed to create SOCKS5 dialer on iteration %d: %v", i, err)
		}
		if dialer == nil {
			t.Fatalf("Dialer is nil on iteration %d", i)
		}
	}
}

func TestProxyDialer_InvalidPort(t *testing.T) {
	// This test verifies behavior with invalid port
	// Note: Validation should catch this, but testing defensive coding
	cfg := &config.Config{}
	cfg.Proxy.Enabled = true
	cfg.Proxy.Host = "proxy.example.com"
	cfg.Proxy.Port = 0 // Invalid port
	cfg.Proxy.Protocol = "socks5"

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out)
	pd := NewProxyDialer(cfg, logger)

	// Should still create dialer, but connection would fail at runtime
	dialer, err := pd.CreateSOCKS5Dialer()
	if err != nil {
		t.Fatalf("Dialer creation should not fail even with port 0: %v", err)
	}
	if dialer == nil {
		t.Fatal("Dialer is nil")
	}
}

func TestProxyDialer_EmptyCredentials(t *testing.T) {
	// Test with empty username but password set (edge case)
	cfg := &config.Config{}
	cfg.Proxy.Enabled = true
	cfg.Proxy.Host = "proxy.example.com"
	cfg.Proxy.Port = 1080
	cfg.Proxy.Protocol = "socks5"
	cfg.Proxy.Username = "" // Empty username
	cfg.Proxy.Password = "somepassword"

	logger := logrus.New()
	logger.SetOutput(logrus.StandardLogger().Out)
	pd := NewProxyDialer(cfg, logger)

	// Should create dialer without auth (username is empty)
	dialer, err := pd.CreateSOCKS5Dialer()
	if err != nil {
		t.Fatalf("Failed to create SOCKS5 dialer: %v", err)
	}
	if dialer == nil {
		t.Fatal("Dialer is nil")
	}
}
