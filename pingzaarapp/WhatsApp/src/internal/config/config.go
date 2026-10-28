package config

import (
	"flag"
	"fmt"
	"io"
	"os"
	"strings"

	"github.com/spf13/viper"
)

// Config holds all configuration for the application
type Config struct {
	Server struct {
		Host     string `mapstructure:"host"`
		Port     int    `mapstructure:"port"`
		Debug    bool   `mapstructure:"debug"`
		Secret   string `mapstructure:"secret"`
		AutoRead bool   `mapstructure:"autoread"`
	} `mapstructure:"server"`
	Proxy struct {
		Enabled  bool   `mapstructure:"enabled"`
		Host     string `mapstructure:"host"`
		Port     int    `mapstructure:"port"`
		Protocol string `mapstructure:"protocol"`
		Username string `mapstructure:"username"`
		Password string `mapstructure:"password"`
	} `mapstructure:"proxy"`
	Ngrok struct {
		Enabled   bool     `mapstructure:"enabled"`
		Authtoken string   `mapstructure:"authtoken"`
		Sites     []string `mapstructure:"sites"`
	} `mapstructure:"ngrok"`
}

// CLIFlags holds command-line flag values
type CLIFlags struct {
	SecretKey     *string
	Host          *string
	Port          *int
	Debug         *bool
	AutoRead      *bool
	ShowHelp      *bool
	ProxyHost     *string
	ProxyPort     *int
	ProxyProtocol *string
	ProxyUsername *string
	ProxyPassword *string
	NgrokToken    *string
	NgrokServer   *string
}

// Load loads configuration from environment variables and config file
func Load() (*Config, error) {
	v := viper.New()

	// Set config file - supports both YAML and JSON
	v.SetConfigName("config")
	v.AddConfigPath(".")
	v.AddConfigPath("./config")

	// Set defaults
	v.SetDefault("server.host", "0.0.0.0")
	v.SetDefault("server.port", 8899)
	v.SetDefault("server.debug", false)
	v.SetDefault("server.secret", "your-secret-key")
	v.SetDefault("server.autoread", true)
	v.SetDefault("proxy.enabled", false)
	v.SetDefault("proxy.host", "")
	v.SetDefault("proxy.port", 0)
	v.SetDefault("proxy.protocol", "socks5")
	v.SetDefault("proxy.username", "")
	v.SetDefault("proxy.password", "")
	v.SetDefault("ngrok.enabled", false)
	v.SetDefault("ngrok.authtoken", "")
	v.SetDefault("ngrok.sites", []string{})

	// Environment variables
	v.AutomaticEnv()
	v.SetEnvPrefix("WHATSAPP")

	// Read config file (optional)
	if err := v.ReadInConfig(); err != nil {
		if _, ok := err.(viper.ConfigFileNotFoundError); !ok {
			return nil, err
		}
	}

	var config Config
	if err := v.Unmarshal(&config); err != nil {
		return nil, err
	}

	return &config, nil
}

// LoadWithCLI loads configuration and merges with CLI flags (CLI takes priority)
func LoadWithCLI(version string) (*Config, error) {
	// Parse CLI flags
	flags := parseCLIFlags(version)

	// Show help if explicitly requested
	if *flags.ShowHelp {
		showHelp(version)
		os.Exit(0)
	}

	// Load base configuration
	cfg, err := Load()
	if err != nil {
		return nil, err
	}

	// Merge CLI flags with priority (CLI overrides config.json)
	mergeFlags(cfg, flags)

	// Validate final configuration
	if err := cfg.Validate(); err != nil {
		return nil, err
	}

	return cfg, nil
}

// fs is the custom FlagSet used for CLI parsing (ignores unknown flags)
var fs *flag.FlagSet

// filterKnownArgs filters args to only include flags defined in the FlagSet.
// Unknown flags are silently ignored so the server can proceed.
func filterKnownArgs(fset *flag.FlagSet, args []string) []string {
	var known []string
	for i := 0; i < len(args); i++ {
		arg := args[i]
		if !strings.HasPrefix(arg, "-") {
			known = append(known, arg)
			continue
		}

		name := strings.TrimLeft(arg, "-")
		hasValue := false
		if idx := strings.Index(name, "="); idx >= 0 {
			name = name[:idx]
			hasValue = true
		}

		if fset.Lookup(name) != nil {
			known = append(known, arg)
		} else {
			// Unknown flag — skip its value too if provided as separate arg
			if !hasValue && i+1 < len(args) && !strings.HasPrefix(args[i+1], "-") {
				i++
			}
		}
	}
	return known
}

