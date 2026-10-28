package config

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestLoad_DefaultConfiguration(t *testing.T) {
	// Temporarily change to a directory without config file
	tempDir := t.TempDir()
	oldDir, err := os.Getwd()
	require.NoError(t, err)

	err = os.Chdir(tempDir)
	require.NoError(t, err)
	defer func() {
		os.Chdir(oldDir)
	}()

	config, err := Load()
	require.NoError(t, err)
	require.NotNil(t, config)

	// Test default values
	assert.Equal(t, "0.0.0.0", config.Server.Host)
	assert.Equal(t, 8899, config.Server.Port)
	assert.Equal(t, false, config.Server.Debug)
	assert.Equal(t, "your-secret-key", config.Server.Secret)
	assert.Equal(t, true, config.Server.AutoRead)
	// Storage paths are hardcoded in the application
}

func TestLoad_FromYAMLFile(t *testing.T) {
	tempDir := t.TempDir()
	configPath := filepath.Join(tempDir, "config.yaml")

	configContent := `
server:
  host: "127.0.0.1"
  port: 9000
  debug: true
  secret: "test-secret"
  autoread: false
`

	err := os.WriteFile(configPath, []byte(configContent), 0644)
	require.NoError(t, err)

	// Change to temp directory
	oldDir, err := os.Getwd()
	require.NoError(t, err)
	err = os.Chdir(tempDir)
	require.NoError(t, err)
	defer func() {
		os.Chdir(oldDir)
	}()

	config, err := Load()
	require.NoError(t, err)
	require.NotNil(t, config)

	// Test loaded values
	assert.Equal(t, "127.0.0.1", config.Server.Host)
	assert.Equal(t, 9000, config.Server.Port)
	assert.Equal(t, true, config.Server.Debug)
	assert.Equal(t, "test-secret", config.Server.Secret)
	assert.Equal(t, false, config.Server.AutoRead)
	// Storage paths are hardcoded in the application
}

func TestLoad_EnvironmentVariables(t *testing.T) {
	// Set environment variables with correct Viper format
	os.Setenv("WHATSAPP_SERVER.HOST", "192.168.1.1")
	os.Setenv("WHATSAPP_SERVER.PORT", "7777")
	os.Setenv("WHATSAPP_SERVER.DEBUG", "true")
	os.Setenv("WHATSAPP_SERVER.SECRET", "env-secret")
	os.Setenv("WHATSAPP_SERVER.AUTOREAD", "false")
	defer func() {
		os.Unsetenv("WHATSAPP_SERVER.HOST")
		os.Unsetenv("WHATSAPP_SERVER.PORT")
		os.Unsetenv("WHATSAPP_SERVER.DEBUG")
		os.Unsetenv("WHATSAPP_SERVER.SECRET")
		os.Unsetenv("WHATSAPP_SERVER.AUTOREAD")
	}()

	tempDir := t.TempDir()
	oldDir, err := os.Getwd()
	require.NoError(t, err)
	err = os.Chdir(tempDir)
	require.NoError(t, err)
	defer func() {
		os.Chdir(oldDir)
	}()

	config, err := Load()
	require.NoError(t, err)
	require.NotNil(t, config)

	// Test environment variable overrides
	assert.Equal(t, "192.168.1.1", config.Server.Host)
	assert.Equal(t, 7777, config.Server.Port)
	assert.Equal(t, true, config.Server.Debug)
	assert.Equal(t, "env-secret", config.Server.Secret)
	assert.Equal(t, false, config.Server.AutoRead)
}

func TestLoad_InvalidYAMLFile(t *testing.T) {
	tempDir := t.TempDir()
	configPath := filepath.Join(tempDir, "config.yaml")

	// Write invalid YAML content
	invalidContent := `
server:
  host: "127.0.0.1
  port: invalid_port
whatsapp
  auto_read: true
`

	err := os.WriteFile(configPath, []byte(invalidContent), 0644)
	require.NoError(t, err)

	oldDir, err := os.Getwd()
	require.NoError(t, err)
	err = os.Chdir(tempDir)
	require.NoError(t, err)
	defer func() {
		os.Chdir(oldDir)
	}()

	_, err = Load()
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "yaml")
}

func TestLoad_ConfigInSubdirectory(t *testing.T) {
	tempDir := t.TempDir()
	configDir := filepath.Join(tempDir, "config")
	err := os.MkdirAll(configDir, 0755)
	require.NoError(t, err)

	configPath := filepath.Join(configDir, "config.yaml")
	configContent := `
server:
  host: "subdirectory.test"
  port: 5555
`

	err = os.WriteFile(configPath, []byte(configContent), 0644)
	require.NoError(t, err)

	oldDir, err := os.Getwd()
	require.NoError(t, err)
	err = os.Chdir(tempDir)
	require.NoError(t, err)
	defer func() {
		os.Chdir(oldDir)
	}()

	config, err := Load()
	require.NoError(t, err)
	require.NotNil(t, config)

	assert.Equal(t, "subdirectory.test", config.Server.Host)
	assert.Equal(t, 5555, config.Server.Port)
}

