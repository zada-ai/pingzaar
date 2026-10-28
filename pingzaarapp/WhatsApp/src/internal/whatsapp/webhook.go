package whatsapp

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/sirupsen/logrus"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/binary/proto"
	"go.mau.fi/whatsmeow/types/events"

	"whatsapp-server/pkg/types"
)

// ProductionMessageDeduplicator implements MessageDeduplicatorInterface for production use.
// It tracks processed messages and automatically cleans up old entries.
type ProductionMessageDeduplicator struct {
	messages       sync.Map
	cleanupTicker  *time.Ticker
	cleanupDone    chan struct{}
	expirationTime time.Duration
	started        bool
	mu             sync.Mutex
}

// NewProductionMessageDeduplicator creates a new production message deduplicator.
func NewProductionMessageDeduplicator() *ProductionMessageDeduplicator {
	d := &ProductionMessageDeduplicator{
		cleanupDone:    make(chan struct{}),
		expirationTime: 5 * time.Minute,
	}
	d.startCleanup()
	return d
}

// startCleanup starts the background cleanup goroutine.
func (d *ProductionMessageDeduplicator) startCleanup() {
	d.mu.Lock()
	defer d.mu.Unlock()

	if d.started {
		return
	}
	d.started = true

	d.cleanupTicker = time.NewTicker(1 * time.Minute)
	go func() {
		for {
			select {
			case <-d.cleanupDone:
				return
			case <-d.cleanupTicker.C:
				now := time.Now()
				d.messages.Range(func(key, value interface{}) bool {
					if ts, ok := value.(time.Time); ok {
						if now.Sub(ts) > d.expirationTime {
							d.messages.Delete(key)
						}
					}
					return true
				})
			}
		}
	}()
}

// IsProcessed checks if a message has already been processed.
func (d *ProductionMessageDeduplicator) IsProcessed(messageKey string) bool {
	_, exists := d.messages.Load(messageKey)
	return exists
}

// MarkProcessed marks a message as processed.
func (d *ProductionMessageDeduplicator) MarkProcessed(messageKey string) {
	d.messages.Store(messageKey, time.Now())
}

// MarkProcessedIfNew marks a message as processed only if it hasn't been processed yet.
// Returns true if the message was newly marked, false if it was already processed.
func (d *ProductionMessageDeduplicator) MarkProcessedIfNew(messageKey string) bool {
	_, loaded := d.messages.LoadOrStore(messageKey, time.Now())
	return !loaded // true if this was a new entry
}

// Reset clears all processed messages (used in tests).
func (d *ProductionMessageDeduplicator) Reset() {
	d.messages = sync.Map{}
}

// Stop stops the cleanup goroutine.
func (d *ProductionMessageDeduplicator) Stop() {
	d.mu.Lock()
	defer d.mu.Unlock()

	if d.cleanupTicker != nil {
		d.cleanupTicker.Stop()
	}
	if d.started {
		close(d.cleanupDone)
		d.started = false
	}
}

// phoneDigitsRegex validates that a DM sender's User is composed solely of
// digits. whatsmeow's binary decoder does not constrain a JID's User field to
// digits, and Zender's PHP server keys its database on this value - a
// malformed or corrupted User must never reach that lookup.
var phoneDigitsRegex = regexp.MustCompile(`^[0-9]+$`)

// WebhookService handles sending webhooks to Zender
type WebhookService struct {
	sessionMgr   SessionManagerInterface
	logger       *logrus.Logger
	httpClient   HTTPClient
	deduplicator MessageDeduplicatorInterface
}

// NewWebhookService creates a new webhook service with default dependencies.
// For testing, use NewWebhookServiceWithDeps to inject mock dependencies.
func NewWebhookService(sessionMgr *SessionManager, logger *logrus.Logger) *WebhookService {
	return NewWebhookServiceWithDeps(sessionMgr, logger, nil, nil)
}

// NewWebhookServiceWithDeps creates a new webhook service with injectable dependencies.
// If httpClient is nil, a default HTTP client is created.
// If deduplicator is nil, a ProductionMessageDeduplicator is created.
func NewWebhookServiceWithDeps(
	sessionMgr SessionManagerInterface,
	logger *logrus.Logger,
	httpClient HTTPClient,
	deduplicator MessageDeduplicatorInterface,
) *WebhookService {
	if httpClient == nil {
		httpClient = &http.Client{
			Timeout: 120 * time.Second, // Allow Zender time for AI processing
		}
	}
	if deduplicator == nil {
		deduplicator = NewProductionMessageDeduplicator()
	}

	return &WebhookService{
		sessionMgr:   sessionMgr,
		logger:       logger,
		httpClient:   httpClient,
		deduplicator: deduplicator,
	}
}