// parseCLIFlags parses command-line flags, ignoring any unknown flags
func parseCLIFlags(version string) *CLIFlags {
	fs = flag.NewFlagSet("whatsapp", flag.ContinueOnError)
	fs.SetOutput(io.Discard)

	flags := &CLIFlags{
		SecretKey:     fs.String("key", "", "Secret key for remote validation (overrides config.server.secret)"),
		Host:          fs.String("host", "", "Custom server hostname (overrides config.server.host)"),
		Port:          fs.Int("port", 0, "Custom server port (overrides config.server.port)"),
		Debug:         fs.Bool("debug", false, "Enable debug mode (overrides config.server.debug)"),
		AutoRead:      fs.Bool("autoread", false, "Enable auto-read mode (overrides config.server.autoread)"),
		ShowHelp:      fs.Bool("help", false, "Show help message"),
		ProxyHost:     fs.String("proxy-host", "", "Proxy server hostname/IP (required for proxy)"),
		ProxyPort:     fs.Int("proxy-port", 0, "Proxy server port (required for proxy)"),
		ProxyProtocol: fs.String("proxy-protocol", "socks5", "Proxy protocol: socks5, http, https"),
		ProxyUsername: fs.String("proxy-username", "", "Proxy authentication username (optional)"),
		ProxyPassword: fs.String("proxy-password", "", "Proxy authentication password (optional)"),
		NgrokToken:    fs.String("ngroktoken", "", "Ngrok authtoken (optional)"),
		NgrokServer:   fs.String("ngrokserver", "", "Server URLs, comma-separated (required if ngroktoken used)"),
	}

	args := filterKnownArgs(fs, os.Args[1:])
	fs.Parse(args)
	return flags
}

// mergeFlags merges CLI flags into config with CLI priority
func mergeFlags(cfg *Config, flags *CLIFlags) {
	// CLI flags override config.json values when provided
	if *flags.SecretKey != "" {
		cfg.Server.Secret = *flags.SecretKey
	}
	if *flags.Host != "" {
		cfg.Server.Host = *flags.Host
	}
	if *flags.Port > 0 {
		cfg.Server.Port = *flags.Port
	}
	// For boolean flags, we check if they were explicitly set
	if isFlagSet("debug") {
		cfg.Server.Debug = *flags.Debug
	}
	if isFlagSet("autoread") {
		cfg.Server.AutoRead = *flags.AutoRead
	}

	// Proxy flag merging
	if *flags.ProxyHost != "" {
		cfg.Proxy.Host = *flags.ProxyHost
	}
	if *flags.ProxyPort > 0 {
		cfg.Proxy.Port = *flags.ProxyPort
	}
	if isFlagSet("proxy-protocol") {
		cfg.Proxy.Protocol = *flags.ProxyProtocol
	}
	if *flags.ProxyUsername != "" {
		cfg.Proxy.Username = *flags.ProxyUsername
	}
	if *flags.ProxyPassword != "" {
		cfg.Proxy.Password = *flags.ProxyPassword
	}

	// Auto-enable proxy if host and port provided (matches Node.js behavior)
	cfg.Proxy.Enabled = cfg.Proxy.Host != "" && cfg.Proxy.Port > 0

	// Ngrok flag merging
	if *flags.NgrokToken != "" {
		cfg.Ngrok.Authtoken = *flags.NgrokToken
	}
	if *flags.NgrokServer != "" {
		cfg.Ngrok.Sites = strings.Split(*flags.NgrokServer, ",")
		// Trim whitespace from each site
		for i, site := range cfg.Ngrok.Sites {
			cfg.Ngrok.Sites[i] = strings.TrimSpace(site)
		}
	}

	// Auto-enable ngrok if both authtoken and sites provided (matches Node.js behavior)
	cfg.Ngrok.Enabled = cfg.Ngrok.Authtoken != "" && len(cfg.Ngrok.Sites) > 0
}

// isFlagSet checks if a flag was explicitly provided on command line
func isFlagSet(name string) bool {
	found := false
	fs.Visit(func(f *flag.Flag) {
		if f.Name == name {
			found = true
		}
	})
	return found
}

