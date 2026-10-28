package whatsapp

import (
	"fmt"
	"net/http"
	"net/url"

	"github.com/sirupsen/logrus"
	"golang.org/x/net/proxy"
	"whatsapp-server/internal/config"
)

// ProxyDialer creates a proxy dialer based on configuration
type ProxyDialer struct {
	config *config.Config
	logger *logrus.Logger
}

// NewProxyDialer creates a new proxy dialer
func NewProxyDialer(cfg *config.Config, logger *logrus.Logger) *ProxyDialer {
	return &ProxyDialer{
		config: cfg,
		logger: logger,
	}
}

// CreateSOCKS5Dialer creates a SOCKS5 proxy dialer
func (pd *ProxyDialer) CreateSOCKS5Dialer() (proxy.Dialer, error) {
	if !pd.config.Proxy.Enabled {
		return proxy.Direct, nil
	}

	proxyAddr := fmt.Sprintf("%s:%d", pd.config.Proxy.Host, pd.config.Proxy.Port)

	var auth *proxy.Auth
	if pd.config.Proxy.Username != "" {
		auth = &proxy.Auth{
			User:     pd.config.Proxy.Username,
			Password: pd.config.Proxy.Password,
		}
		pd.logger.WithFields(logrus.Fields{
			"proxy_addr": proxyAddr,
			"has_auth":   true,
		}).Debug("Creating SOCKS5 proxy dialer with authentication")
	} else {
		pd.logger.WithFields(logrus.Fields{
			"proxy_addr": proxyAddr,
			"has_auth":   false,
		}).Debug("Creating SOCKS5 proxy dialer without authentication")
	}

	dialer, err := proxy.SOCKS5("tcp", proxyAddr, auth, proxy.Direct)
	if err != nil {
		return nil, fmt.Errorf("failed to create SOCKS5 proxy dialer: %w", err)
	}

	return dialer, nil
}

// CreateHTTPProxy creates an HTTP/HTTPS proxy function
func (pd *ProxyDialer) CreateHTTPProxy() (func(*http.Request) (*url.URL, error), error) {
	if !pd.config.Proxy.Enabled {
		return nil, nil // No proxy
	}

	// Build proxy URL
	proxyURL := fmt.Sprintf("%s://%s:%d",
		pd.config.Proxy.Protocol,
		pd.config.Proxy.Host,
		pd.config.Proxy.Port,
	)

	// Add authentication if provided
	if pd.config.Proxy.Username != "" {
		parsedURL, err := url.Parse(proxyURL)
		if err != nil {
			return nil, fmt.Errorf("failed to parse proxy URL: %w", err)
		}

		parsedURL.User = url.UserPassword(
			pd.config.Proxy.Username,
			pd.config.Proxy.Password,
		)
		proxyURL = parsedURL.String()

		pd.logger.WithFields(logrus.Fields{
			"proxy_host": pd.config.Proxy.Host,
			"proxy_port": pd.config.Proxy.Port,
			"protocol":   pd.config.Proxy.Protocol,
			"has_auth":   true,
		}).Debug("Creating HTTP proxy with authentication")
	} else {
		pd.logger.WithFields(logrus.Fields{
			"proxy_host": pd.config.Proxy.Host,
			"proxy_port": pd.config.Proxy.Port,
			"protocol":   pd.config.Proxy.Protocol,
			"has_auth":   false,
		}).Debug("Creating HTTP proxy without authentication")
	}

	parsedProxyURL, err := url.Parse(proxyURL)
	if err != nil {
		return nil, fmt.Errorf("failed to parse proxy URL: %w", err)
	}

	return http.ProxyURL(parsedProxyURL), nil
}

// IsEnabled returns whether proxy is enabled
func (pd *ProxyDialer) IsEnabled() bool {
	return pd.config.Proxy.Enabled
}

// GetProtocol returns the configured proxy protocol
func (pd *ProxyDialer) GetProtocol() string {
	return pd.config.Proxy.Protocol
}