// SendMessageReceived sends incoming message to Zender's webhook endpoint
func (ws *WebhookService) SendMessageReceived(evt *events.Message, session *types.Session) error {
	// Create unique key for this message
	messageKey := fmt.Sprintf("%s:%s", session.ID, evt.Info.ID)

	// Extract phone number and chat JID with LID support (before duplicate check for logging)
	senderJID := extractPhoneNumber(context.Background(), evt.Info.MessageSource, sessionLIDResolver(session))
	chatJID := extractChatJID(context.Background(), evt.Info.MessageSource, sessionLIDResolver(session))

	if isUnresolvedLIDDM(evt.Info.IsGroup, senderJID) {
		ws.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": evt.Info.ID,
			"sender":     senderJID.String(),
		}).Warn("Skipping webhook - DM sender is an unresolvable LID")
		return nil
	}

	// Defense-in-depth: whatsmeow's binary decoder does not constrain a JID's
	// User field to digits, and Zender's PHP server keys its DB on this value.
	// Refuse to forward a DM whose sender User isn't purely numeric.
	if !evt.Info.IsGroup && !phoneDigitsRegex.MatchString(senderJID.User) {
		ws.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": evt.Info.ID,
			"sender":     senderJID.String(),
		}).Warn("Skipping webhook - DM sender is not a valid phone number")
		return nil
	}

	// Check if already processing/processed using injected deduplicator
	if ws.deduplicator.IsProcessed(messageKey) {
		ws.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": evt.Info.ID,
			"from":       senderJID.User,
		}).Debug("Skipping duplicate webhook - message already processed")
		return nil
	}
	// Mark as processed before sending webhook
	ws.deduplicator.MarkProcessed(messageKey)
	// Note: Cleanup is handled by the deduplicator's background goroutine

	// Extract phone number for webhook payload (matches Node.js behavior)
	// For group messages: use full group JID (120363403405318006@g.us)
	// For direct messages: use sender's phone number only
	var phone string
	if evt.Info.IsGroup {
		// Group message: phone = group JID with @g.us suffix
		phone = chatJID.User + "@g.us"
	} else {
		// Direct message: phone = sender's phone number (no suffix, no device ID)
		phone = senderJID.User
	}

	// Skip empty messages without attachments (matches Node.js behavior)
	// Only send webhook if message has text content, media, or location
	if !ws.hasValidContent(evt.Message) {
		ws.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"from":       phone,
			"message_id": evt.Info.ID,
		}).Debug("Skipping webhook - empty message without attachments")
		return nil
	}

	// Build webhook payload matching Zender's expected format
	payload := url.Values{}
	payload.Set("system_token", session.Cache.SystemToken)
	payload.Set("uid", session.Cache.UID)
	payload.Set("hash", session.Cache.Hash)
	payload.Set("unique", session.Cache.Unique)
	payload.Set("phone", phone)
	payload.Set("message", ws.extractMessageContent(evt.Message))
	payload.Set("timestamp", fmt.Sprintf("%d", evt.Info.Timestamp.Unix()))

	// Add group info (matches Node.js behavior)
	// Group messages: set group name (from groupMeta.subject)
	// Direct messages: set empty string
	if evt.Info.IsGroup {
		// Fetch group metadata to get group name (use LID-aware chat JID)
		groupInfo, err := session.Client.GetGroupInfo(context.Background(), chatJID)
		if err != nil {
			ws.logger.WithError(err).WithFields(logrus.Fields{
				"session_id": session.ID,
				"group_jid":  chatJID.String(),
			}).Warn("Failed to fetch group info, using empty group name")
			payload.Set("group", "") // Fallback to empty string
		} else {
			// Use group name (subject) like Node.js does
			payload.Set("group", groupInfo.GroupName.Name)
		}
	} else {
		// Direct message: group field is empty string (matches Node.js)
		payload.Set("group", "")
	}

	// Track file path for cleanup after webhook
	var savedFilePath string

	// Add file info if media message
	if ws.hasMediaContent(evt.Message) {
		// Store file and get filename
		filename := ws.saveMediaFile(evt, session)
		if filename != "" {
			payload.Set("file", filename)
			// Store full path for cleanup after webhook
			savedFilePath = filepath.Join("storage", "media", session.ID, filename)
		}
	}

	// Construct webhook URL
	webhookURL := fmt.Sprintf("%s/whatsapp/received",
		strings.TrimRight(session.Cache.SiteURL, "/"))

	// Debug log detailed payload information
	ws.logger.WithFields(logrus.Fields{
		"webhook_url":    webhookURL,
		"session_id":     session.ID,
		"from_phone":     phone,
		"message":        ws.extractMessageContent(evt.Message),
		"timestamp":      evt.Info.Timestamp.Unix(),
		"is_group":       evt.Info.IsGroup,
		"has_media":      ws.hasMediaContent(evt.Message),
		"system_token":   session.Cache.SystemToken,
		"site_unique":    session.Cache.SiteUnique,
		"site_url":       session.Cache.SiteURL,
		"uid":            session.Cache.UID,
		"hash":           session.Cache.Hash,
		"unique":         session.Cache.Unique,
		"payload_params": len(payload),
	}).Debug("Preparing webhook payload for server")

	// Send webhook with retry logic
	err := ws.sendWithRetry(webhookURL, payload, session.ID)

	// Clean up media file ONLY if webhook succeeded
	// This ensures PHP server has downloaded the file before we delete it
	// If webhook failed, keep the file for potential manual recovery or retry
	if savedFilePath != "" {
		if err == nil {
			// Webhook succeeded - safe to delete
			if removeErr := os.Remove(savedFilePath); removeErr != nil {
				ws.logger.WithError(removeErr).WithFields(logrus.Fields{
					"session_id": session.ID,
					"file_path":  savedFilePath,
				}).Warn("Failed to delete media file after successful webhook")
			} else {
				ws.logger.WithFields(logrus.Fields{
					"session_id": session.ID,
					"file_path":  savedFilePath,
				}).Debug("Media file cleaned up after successful webhook")
			}
		} else {
			// Webhook failed - keep the file
			ws.logger.WithFields(logrus.Fields{
				"session_id": session.ID,
				"file_path":  savedFilePath,
			}).Warn("Keeping media file after webhook failure for potential recovery")
		}
	}

	return err
}