// placeholderSecrets are values that have appeared in shipped config files,
// documentation and examples. They are effectively public, so treating them as
// unset is safer than letting a server come up with one.
//
// These are written in canonical form: lower case with separators removed. That
// is deliberate. An earlier version of this list matched the exact strings, and
// the very commit that added it replaced "123456" in the documentation with
// "CHANGE-ME-TO-A-LONG-RANDOM-STRING" — a value the list did not contain, which
// reopened the same hole one string further along. Canonicalising means a single
// entry covers every punctuation and casing variant a document might use, so
// rewording an example cannot silently un-block it.
var placeholderSecrets = []string{
	"123456",
	"secret",
	"apisecret",
	"changeme",
	"changemetoalongrandomstring",
	"changethis",
	"replaceme",
	"yoursecret",
	"yoursecretkey",
	"yourapisecret",
	"yourkey",
	"mysecret",
	"productionsecret",
	"testsecret",
	"password",
}

// placeholderSeparators are stripped before comparison so that CHANGE-ME,
// change_me, "change me" and changeme all collapse to one canonical value.
var placeholderSeparators = strings.NewReplacer("-", "", "_", "", " ", "", ".", "")

// isPlaceholderSecret reports whether a secret is one of the known throwaway
// values, comparing case-insensitively and ignoring separators and surrounding
// space. A genuinely random secret canonicalises to itself and cannot collide
// with any entry above.
func isPlaceholderSecret(secret string) bool {
	normalised := placeholderSeparators.Replace(strings.ToLower(strings.TrimSpace(secret)))

	for _, placeholder := range placeholderSecrets {
		if normalised == placeholder {
			return true
		}
	}

	return false
}

// Validate validates the final configuration
func (c *Config) Validate() error {
	if c.Server.Secret == "" {
		return fmt.Errorf("secret key is required (provide via config.json 'server.secret' field or --key flag)")
	}
	if isPlaceholderSecret(c.Server.Secret) {
		return fmt.Errorf(
			"secret key %q is a placeholder and cannot be used: this secret is the only thing "+
				"authenticating your Zender site to this server, so anyone who guesses it can send "+
				"and read messages on every linked account. Set a long random value via config.json "+
				"'server.secret' or the --key flag",
			c.Server.Secret,
		)
	}
	if c.Server.Port < 1 || c.Server.Port > 65535 {
		return fmt.Errorf("port must be between 1 and 65535")
	}

	// Proxy validation
	if c.Proxy.Enabled {
		if c.Proxy.Host == "" {
			return fmt.Errorf("proxy host is required when proxy is enabled")
		}
		if c.Proxy.Port < 1 || c.Proxy.Port > 65535 {
			return fmt.Errorf("proxy port must be between 1 and 65535")
		}

		validProtocols := map[string]bool{
			"socks5": true,
			"socks":  true,
			"http":   true,
			"https":  true,
		}
		if !validProtocols[c.Proxy.Protocol] {
			return fmt.Errorf("proxy protocol must be one of: socks5, socks, http, https")
		}

		// Normalize "socks" to "socks5"
		if c.Proxy.Protocol == "socks" {
			c.Proxy.Protocol = "socks5"
		}
	}

	// Ngrok validation (matches Node.js behavior)
	if c.Ngrok.Authtoken != "" && len(c.Ngrok.Sites) == 0 {
		return fmt.Errorf("--ngrokserver required when using --ngroktoken")
	}
	if len(c.Ngrok.Sites) > 0 && c.Ngrok.Authtoken == "" {
		return fmt.Errorf("--ngroktoken required when using --ngrokserver")
	}

	return nil
}