func TestLoad_UnknownFieldsIgnored(t *testing.T) {
	// Viper silently ignores unknown config fields - verify this doesn't cause errors
	tempDir := t.TempDir()
	configPath := filepath.Join(tempDir, "config.yaml")

	configContent := `
server:
  host: "10.0.0.1"
  port: 7000
storage:
  db_strategy: "per_session"
  base_path: "/var/lib/whatsapp"
`

	err := os.WriteFile(configPath, []byte(configContent), 0644)
	require.NoError(t, err)

	oldDir, err := os.Getwd()
	require.NoError(t, err)
	err = os.Chdir(tempDir)
	require.NoError(t, err)
	defer func() {
		os.Chdir(oldDir)
	}()

	config, err := Load()
	require.NoError(t, err)
	require.NotNil(t, config)

	// Known fields should still load correctly
	assert.Equal(t, "10.0.0.1", config.Server.Host)
	assert.Equal(t, 7000, config.Server.Port)
}

func TestLoad_CompleteConfiguration(t *testing.T) {
	tempDir := t.TempDir()
	configPath := filepath.Join(tempDir, "config.yaml")

	configContent := `
server:
  host: "0.0.0.0"
  port: 8080
  debug: false
  secret: "production-secret"
  autoread: true

proxy:
  enabled: true
  host: "proxy.example.com"
  port: 1080
  protocol: "socks5"
  username: "proxyuser"
  password: "proxypass"

ngrok:
  enabled: true
  authtoken: "test-token"
  sites:
    - "https://site1.com"
    - "https://site2.com"
`

	err := os.WriteFile(configPath, []byte(configContent), 0644)
	require.NoError(t, err)

	oldDir, err := os.Getwd()
	require.NoError(t, err)
	err = os.Chdir(tempDir)
	require.NoError(t, err)
	defer func() {
		os.Chdir(oldDir)
	}()

	config, err := Load()
	require.NoError(t, err)
	require.NotNil(t, config)

	// Verify server section
	assert.Equal(t, "0.0.0.0", config.Server.Host)
	assert.Equal(t, 8080, config.Server.Port)
	assert.Equal(t, false, config.Server.Debug)
	assert.Equal(t, "production-secret", config.Server.Secret)
	assert.Equal(t, true, config.Server.AutoRead)

	// Verify proxy section
	assert.Equal(t, true, config.Proxy.Enabled)
	assert.Equal(t, "proxy.example.com", config.Proxy.Host)
	assert.Equal(t, 1080, config.Proxy.Port)
	assert.Equal(t, "socks5", config.Proxy.Protocol)
	assert.Equal(t, "proxyuser", config.Proxy.Username)
	assert.Equal(t, "proxypass", config.Proxy.Password)

	// Verify ngrok section
	assert.Equal(t, true, config.Ngrok.Enabled)
	assert.Equal(t, "test-token", config.Ngrok.Authtoken)
	assert.Equal(t, []string{"https://site1.com", "https://site2.com"}, config.Ngrok.Sites)
}

// TestValidateRejectsPlaceholderSecrets covers the guard that stops a server
// coming up on a secret that has been published in a shipped config file or in
// the documentation. That secret is the whole of the authentication between a
// Zender site and this server, so a known value is equivalent to none at all.
func TestValidateRejectsPlaceholderSecrets(t *testing.T) {
	newConfig := func(secret string) *Config {
		cfg := &Config{}
		cfg.Server.Secret = secret
		cfg.Server.Port = 8899
		return cfg
	}

	rejected := []string{
		"123456",            // the value wa-go's own config.json used to ship with
		"your-secret-key",   // from the README
		"production-secret", // from the environment-variable example
		"  123456  ",        // surrounding space must not smuggle it through
		"YOUR-SECRET-KEY",   // nor must case
		"ChangeMe",

		// Every placeholder the documentation currently prints. A security
		// review found these were accepted: the commit that introduced the
		// deny-list also reworded the docs to strings the list did not cover,
		// so a customer copying a config block verbatim got a secret the server
		// started on happily.
		"CHANGE-ME-TO-A-LONG-RANDOM-STRING",
		"CHANGE-ME",
		"YOUR_SECRET",
		"your-api-secret",

		// Separator and casing variants must all collapse to one entry.
		"change_me",
		"change me",
		"CHANGEME",
		"Your_Secret_Key",
	}

	for _, secret := range rejected {
		if err := newConfig(secret).Validate(); err == nil {
			t.Errorf("Validate() accepted placeholder secret %q, expected rejection", secret)
		}
	}

	accepted := []string{
		"k7Qm2xR9vB4nT1sW8pL3",
		"a-genuinely-chosen-passphrase",
		"1234567", // close to a placeholder but not one of them
	}

	for _, secret := range accepted {
		if err := newConfig(secret).Validate(); err != nil {
			t.Errorf("Validate() rejected legitimate secret %q: %v", secret, err)
		}
	}

	if err := newConfig("").Validate(); err == nil {
		t.Error("Validate() accepted an empty secret, expected rejection")
	}
}