// extractMessageContent extracts text content from different message types
func (ws *WebhookService) extractMessageContent(msg *proto.Message) string {
	switch {
	case msg.GetConversation() != "":
		return msg.GetConversation()
	case msg.GetExtendedTextMessage() != nil:
		return msg.GetExtendedTextMessage().GetText()
	case msg.GetImageMessage() != nil:
		return msg.GetImageMessage().GetCaption()
	case msg.GetVideoMessage() != nil:
		return msg.GetVideoMessage().GetCaption()
	case msg.GetDocumentMessage() != nil:
		return msg.GetDocumentMessage().GetCaption()
	case msg.GetLocationMessage() != nil:
		lat := msg.GetLocationMessage().GetDegreesLatitude()
		lng := msg.GetLocationMessage().GetDegreesLongitude()
		return fmt.Sprintf("Location: %f, %f", lat, lng)
	default:
		return ""
	}
}

// hasMediaContent checks if message contains media
func (ws *WebhookService) hasMediaContent(msg *proto.Message) bool {
	return msg.GetImageMessage() != nil ||
		msg.GetVideoMessage() != nil ||
		msg.GetAudioMessage() != nil ||
		msg.GetDocumentMessage() != nil
}

// hasValidContent checks if message has meaningful content worth sending to webhook
// Returns true if message has text content, media attachment, or location data
// Matches Node.js behavior to avoid sending empty protocol/system messages
func (ws *WebhookService) hasValidContent(msg *proto.Message) bool {
	// Check if message has text content (conversation, extended text, captions, location)
	textContent := ws.extractMessageContent(msg)
	if textContent != "" {
		return true
	}

	// Check if message has media attachment (image, video, audio, document)
	if ws.hasMediaContent(msg) {
		return true
	}

	// Empty message without any content or media
	return false
}