// showHelp displays usage information
func showHelp(version string) {
	fmt.Printf("\n")
	fmt.Printf("💬 ZENDER WHATSAPP SERVER (v%s)\n\n", version)

	fmt.Println("USAGE:")
	fmt.Println("  ./titansys-whatsapp-linux [OPTIONS]")

	fmt.Println("CONFIGURATION:")
	fmt.Println("  • Primary: config.json file (optional)")
	fmt.Println("  • Override: CLI flags (takes priority when provided)")

	fmt.Println("CLI FLAGS:")
	fmt.Printf("  %-25s %s\n", "--key STRING", "Secret key (overrides config.server.secret)")
	fmt.Printf("  %-25s %s\n", "--host STRING", "Server hostname (overrides config.server.host)")
	fmt.Printf("  %-25s %s\n", "--port INT", "Server port (overrides config.server.port)")
	fmt.Printf("  %-25s %s\n", "--debug", "Enable debug mode (overrides config.server.debug)")
	fmt.Printf("  %-25s %s\n", "--autoread", "Enable auto-read (overrides config.server.autoread)")
	fmt.Printf("  %-25s %s\n", "--help", "Show this help message")

	fmt.Println("\nPROXY FLAGS:")
	fmt.Printf("  %-25s %s\n", "--proxy-host STRING", "Proxy hostname/IP (required for proxy)")
	fmt.Printf("  %-25s %s\n", "--proxy-port INT", "Proxy port (required for proxy)")
	fmt.Printf("  %-25s %s\n", "--proxy-protocol STRING", "Protocol: socks5, http, https (default: socks5)")
	fmt.Printf("  %-25s %s\n", "--proxy-username STRING", "Proxy auth username (optional)")
	fmt.Printf("  %-25s %s\n", "--proxy-password STRING", "Proxy auth password (optional)")

	fmt.Println("\nNGROK FLAGS:")
	fmt.Printf("  %-25s %s\n", "--ngroktoken STRING", "Ngrok authtoken (optional)")
	fmt.Printf("  %-25s %s\n", "--ngrokserver STRING", "Server URLs, comma-separated (required if ngroktoken used)")

	fmt.Println("\nEXAMPLES:")
	fmt.Println("  Config-only (simple):")
	fmt.Println("    ./titansys-whatsapp-linux")
	fmt.Println("    → Uses all values from config.json")

	fmt.Println("  Selective override:")
	fmt.Println("    ./titansys-whatsapp-linux --port=9000")
	fmt.Println("    → Uses config.json + overrides port")

	fmt.Println("  Full CLI (Node.js style):")
	fmt.Println("    ./titansys-whatsapp-linux --key=\"SECRET\" --host=\"0.0.0.0\" --port=8899")
	fmt.Println("    → Ignores config.json, uses only CLI values")

	fmt.Println("\nPROXY EXAMPLES:")
	fmt.Println("  Basic SOCKS5 (no auth):")
	fmt.Println("    ./titansys-whatsapp-linux --key=\"SECRET\" \\")
	fmt.Println("      --proxy-host=\"192.168.1.100\" --proxy-port=1080")
	fmt.Println()
	fmt.Println("  SOCKS5 with authentication:")
	fmt.Println("    ./titansys-whatsapp-linux --key=\"SECRET\" \\")
	fmt.Println("      --proxy-host=\"proxy.example.com\" --proxy-port=1080 \\")
	fmt.Println("      --proxy-username=\"user\" --proxy-password=\"pass\"")
	fmt.Println()
	fmt.Println("  HTTP Proxy:")
	fmt.Println("    ./titansys-whatsapp-linux --key=\"SECRET\" \\")
	fmt.Println("      --proxy-host=\"10.0.0.5\" --proxy-port=8080 --proxy-protocol=\"http\"")
	fmt.Println()
	fmt.Println("  Basic Ngrok tunnel:")
	fmt.Println("    ./titansys-whatsapp-linux --key=\"SECRET\" \\")
	fmt.Println("      --ngroktoken=\"2abc123...\" --ngrokserver=\"https://site1.com,https://site2.com\"")

	fmt.Println("\nCONFIG.JSON EXAMPLE:")
	fmt.Printf("  {\n")
	fmt.Printf("    \"server\": {\n")
	fmt.Printf("      \"host\": \"0.0.0.0\",\n")
	fmt.Printf("      \"port\": 8899,\n")
	fmt.Printf("      \"debug\": false,\n")
	fmt.Printf("      \"secret\": \"your-secret-key\",\n")
	fmt.Printf("      \"autoread\": true\n")
	fmt.Printf("    },\n")
	fmt.Printf("    \"proxy\": {\n")
	fmt.Printf("      \"enabled\": false,\n")
	fmt.Printf("      \"host\": \"\",\n")
	fmt.Printf("      \"port\": 0,\n")
	fmt.Printf("      \"protocol\": \"socks5\",\n")
	fmt.Printf("      \"username\": \"\",\n")
	fmt.Printf("      \"password\": \"\"\n")
	fmt.Printf("    },\n")
	fmt.Printf("    \"ngrok\": {\n")
	fmt.Printf("      \"enabled\": false,\n")
	fmt.Printf("      \"authtoken\": \"\",\n")
	fmt.Printf("      \"sites\": []\n")
	fmt.Printf("    }\n")
	fmt.Printf("  }\n\n")
}
