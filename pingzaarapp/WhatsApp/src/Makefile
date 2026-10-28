.PHONY: help build build-linux build-arm64 build-windows build-macos zip run dev test test-coverage lint fmt clean tidy update install-tools deploy-check

# Variables
BINARY_NAME=app-test
BINARY_NAME_PROD=titansys-whatsapp
BINARY_DIR=dist
MAIN_FILE=app.go

# The binary reports the version of the Zender release it ships inside, so a
# customer comparing the two never sees a mismatch. Read straight from Zender's
# version file rather than duplicated here — there is no second place to forget
# to bump. Override for a one-off build with: make build-linux VERSION=1.2.3
ZENDER_VERSION_FILE=../zender/system/configurations/cc_ver.inc
VERSION ?= $(shell cat $(ZENDER_VERSION_FILE) 2>/dev/null || echo 0.0.0-dev)
# -trimpath matters as much as -ldflags here. "-s -w" strips the symbol table and
# DWARF, but Go still embeds absolute SOURCE PATHS for panic and stack traces --
# so a shipped binary carried 869 occurrences of the build machine's home
# directory, including the developer's account name and this repo's own layout,
# recoverable by anyone who runs `strings` on a public download. -trimpath
# rewrites those to module-relative paths. No behaviour change.
BUILD_FLAGS=-trimpath -ldflags="-s -w -X main.Version=$(VERSION)"