// saveMediaFile streams media from WhatsApp directly to disk, returning the
// filename saved. Uses client.DownloadToFile so the full payload never has to
// fit in memory - important for videos and documents that can be tens or
// hundreds of MB.
func (ws *WebhookService) saveMediaFile(evt *events.Message, session *types.Session) string {
	if !ws.hasMediaContent(evt.Message) {
		return ""
	}

	// Extract downloadable message based on type
	var downloadableMsg whatsmeow.DownloadableMessage
	switch {
	case evt.Message.GetImageMessage() != nil:
		downloadableMsg = evt.Message.GetImageMessage()
	case evt.Message.GetVideoMessage() != nil:
		downloadableMsg = evt.Message.GetVideoMessage()
	case evt.Message.GetAudioMessage() != nil:
		downloadableMsg = evt.Message.GetAudioMessage()
	case evt.Message.GetDocumentMessage() != nil:
		downloadableMsg = evt.Message.GetDocumentMessage()
	default:
		ws.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": evt.Info.ID,
		}).Error("Unsupported media type for download")
		return ""
	}

	// Determine file extension based on message type
	fileExt := ws.getFileExtension(evt.Message)
	if fileExt == "" {
		fileExt = "bin"
	}

	// Generate unique filename
	filename := fmt.Sprintf("%s_%d_%s.%s",
		session.Cache.Unique,
		time.Now().Unix(),
		evt.Info.ID,
		fileExt)

	// Ensure per-session media storage directory exists
	mediaPath := filepath.Join("storage", "media", session.ID)
	if err := os.MkdirAll(mediaPath, 0755); err != nil {
		ws.logger.WithError(err).WithFields(logrus.Fields{
			"session_id": session.ID,
			"media_path": mediaPath,
		}).Error("Failed to create media storage directory")
		return ""
	}

	fullPath := filepath.Join(mediaPath, filename)

	// Open destination file before starting the download so DownloadToFile
	// can stream directly. *os.File satisfies the whatsmeow.File interface.
	file, err := os.OpenFile(fullPath, os.O_RDWR|os.O_CREATE|os.O_TRUNC, 0644)
	if err != nil {
		ws.logger.WithError(err).WithFields(logrus.Fields{
			"session_id": session.ID,
			"file_path":  fullPath,
		}).Error("Failed to open media file for writing")
		return ""
	}

	// 5-minute ceiling protects against stuck downloads without artificially
	// limiting legitimately large transfers.
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	defer cancel()

	if err := session.Client.DownloadToFile(ctx, downloadableMsg, file); err != nil {
		// Close before Remove so this also works on platforms (notably Windows)
		// that refuse to remove an open file.
		_ = file.Close()
		ws.logger.WithError(err).WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": evt.Info.ID,
			"file_path":  fullPath,
		}).Error("Failed to download media from WhatsApp")
		if rmErr := os.Remove(fullPath); rmErr != nil && !os.IsNotExist(rmErr) {
			ws.logger.WithError(rmErr).WithField("file_path", fullPath).
				Warn("Failed to remove partial media file after download error")
		}
		return ""
	}

	var fileSize int64
	if fi, statErr := file.Stat(); statErr == nil {
		fileSize = fi.Size()
	}
	_ = file.Close()

	ws.logger.WithFields(logrus.Fields{
		"session_id": session.ID,
		"filename":   filename,
		"file_path":  fullPath,
		"file_size":  fileSize,
		"file_ext":   fileExt,
	}).Info("Media file saved successfully")

	return filename
}

// getFileExtension determines file extension based on message type
func (ws *WebhookService) getFileExtension(msg *proto.Message) string {
	switch {
	case msg.GetImageMessage() != nil:
		mimeType := msg.GetImageMessage().GetMimetype()
		return ws.getExtensionFromMimeType(mimeType, "jpg")
	case msg.GetVideoMessage() != nil:
		mimeType := msg.GetVideoMessage().GetMimetype()
		return ws.getExtensionFromMimeType(mimeType, "mp4")
	case msg.GetAudioMessage() != nil:
		mimeType := msg.GetAudioMessage().GetMimetype()
		return ws.getExtensionFromMimeType(mimeType, "m4a")
	case msg.GetDocumentMessage() != nil:
		// Try to get extension from filename first
		filename := msg.GetDocumentMessage().GetFileName()
		if filename != "" && strings.Contains(filename, ".") {
			parts := strings.Split(filename, ".")
			return parts[len(parts)-1]
		}
		// Fall back to mime type
		mimeType := msg.GetDocumentMessage().GetMimetype()
		return ws.getExtensionFromMimeType(mimeType, "bin")
	default:
		return "bin"
	}
}