# Colors for output
COLOR_RESET=\033[0m
COLOR_BLUE=\033[36m
COLOR_GREEN=\033[32m
COLOR_YELLOW=\033[33m

# Default target - show help
.DEFAULT_GOAL := help

help: ## Show this help message
	@echo "$(COLOR_BLUE)Available commands:$(COLOR_RESET)"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(COLOR_GREEN)%-20s$(COLOR_RESET) %s\n", $$1, $$2}'

# Development commands
build: ## Build development binary (pure Go, no CGO)
	@echo "$(COLOR_BLUE)🔨 Building $(BINARY_NAME)...$(COLOR_RESET)"
	@mkdir -p $(BINARY_DIR)
	@CGO_ENABLED=0 go build -o $(BINARY_DIR)/$(BINARY_NAME) $(MAIN_FILE)
	@echo "$(COLOR_GREEN)✅ Build complete: $(BINARY_DIR)/$(BINARY_NAME)$(COLOR_RESET)"


run: ## Run the application directly
	@go run $(MAIN_FILE)

dev: ## Run with live reload (requires air, falls back to go run)
	@if command -v air > /dev/null; then \
		echo "$(COLOR_BLUE)🚀 Starting with live reload...$(COLOR_RESET)"; \
		air; \
	else \
		echo "$(COLOR_YELLOW)⚠️  'air' not installed. Run 'make install-tools' to enable live reload$(COLOR_RESET)"; \
		echo "$(COLOR_BLUE)Running directly...$(COLOR_RESET)"; \
		go run $(MAIN_FILE); \
	fi

# Testing commands
test: ## Run all tests
	@echo "$(COLOR_BLUE)🧪 Running tests...$(COLOR_RESET)"
	@go test ./... -v

test-coverage: ## Run tests with HTML coverage report
	@echo "$(COLOR_BLUE)🧪 Running tests with coverage...$(COLOR_RESET)"
	@go test ./... -coverprofile=coverage.out
	@go tool cover -html=coverage.out -o coverage.html
	@echo "$(COLOR_GREEN)✅ Coverage report generated: coverage.html$(COLOR_RESET)"

# Code quality commands
lint: ## Run linter (uses golangci-lint or falls back to go vet)
	@if command -v golangci-lint > /dev/null; then \
		echo "$(COLOR_BLUE)🔍 Running golangci-lint...$(COLOR_RESET)"; \
		golangci-lint run; \
	else \
		echo "$(COLOR_YELLOW)⚠️  golangci-lint not installed, running go vet...$(COLOR_RESET)"; \
		go vet ./...; \
	fi

fmt: ## Format all Go code
	@echo "$(COLOR_BLUE)🎨 Formatting code...$(COLOR_RESET)"
	@go fmt ./...
	@echo "$(COLOR_GREEN)✅ Code formatted$(COLOR_RESET)"

# Maintenance commands
clean: ## Clean build artifacts and coverage files
	@echo "$(COLOR_BLUE)🧹 Cleaning...$(COLOR_RESET)"
	@rm -rf $(BINARY_DIR)
	@rm -f coverage.out coverage.html
	@echo "$(COLOR_GREEN)✅ Clean complete$(COLOR_RESET)"

tidy: ## Tidy and verify Go dependencies
	@echo "$(COLOR_BLUE)📦 Tidying dependencies...$(COLOR_RESET)"
	@go mod tidy
	@go mod verify
	@echo "$(COLOR_GREEN)✅ Dependencies verified$(COLOR_RESET)"

update: ## Update all Go packages to latest versions (including whatsmeow)
	@echo "$(COLOR_BLUE)📦 Updating all packages to latest versions...$(COLOR_RESET)"
	@echo "$(COLOR_BLUE)📦 Updating whatsmeow to latest commit...$(COLOR_RESET)"
	@go get go.mau.fi/whatsmeow@latest
	@LATEST_VERSION=$$(grep 'go.mau.fi/whatsmeow' go.mod | head -1 | awk '{print $$2}'); \
	if [ "$$(uname)" = "Darwin" ]; then \
		sed -i '' "s|github.com/tulir/whatsmeow v.*|github.com/tulir/whatsmeow $$LATEST_VERSION|" go.mod; \
	else \
		sed -i "s|github.com/tulir/whatsmeow v.*|github.com/tulir/whatsmeow $$LATEST_VERSION|" go.mod; \
	fi
	@go get -u ./...
	@go mod tidy
	@echo "$(COLOR_YELLOW)📌 Pinning log15 to v3.0.0-testing.5 (required by ngrok)...$(COLOR_RESET)"
	@go get github.com/inconshreveable/log15/v3@v3.0.0-testing.5
	@echo "$(COLOR_YELLOW)📌 Pinning quic-go to v0.48.2 (compatible with qpack v0.5.1)...$(COLOR_RESET)"
	@go get github.com/quic-go/quic-go@v0.48.2 github.com/quic-go/qpack@v0.5.1
	@go mod tidy
	@echo "$(COLOR_GREEN)✅ Packages updated (including latest whatsmeow)$(COLOR_RESET)"
	@echo "$(COLOR_YELLOW)⚠️  Run 'make test' to verify everything still works$(COLOR_RESET)"

install-tools: ## Install development tools (air for live reload)
	@echo "$(COLOR_BLUE)🔧 Installing development tools...$(COLOR_RESET)"
	@go install github.com/air-verse/air@latest
	@echo "$(COLOR_GREEN)✅ All tools installed$(COLOR_RESET)"
	@echo "$(COLOR_BLUE)ℹ️  Installed: air (live reload)$(COLOR_RESET)"

# Cross-platform builds
# NOTE: Pure Go SQLite driver (modernc.org/sqlite) enables cross-compilation from any platform
build-linux: ## Build for Linux AMD64 (cross-compilation enabled with pure Go driver)
	@echo "$(COLOR_BLUE)🔨 Building for Linux AMD64...$(COLOR_RESET)"
	@mkdir -p $(BINARY_DIR)
	@CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build $(BUILD_FLAGS) -o $(BINARY_DIR)/titansys-whatsapp-linux $(MAIN_FILE)
	@echo "$(COLOR_GREEN)✅ Linux build complete: $(BINARY_DIR)/titansys-whatsapp-linux$(COLOR_RESET)"

build-arm64: ## Build for Linux ARM64 (cross-compilation enabled with pure Go driver)
	@echo "$(COLOR_BLUE)🔨 Building for Linux ARM64...$(COLOR_RESET)"
	@mkdir -p $(BINARY_DIR)
	@CGO_ENABLED=0 GOOS=linux GOARCH=arm64 go build $(BUILD_FLAGS) -o $(BINARY_DIR)/titansys-whatsapp-arm64 $(MAIN_FILE)
	@echo "$(COLOR_GREEN)✅ ARM64 build complete: $(BINARY_DIR)/titansys-whatsapp-arm64$(COLOR_RESET)"

# build-macos IS shipped — its output goes into WhatsApp/prebuilt/ alongside the two
# Linux binaries, and this machine is Apple Silicon so the result can be run and tested.
# build-windows is NOT shipped: it builds fine (CGO_ENABLED=0 cross-compiles anywhere),
# but there is no Windows hardware here to test the result on, so users build that one
# themselves from WhatsApp/src/.
build-windows: ## Build for Windows AMD64 (cross-compilation enabled with pure Go driver)
	@echo "$(COLOR_BLUE)🔨 Building for Windows AMD64...$(COLOR_RESET)"
	@mkdir -p $(BINARY_DIR)
	@CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build $(BUILD_FLAGS) -o $(BINARY_DIR)/titansys-whatsapp-win.exe $(MAIN_FILE)
	@echo "$(COLOR_GREEN)✅ Windows build complete: $(BINARY_DIR)/titansys-whatsapp-win.exe$(COLOR_RESET)"

build-macos: ## Build for macOS ARM64 (cross-compilation enabled with pure Go driver)
	@echo "$(COLOR_BLUE)🔨 Building for macOS ARM64...$(COLOR_RESET)"
	@mkdir -p $(BINARY_DIR)
	@CGO_ENABLED=0 GOOS=darwin GOARCH=arm64 go build $(BUILD_FLAGS) -o $(BINARY_DIR)/titansys-whatsapp-macos $(MAIN_FILE)
	@echo "$(COLOR_GREEN)✅ macOS build complete: $(BINARY_DIR)/titansys-whatsapp-macos$(COLOR_RESET)"

# Package binaries into zip files
zip: ## Create zip files for Linux, ARM64, and macOS binaries (Windows excluded)
	@echo "$(COLOR_BLUE)📦 Creating zip packages...$(COLOR_RESET)"
	@if [ ! -d "$(BINARY_DIR)" ]; then \
		echo "$(COLOR_YELLOW)⚠️  $(BINARY_DIR) directory not found. Run builds first.$(COLOR_RESET)"; \
		exit 1; \
	fi
	@cd $(BINARY_DIR) && \
	if [ -f "titansys-whatsapp-linux" ]; then \
		echo "$(COLOR_BLUE)  Zipping Linux binary...$(COLOR_RESET)"; \
		zip -q linux.zip titansys-whatsapp-linux && \
		echo "$(COLOR_GREEN)  ✅ Created linux.zip$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)  ⚠️  titansys-whatsapp-linux not found, skipping$(COLOR_RESET)"; \
	fi
	@cd $(BINARY_DIR) && \
	if [ -f "titansys-whatsapp-arm64" ]; then \
		echo "$(COLOR_BLUE)  Zipping ARM64 binary...$(COLOR_RESET)"; \
		zip -q arm64.zip titansys-whatsapp-arm64 && \
		echo "$(COLOR_GREEN)  ✅ Created arm64.zip$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)  ⚠️  titansys-whatsapp-arm64 not found, skipping$(COLOR_RESET)"; \
	fi
	@cd $(BINARY_DIR) && \
	if [ -f "titansys-whatsapp-macos" ]; then \
		echo "$(COLOR_BLUE)  Zipping macOS binary...$(COLOR_RESET)"; \
		zip -q macos.zip titansys-whatsapp-macos && \
		echo "$(COLOR_GREEN)  ✅ Created macos.zip$(COLOR_RESET)"; \
	else \
		echo "$(COLOR_YELLOW)  ⚠️  titansys-whatsapp-macos not found, skipping$(COLOR_RESET)"; \
	fi
	@echo "$(COLOR_GREEN)✅ Zip packaging complete$(COLOR_RESET)"
	@echo "$(COLOR_BLUE)ℹ️  Zipped files in $(BINARY_DIR)/$(COLOR_RESET)"

# Production workflow
deploy-check: lint test build ## Run all checks before deployment
	@echo "$(COLOR_GREEN)✅ All deployment checks passed$(COLOR_RESET)"