// getExtensionFromMimeType maps MIME types to file extensions
func (ws *WebhookService) getExtensionFromMimeType(mimeType, defaultExt string) string {
	mimeToExt := map[string]string{
		// Images
		"image/jpeg": "jpg",
		"image/jpg":  "jpg",
		"image/png":  "png",
		"image/gif":  "gif",
		"image/webp": "webp",
		"image/bmp":  "bmp",
		"image/tiff": "tiff",

		// Videos
		"video/mp4":  "mp4",
		"video/avi":  "avi",
		"video/mov":  "mov",
		"video/wmv":  "wmv",
		"video/flv":  "flv",
		"video/webm": "webm",
		"video/mkv":  "mkv",
		"video/3gp":  "3gp",

		// Audio
		"audio/mpeg": "mp3",
		"audio/mp3":  "mp3",
		"audio/mp4":  "m4a",
		"audio/aac":  "aac",
		"audio/wav":  "wav",
		"audio/ogg":  "ogg",
		"audio/flac": "flac",
		"audio/wma":  "wma",

		// Documents
		"application/pdf":    "pdf",
		"application/msword": "doc",
		"application/vnd.openxmlformats-officedocument.wordprocessingml.document": "docx",
		"application/vnd.ms-excel": "xls",
		"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet":         "xlsx",
		"application/vnd.ms-powerpoint":                                             "ppt",
		"application/vnd.openxmlformats-officedocument.presentationml.presentation": "pptx",
		"text/plain":                   "txt",
		"text/csv":                     "csv",
		"application/zip":              "zip",
		"application/x-rar-compressed": "rar",
		"application/x-7z-compressed":  "7z",
		"application/json":             "json",
		"application/xml":              "xml",
	}

	if ext, exists := mimeToExt[mimeType]; exists {
		return ext
	}

	return defaultExt
}

// sendWithRetry sends webhook with exponential backoff retry logic
func (ws *WebhookService) sendWithRetry(webhookURL string, data url.Values, sessionID string) error {
	maxRetries := 1 // Only try once with 120s timeout, no retries

	for attempt := 1; attempt <= maxRetries; attempt++ {
		startTime := time.Now()

		// Debug log the outgoing request
		ws.logger.WithFields(logrus.Fields{
			"session_id": sessionID,
			"attempt":    attempt,
			"url":        webhookURL,
			"payload":    data.Encode(),
			"method":     "POST",
		}).Debug("Sending webhook request to server")

		req, err := http.NewRequest("POST", webhookURL, strings.NewReader(data.Encode()))
		if err != nil {
			ws.logger.WithFields(logrus.Fields{
				"session_id": sessionID,
				"attempt":    attempt,
				"error":      err.Error(),
				"url":        webhookURL,
			}).Error("Failed to create webhook request")
			return fmt.Errorf("failed to create request: %w", err)
		}

		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
		req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36")

		// Debug log request headers
		ws.logger.WithFields(logrus.Fields{
			"session_id":   sessionID,
			"attempt":      attempt,
			"content_type": req.Header.Get("Content-Type"),
			"user_agent":   req.Header.Get("User-Agent"),
		}).Debug("Webhook request headers")

		resp, err := ws.httpClient.Do(req)
		duration := time.Since(startTime)

		if err != nil {
			ws.logger.WithFields(logrus.Fields{
				"session_id": sessionID,
				"attempt":    attempt,
				"error":      err.Error(),
				"url":        webhookURL,
				"duration":   duration,
			}).Error("Webhook request failed with network error")
		} else {
			// Read response body for debugging
			var responseBody string
			if resp.Body != nil {
				bodyBytes, readErr := io.ReadAll(resp.Body)
				resp.Body.Close()
				if readErr != nil {
					responseBody = fmt.Sprintf("Error reading response body: %v", readErr)
				} else {
					responseBody = string(bodyBytes)
				}
			}

			// Debug log response details
			responseHeaders := make(map[string]string)
			for key, values := range resp.Header {
				if len(values) > 0 {
					responseHeaders[key] = values[0]
				}
			}

			logFields := logrus.Fields{
				"session_id":       sessionID,
				"attempt":          attempt,
				"url":              webhookURL,
				"status_code":      resp.StatusCode,
				"status":           resp.Status,
				"response_body":    responseBody,
				"response_headers": responseHeaders,
				"duration":         duration,
			}

			if resp.StatusCode == 200 {
				ws.logger.WithFields(logFields).Info("Webhook sent successfully to server!")
				return nil
			} else {
				ws.logger.WithFields(logFields).Warn("Webhook failed - server returned non-200 status")
			}
		}

		// Exponential backoff
		if attempt < maxRetries {
			delay := time.Duration(attempt*attempt) * time.Second
			ws.logger.WithFields(logrus.Fields{
				"session_id":   sessionID,
				"delay":        delay,
				"attempt":      attempt,
				"next_attempt": attempt + 1,
			}).Debug("Retrying webhook after delay")
			time.Sleep(delay)
		}
	}

	ws.logger.WithFields(logrus.Fields{
		"session_id":  sessionID,
		"max_retries": maxRetries,
		"url":         webhookURL,
	}).Error("Webhook permanently failed after all retry attempts")

	return fmt.Errorf("webhook failed after %d attempts", maxRetries)
}
