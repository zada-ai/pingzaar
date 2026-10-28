package whatsapp

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"html"
	"io"
	"mime"
	"net/http"
	"path/filepath"
	"strings"
	"time"

	"github.com/hajimehoshi/go-mp3"
	"github.com/sirupsen/logrus"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/binary/proto"
	"go.mau.fi/whatsmeow/types"
	protoUtils "google.golang.org/protobuf/proto"

	goTypes "whatsapp-server/pkg/types"
)

const (
	// MaxMediaSize defines the maximum allowed media file size (100MB)
	// Prevents memory exhaustion from downloading extremely large files
	MaxMediaSize = 100 * 1024 * 1024 // 100MB
)

// MessageSender handles sending WhatsApp messages via whatsmeow
type MessageSender struct {
	sessionMgr SessionManagerInterface
	logger     *logrus.Logger
	httpClient HTTPClient
}

// NewMessageSender creates a new message sender service with default HTTP client.
// For testing, use NewMessageSenderWithDeps to inject a mock HTTP client.
func NewMessageSender(sessionMgr *SessionManager, logger *logrus.Logger) *MessageSender {
	return NewMessageSenderWithDeps(sessionMgr, logger, nil)
}

// NewMessageSenderWithDeps creates a new message sender service with injectable dependencies.
// If httpClient is nil, a default HTTP client is created.
func NewMessageSenderWithDeps(sessionMgr SessionManagerInterface, logger *logrus.Logger, httpClient HTTPClient) *MessageSender {
	if httpClient == nil {
		// Create custom transport that disables environment proxy variables
		// This ensures external media URLs work regardless of HTTP_PROXY/HTTPS_PROXY settings
		customTransport := &http.Transport{
			Proxy: nil, // Explicitly disable proxy (don't use ProxyFromEnvironment)
			// Keep default settings for connection pooling, TLS, etc.
			MaxIdleConns:          100,
			IdleConnTimeout:       90 * time.Second,
			TLSHandshakeTimeout:   10 * time.Second,
			ExpectContinueTimeout: 1 * time.Second,
		}
		httpClient = &http.Client{
			Timeout:   60 * time.Second,
			Transport: customTransport,
		}
	}

	return &MessageSender{
		sessionMgr: sessionMgr,
		logger:     logger,
		httpClient: httpClient,
	}
}

// AudioType represents audio format information
type AudioType struct {
	Format   string // "ogg" or "mp3"
	MimeType string // "audio/ogg; codecs=opus" or "audio/mpeg"
	IsPTT    bool   // true for voice messages (OGG), false for audio files (MP3)
}

// SendWhatsAppMessage sends a message via WhatsApp and returns success/failure
// This function validates phone numbers with WhatsApp before sending to handle
// country-specific number normalization (e.g., Brazilian "9" digit issue).
func (ms *MessageSender) SendWhatsAppMessage(session *goTypes.Session, message goTypes.QueuedMessage) bool {
	startTime := time.Now()

	ms.logger.WithFields(logrus.Fields{
		"session_id": session.ID,
		"message_id": message.ID,
		"cid":        message.CID,
		"phone":      message.Phone,
		"priority":   message.Priority,
	}).Info("Attempting to send WhatsApp message")

	// Fast-fail if WhatsApp client is not connected
	if session.Client == nil || !session.Client.IsConnected() {
		ms.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": message.ID,
		}).Error("Cannot send message - WhatsApp client not connected")
		return false
	}

	// Group JIDs (@g.us) and LID-addressed DMs (@lid) bypass IsOnWhatsApp
	// validation - they are already-formed JIDs that ParseJID handles directly.
	if isGroupOrLIDRecipient(message.Phone) {
		recipientJID, err := types.ParseJID(message.Phone)
		if err != nil {
			ms.logger.WithError(err).WithFields(logrus.Fields{
				"session_id": session.ID,
				"message_id": message.ID,
				"phone":      message.Phone,
			}).Error("Failed to parse group/LID JID")
			return false
		}
		return ms.sendToJID(session, message, recipientJID, startTime)
	}

	// Clean phone number for validation
	cleanPhone := strings.TrimPrefix(message.Phone, "+")
	cleanPhone = strings.ReplaceAll(cleanPhone, "-", "")
	cleanPhone = strings.ReplaceAll(cleanPhone, " ", "")
	cleanPhone = strings.ReplaceAll(cleanPhone, "(", "")
	cleanPhone = strings.ReplaceAll(cleanPhone, ")", "")

	// Check if it's already a full JID (e.g., from WID field)
	if strings.Contains(message.Phone, "@s.whatsapp.net") {
		recipientJID, err := types.ParseJID(message.Phone)
		if err != nil {
			ms.logger.WithError(err).WithFields(logrus.Fields{
				"session_id": session.ID,
				"message_id": message.ID,
				"phone":      message.Phone,
			}).Error("Failed to parse JID")
			return false
		}
		return ms.sendToJID(session, message, recipientJID, startTime)
	}

	// Validate with WhatsApp and get the correct JID
	// This handles country-specific normalization (e.g., Brazilian "9" digit issue)
	// WhatsApp may return a different JID than the input phone for legacy registrations
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	resp, err := session.Client.IsOnWhatsApp(ctx, []string{cleanPhone})
	if err != nil {
		ms.logger.WithError(err).WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": message.ID,
			"phone":      message.Phone,
		}).Error("Failed to validate phone number with WhatsApp")
		return false
	}

	if len(resp) == 0 || !resp[0].IsIn {
		ms.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": message.ID,
			"phone":      message.Phone,
		}).Warn("Phone number not registered on WhatsApp")
		return false
	}

	// Use the JID that WhatsApp returned (handles country-specific normalization)
	recipientJID := resp[0].JID

	ms.logger.WithFields(logrus.Fields{
		"session_id":     session.ID,
		"message_id":     message.ID,
		"original_phone": message.Phone,
		"resolved_jid":   recipientJID.String(),
	}).Debug("Phone number validated, using WhatsApp-provided JID")

	return ms.sendToJID(session, message, recipientJID, startTime)
}

// sendToJID sends a message to a validated JID
func (ms *MessageSender) sendToJID(session *goTypes.Session, message goTypes.QueuedMessage, recipientJID types.JID, startTime time.Time) bool {
	// Parse message content from JSON (pass session for media upload)
	waMessage, err := ms.parseMessageContent(message.Message, session)
	if err != nil {
		ms.logger.WithError(err).WithFields(logrus.Fields{
			"session_id":      session.ID,
			"message_id":      message.ID,
			"message_content": message.Message,
		}).Error("Failed to parse message content")
		return false
	}

	// Send message via whatsmeow
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	_, err = session.Client.SendMessage(ctx, recipientJID, waMessage)
	duration := time.Since(startTime)

	if err != nil {
		ms.logger.WithError(err).WithFields(logrus.Fields{
			"session_id":    session.ID,
			"message_id":    message.ID,
			"recipient_jid": recipientJID.String(),
			"duration":      duration,
		}).Error("Failed to send WhatsApp message")
		return false
	}

	ms.logger.WithFields(logrus.Fields{
		"session_id":    session.ID,
		"message_id":    message.ID,
		"recipient_jid": recipientJID.String(),
		"duration":      duration,
	}).Info("WhatsApp message sent successfully")

	return true
}

// parseRecipientJID converts a phone number or already-formed JID into a
// WhatsApp JID. Inputs containing "@" (including @g.us, @lid, @s.whatsapp.net)
// are passed through ParseJID unchanged; bare digits get normalized and
// suffixed with @s.whatsapp.net.
func (ms *MessageSender) parseRecipientJID(phone string) (types.JID, error) {
	// Already-formed JID - includes @lid addressing mode.
	if strings.Contains(phone, "@") {
		return types.ParseJID(phone)
	}

	// Bare phone number: strip punctuation and default to @s.whatsapp.net.
	cleanPhone := strings.TrimPrefix(phone, "+")
	cleanPhone = strings.ReplaceAll(cleanPhone, "-", "")
	cleanPhone = strings.ReplaceAll(cleanPhone, " ", "")
	cleanPhone = strings.ReplaceAll(cleanPhone, "(", "")
	cleanPhone = strings.ReplaceAll(cleanPhone, ")", "")

	jidString := fmt.Sprintf("%s@s.whatsapp.net", cleanPhone)
	return types.ParseJID(jidString)
}

// isGroupOrLIDRecipient returns true when the phone string is an
// already-formed JID in a server type that bypasses phone-number validation.
// @g.us is groups; @lid is LID-addressed DMs (both are not phone numbers we
// could look up via IsOnWhatsApp).
func isGroupOrLIDRecipient(phone string) bool {
	return strings.Contains(phone, "@g.us") || strings.Contains(phone, "@lid")
}

// parseMessageContent parses JSON message content into WhatsApp message format
func (ms *MessageSender) parseMessageContent(messageContent string, session *goTypes.Session) (*proto.Message, error) {
	// Parse JSON message content
	var msgData map[string]interface{}
	if err := json.Unmarshal([]byte(messageContent), &msgData); err != nil {
		// If not JSON, treat as plain text
		return &proto.Message{
			Conversation: protoUtils.String(messageContent),
		}, nil
	}

	// Handle different message types based on JSON structure
	if text, exists := msgData["text"]; exists {
		return ms.createTextMessage(text.(string)), nil
	}

	if image, exists := msgData["image"]; exists {
		return ms.createImageMessage(image, msgData, session)
	}

	if video, exists := msgData["video"]; exists {
		return ms.createVideoMessage(video, msgData, session)
	}

	if audio, exists := msgData["audio"]; exists {
		return ms.createAudioMessage(audio, msgData, session)
	}

	if document, exists := msgData["document"]; exists {
		return ms.createDocumentMessage(document, msgData, session)
	}

	if location, exists := msgData["location"]; exists {
		return ms.createLocationMessage(location)
	}

	if contact, exists := msgData["contact"]; exists {
		return ms.createContactMessage(contact)
	}

	// Default to text message if no specific type found
	if msg, exists := msgData["message"]; exists {
		return ms.createTextMessage(msg.(string)), nil
	}

	// Last resort - convert entire JSON to string
	return &proto.Message{
		Conversation: protoUtils.String(messageContent),
	}, nil
}

// createTextMessage creates a text message
func (ms *MessageSender) createTextMessage(text string) *proto.Message {
	// Decode HTML entities to match Node.js behavior (he.decode)
	decodedText := html.UnescapeString(text)
	return &proto.Message{
		Conversation: protoUtils.String(decodedText),
	}
}

// createImageMessage creates an image message with proper WhatsApp media upload
func (ms *MessageSender) createImageMessage(image interface{}, msgData map[string]interface{}, session *goTypes.Session) (*proto.Message, error) {
	// Extract image URL
	imageURL, err := ms.extractMediaURL(image)
	if err != nil {
		ms.logger.WithError(err).Error("Failed to extract image URL")
		return nil, fmt.Errorf("invalid image data: %w", err)
	}

	// Extract caption (optional)
	caption := ""
	if cap, exists := msgData["caption"]; exists {
		if capStr, ok := cap.(string); ok {
			caption = capStr
		}
	}

	ms.logger.WithFields(logrus.Fields{
		"url":     imageURL,
		"caption": caption,
	}).Info("Processing image message")

	// Create context with timeout for download and upload
	ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
	defer cancel()

	// Download image from URL
	imageData, err := ms.downloadMediaFromURL(ctx, imageURL)
	if err != nil {
		ms.logger.WithError(err).WithField("url", imageURL).Error("Failed to download image")
		return nil, fmt.Errorf("failed to download image: %w", err)
	}

	// Detect MIME type
	mimeType := ms.detectMimeType(imageData, imageURL)
	ms.logger.WithFields(logrus.Fields{
		"mime_type": mimeType,
		"data_size": len(imageData),
	}).Debug("Image downloaded, uploading to WhatsApp")

	// Upload to WhatsApp
	uploaded, err := ms.uploadToWhatsApp(ctx, session.Client, imageData, whatsmeow.MediaImage)
	if err != nil {
		ms.logger.WithError(err).Error("Failed to upload image to WhatsApp")
		return nil, fmt.Errorf("failed to upload image: %w", err)
	}

	// Create ImageMessage proto
	imageMsg := &proto.ImageMessage{
		Mimetype:      protoUtils.String(mimeType),
		URL:           protoUtils.String(uploaded.URL),
		DirectPath:    protoUtils.String(uploaded.DirectPath),
		MediaKey:      uploaded.MediaKey,
		FileEncSHA256: uploaded.FileEncSHA256,
		FileSHA256:    uploaded.FileSHA256,
		FileLength:    protoUtils.Uint64(uploaded.FileLength),
	}

	// Add caption if provided (decode HTML entities)
	if caption != "" {
		imageMsg.Caption = protoUtils.String(html.UnescapeString(caption))
	}

	ms.logger.WithFields(logrus.Fields{
		"url":         uploaded.URL,
		"file_length": uploaded.FileLength,
		"caption":     caption,
	}).Info("Image message created successfully")

	return &proto.Message{
		ImageMessage: imageMsg,
	}, nil
}

// createVideoMessage creates a video message with proper WhatsApp media upload
func (ms *MessageSender) createVideoMessage(video interface{}, msgData map[string]interface{}, session *goTypes.Session) (*proto.Message, error) {
	// Extract video URL
	videoURL, err := ms.extractMediaURL(video)
	if err != nil {
		ms.logger.WithError(err).Error("Failed to extract video URL")
		return nil, fmt.Errorf("invalid video data: %w", err)
	}

	// Extract caption (optional)
	caption := ""
	if cap, exists := msgData["caption"]; exists {
		if capStr, ok := cap.(string); ok {
			caption = capStr
		}
	}

	ms.logger.WithFields(logrus.Fields{
		"url":     videoURL,
		"caption": caption,
	}).Info("Processing video message")

	// Create context with timeout (videos can be larger)
	ctx, cancel := context.WithTimeout(context.Background(), 120*time.Second)
	defer cancel()

	// Download video
	videoData, err := ms.downloadMediaFromURL(ctx, videoURL)
	if err != nil {
		ms.logger.WithError(err).WithField("url", videoURL).Error("Failed to download video")
		return nil, fmt.Errorf("failed to download video: %w", err)
	}

	// Detect MIME type
	mimeType := ms.detectMimeType(videoData, videoURL)
	ms.logger.WithFields(logrus.Fields{
		"mime_type": mimeType,
		"data_size": len(videoData),
	}).Debug("Video downloaded, uploading to WhatsApp")

	// Upload to WhatsApp
	uploaded, err := ms.uploadToWhatsApp(ctx, session.Client, videoData, whatsmeow.MediaVideo)
	if err != nil {
		ms.logger.WithError(err).Error("Failed to upload video to WhatsApp")
		return nil, fmt.Errorf("failed to upload video: %w", err)
	}

	// Create VideoMessage proto
	videoMsg := &proto.VideoMessage{
		Mimetype:      protoUtils.String(mimeType),
		URL:           protoUtils.String(uploaded.URL),
		DirectPath:    protoUtils.String(uploaded.DirectPath),
		MediaKey:      uploaded.MediaKey,
		FileEncSHA256: uploaded.FileEncSHA256,
		FileSHA256:    uploaded.FileSHA256,
		FileLength:    protoUtils.Uint64(uploaded.FileLength),
	}

	// Add caption if provided (decode HTML entities)
	if caption != "" {
		videoMsg.Caption = protoUtils.String(html.UnescapeString(caption))
	}

	ms.logger.WithFields(logrus.Fields{
		"url":         uploaded.URL,
		"file_length": uploaded.FileLength,
	}).Info("Video message created successfully")

	return &proto.Message{
		VideoMessage: videoMsg,
	}, nil
}

// createAudioMessage creates an audio message with proper WhatsApp media upload
func (ms *MessageSender) createAudioMessage(audio interface{}, msgData map[string]interface{}, session *goTypes.Session) (*proto.Message, error) {
	// Extract audio URL
	audioURL, err := ms.extractMediaURL(audio)
	if err != nil {
		ms.logger.WithError(err).Error("Failed to extract audio URL")
		return nil, fmt.Errorf("invalid audio data: %w", err)
	}

	ms.logger.WithField("url", audioURL).Info("Processing audio message")

	// Create context with timeout
	ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
	defer cancel()

	// Download audio
	audioData, err := ms.downloadMediaFromURL(ctx, audioURL)
	if err != nil {
		ms.logger.WithError(err).WithField("url", audioURL).Error("Failed to download audio")
		return nil, fmt.Errorf("failed to download audio: %w", err)
	}

	// Detect audio format and get proper MIME type
	audioType := ms.detectAudioType(audioURL, audioData)
	ms.logger.WithFields(logrus.Fields{
		"format":    audioType.Format,
		"mime_type": audioType.MimeType,
		"is_ptt":    audioType.IsPTT,
		"data_size": len(audioData),
	}).Debug("Audio format detected")

	// Calculate audio duration
	audioDuration := ms.calculateAudioDuration(audioData, audioType.Format)
	ms.logger.WithFields(logrus.Fields{
		"duration": audioDuration,
		"format":   audioType.Format,
	}).Debug("Audio duration calculated")

	// Upload to WhatsApp
	uploaded, err := ms.uploadToWhatsApp(ctx, session.Client, audioData, whatsmeow.MediaAudio)
	if err != nil {
		ms.logger.WithError(err).Error("Failed to upload audio to WhatsApp")
		return nil, fmt.Errorf("failed to upload audio: %w", err)
	}

	// Create AudioMessage proto with all required fields
	audioMsg := &proto.AudioMessage{
		URL:           protoUtils.String(uploaded.URL),
		PTT:           protoUtils.Bool(audioType.IsPTT),
		DirectPath:    protoUtils.String(uploaded.DirectPath),
		Mimetype:      protoUtils.String(audioType.MimeType),
		FileLength:    protoUtils.Uint64(uploaded.FileLength),
		FileSHA256:    uploaded.FileSHA256,
		FileEncSHA256: uploaded.FileEncSHA256,
		MediaKey:      uploaded.MediaKey,
		Seconds:       protoUtils.Uint32(audioDuration),
	}

	ms.logger.WithFields(logrus.Fields{
		"url":         uploaded.URL,
		"file_length": uploaded.FileLength,
		"format":      audioType.Format,
		"ptt":         audioType.IsPTT,
		"duration":    audioDuration,
	}).Info("Audio message created successfully")

	return &proto.Message{
		AudioMessage: audioMsg,
	}, nil
}

// createDocumentMessage creates a document message with proper WhatsApp media upload
func (ms *MessageSender) createDocumentMessage(document interface{}, msgData map[string]interface{}, session *goTypes.Session) (*proto.Message, error) {
	// Extract document URL
	docURL, err := ms.extractMediaURL(document)
	if err != nil {
		ms.logger.WithError(err).Error("Failed to extract document URL")
		return nil, fmt.Errorf("invalid document data: %w", err)
	}

	// Extract filename (optional, will use URL filename if not provided)
	filename := ""
	if name, exists := msgData["filename"]; exists {
		if nameStr, ok := name.(string); ok {
			filename = nameStr
		}
	}
	// If no filename provided, extract from URL
	if filename == "" {
		filename = filepath.Base(docURL)
	}

	// Extract caption (optional)
	caption := ""
	if cap, exists := msgData["caption"]; exists {
		if capStr, ok := cap.(string); ok {
			caption = capStr
		}
	}

	ms.logger.WithFields(logrus.Fields{
		"url":      docURL,
		"filename": filename,
		"caption":  caption,
	}).Info("Processing document message")

	// Create context with timeout
	ctx, cancel := context.WithTimeout(context.Background(), 120*time.Second)
	defer cancel()

	// Download document
	docData, err := ms.downloadMediaFromURL(ctx, docURL)
	if err != nil {
		ms.logger.WithError(err).WithField("url", docURL).Error("Failed to download document")
		return nil, fmt.Errorf("failed to download document: %w", err)
	}

	// Detect MIME type
	mimeType := ms.detectMimeType(docData, docURL)
	ms.logger.WithFields(logrus.Fields{
		"mime_type": mimeType,
		"data_size": len(docData),
	}).Debug("Document downloaded, uploading to WhatsApp")

	// Upload to WhatsApp
	uploaded, err := ms.uploadToWhatsApp(ctx, session.Client, docData, whatsmeow.MediaDocument)
	if err != nil {
		ms.logger.WithError(err).Error("Failed to upload document to WhatsApp")
		return nil, fmt.Errorf("failed to upload document: %w", err)
	}

	// Create DocumentMessage proto
	docMsg := &proto.DocumentMessage{
		FileName:      protoUtils.String(filename),
		Mimetype:      protoUtils.String(mimeType),
		URL:           protoUtils.String(uploaded.URL),
		DirectPath:    protoUtils.String(uploaded.DirectPath),
		MediaKey:      uploaded.MediaKey,
		FileEncSHA256: uploaded.FileEncSHA256,
		FileSHA256:    uploaded.FileSHA256,
		FileLength:    protoUtils.Uint64(uploaded.FileLength),
	}

	// Add caption if provided (decode HTML entities)
	if caption != "" {
		docMsg.Caption = protoUtils.String(html.UnescapeString(caption))
	}

	ms.logger.WithFields(logrus.Fields{
		"url":         uploaded.URL,
		"file_length": uploaded.FileLength,
		"filename":    filename,
	}).Info("Document message created successfully")

	return &proto.Message{
		DocumentMessage: docMsg,
	}, nil
}

// detectAudioType detects audio format from URL and file content
func (ms *MessageSender) detectAudioType(audioURL string, audioData []byte) AudioType {
	// Check URL extension first
	urlLower := strings.ToLower(audioURL)

	// Check for OGG format
	if strings.HasSuffix(urlLower, ".ogg") || strings.HasSuffix(urlLower, ".oga") || strings.HasSuffix(urlLower, ".opus") {
		return AudioType{
			Format:   "ogg",
			MimeType: "audio/ogg; codecs=opus",
			IsPTT:    true, // OGG files are voice messages
		}
	}

	// Check for MP3 format
	if strings.HasSuffix(urlLower, ".mp3") {
		return AudioType{
			Format:   "mp3",
			MimeType: "audio/mpeg",
			IsPTT:    false, // MP3 files are regular audio
		}
	}

	// Check file signature if URL extension didn't match
	if len(audioData) >= 4 {
		// OGG signature: "OggS"
		if bytes.Equal(audioData[0:4], []byte("OggS")) {
			return AudioType{
				Format:   "ogg",
				MimeType: "audio/ogg; codecs=opus",
				IsPTT:    true,
			}
		}

		// MP3 signature: ID3 tag or MPEG sync
		if bytes.Equal(audioData[0:3], []byte("ID3")) ||
			(audioData[0] == 0xFF && (audioData[1]&0xE0) == 0xE0) {
			return AudioType{
				Format:   "mp3",
				MimeType: "audio/mpeg",
				IsPTT:    false,
			}
		}
	}

	// Default to OGG voice message if uncertain
	ms.logger.Warn("Unable to detect audio format, defaulting to OGG")
	return AudioType{
		Format:   "ogg",
		MimeType: "audio/ogg; codecs=opus",
		IsPTT:    true,
	}
}

// getOggDuration extracts duration from OGG file by parsing granule position
func (ms *MessageSender) getOggDuration(audioData []byte) uint32 {
	// Find the last OGG page to get final granule position
	lastPageOffset := ms.findLastOggPage(audioData)
	if lastPageOffset == -1 {
		ms.logger.Debug("Could not find last OGG page for duration calculation")
		return 0
	}

	// Read granule position from the last page
	granulePos := ms.readGranulePosition(audioData, lastPageOffset)
	if granulePos == 0 {
		ms.logger.Debug("Could not read granule position from OGG file")
		return 0
	}

	// OGG Opus uses 48kHz sample rate
	// Duration (seconds) = granule_position / 48000
	duration := uint32(granulePos / 48000)

	ms.logger.WithFields(logrus.Fields{
		"granule_position": granulePos,
		"duration":         duration,
	}).Debug("Calculated OGG duration from granule position")

	return duration
}

// findLastOggPage finds the offset of the last OGG page in the data
func (ms *MessageSender) findLastOggPage(data []byte) int {
	// Search backwards for "OggS" signature
	for i := len(data) - 4; i >= 0; i-- {
		if bytes.Equal(data[i:i+4], []byte("OggS")) {
			return i
		}
	}
	return -1
}

// readGranulePosition reads the 64-bit granule position from an OGG page header
func (ms *MessageSender) readGranulePosition(data []byte, offset int) uint64 {
	// OGG page header structure:
	// 0-3: "OggS"
	// 4: version
	// 5: header_type
	// 6-13: granule_position (little-endian 64-bit)

	if offset+14 > len(data) {
		return 0
	}

	// Read 8 bytes starting at offset+6 (little-endian)
	granulePos := uint64(data[offset+6]) |
		uint64(data[offset+7])<<8 |
		uint64(data[offset+8])<<16 |
		uint64(data[offset+9])<<24 |
		uint64(data[offset+10])<<32 |
		uint64(data[offset+11])<<40 |
		uint64(data[offset+12])<<48 |
		uint64(data[offset+13])<<56

	return granulePos
}

// getMP3Duration calculates MP3 duration using go-mp3 library
func (ms *MessageSender) getMP3Duration(audioData []byte) uint32 {
	// Create a reader from the audio data
	reader := bytes.NewReader(audioData)

	// Decode MP3
	decoder, err := mp3.NewDecoder(reader)
	if err != nil {
		ms.logger.WithError(err).Debug("Failed to create MP3 decoder")
		return 0
	}

	// Get sample rate
	sampleRate := decoder.SampleRate()
	if sampleRate == 0 {
		ms.logger.Debug("MP3 sample rate is 0")
		return 0
	}

	// Calculate total samples by reading through the file
	// MP3 decoder provides Length() which returns total samples
	totalSamples := decoder.Length()

	// Duration = total_samples / sample_rate
	duration := uint32(totalSamples / int64(sampleRate))

	ms.logger.WithFields(logrus.Fields{
		"sample_rate":   sampleRate,
		"total_samples": totalSamples,
		"duration":      duration,
	}).Debug("Calculated MP3 duration")

	return duration
}

// estimateDurationFromSize estimates audio duration based on file size
func (ms *MessageSender) estimateDurationFromSize(data []byte, format string) uint32 {
	fileSize := uint64(len(data))

	var bitrate uint64
	if format == "ogg" {
		// OGG Opus typically uses ~24-32 kbps for voice
		bitrate = 28000 // 28 kbps average
	} else if format == "mp3" {
		// MP3 typically uses 128 kbps as standard
		bitrate = 128000 // 128 kbps
	} else {
		// Default estimate
		bitrate = 64000 // 64 kbps
	}

	// Duration (seconds) = (file_size_bytes * 8) / bitrate
	duration := uint32((fileSize * 8) / bitrate)

	ms.logger.WithFields(logrus.Fields{
		"file_size": fileSize,
		"bitrate":   bitrate,
		"format":    format,
		"duration":  duration,
	}).Debug("Estimated duration from file size")

	return duration
}

// calculateAudioDuration calculates audio duration with fallback strategies
func (ms *MessageSender) calculateAudioDuration(audioData []byte, format string) uint32 {
	var duration uint32

	// Try format-specific parsing first
	if format == "ogg" {
		duration = ms.getOggDuration(audioData)
		if duration > 0 {
			ms.logger.WithField("duration", duration).Debug("Got OGG duration from granule position")
			return duration
		}
	} else if format == "mp3" {
		duration = ms.getMP3Duration(audioData)
		if duration > 0 {
			ms.logger.WithField("duration", duration).Debug("Got MP3 duration from decoder")
			return duration
		}
	}

	// Fallback to size estimation
	duration = ms.estimateDurationFromSize(audioData, format)
	if duration > 0 {
		ms.logger.WithField("duration", duration).Debug("Using estimated duration from file size")
		return duration
	}

	// Last resort: default to 1 second
	ms.logger.Warn("Could not calculate audio duration, defaulting to 1 second")
	return 1
}

// createLocationMessage creates a location message
func (ms *MessageSender) createLocationMessage(location interface{}) (*proto.Message, error) {
	// Parse location data
	var lat, lng float64
	var name, address string

	if locMap, ok := location.(map[string]interface{}); ok {
		if latitude, exists := locMap["latitude"]; exists {
			if latFloat, ok := latitude.(float64); ok {
				lat = latFloat
			}
		}
		if longitude, exists := locMap["longitude"]; exists {
			if lngFloat, ok := longitude.(float64); ok {
				lng = lngFloat
			}
		}
		if locName, exists := locMap["name"]; exists {
			if nameStr, ok := locName.(string); ok {
				name = nameStr
			}
		}
		if locAddr, exists := locMap["address"]; exists {
			if addrStr, ok := locAddr.(string); ok {
				address = addrStr
			}
		}
	}

	return &proto.Message{
		LocationMessage: &proto.LocationMessage{
			DegreesLatitude:  protoUtils.Float64(lat),
			DegreesLongitude: protoUtils.Float64(lng),
			Name:             protoUtils.String(name),
			Address:          protoUtils.String(address),
		},
	}, nil
}

// createContactMessage creates a WhatsApp contact message with vCard
func (ms *MessageSender) createContactMessage(contact interface{}) (*proto.Message, error) {
	contactMap, ok := contact.(map[string]interface{})
	if !ok {
		// Fallback to text representation if not a map
		ms.logger.WithField("contact", contact).Warn("Contact is not a map, falling back to text")
		return &proto.Message{
			Conversation: protoUtils.String(fmt.Sprintf("Contact: %v", contact)),
		}, nil
	}

	// Extract contact fields with safe type assertions
	name := ""
	phone := ""

	if nameVal, exists := contactMap["name"]; exists {
		if nameStr, ok := nameVal.(string); ok {
			name = nameStr
		}
	}

	if phoneVal, exists := contactMap["phone"]; exists {
		if phoneStr, ok := phoneVal.(string); ok {
			phone = phoneStr
		}
	}

	// Validate we have at least a name or phone
	if name == "" && phone == "" {
		return nil, fmt.Errorf("contact must have at least a name or phone")
	}

	// Build vCard 3.0 format
	displayName := name
	if displayName == "" {
		displayName = phone
	}

	vcard := fmt.Sprintf("BEGIN:VCARD\nVERSION:3.0\nFN:%s\n", displayName)
	if name != "" {
		vcard += fmt.Sprintf("N:;%s;;;\n", name)
	}
	if phone != "" {
		vcard += fmt.Sprintf("TEL;type=CELL;type=VOICE;waid=%s:%s\n", strings.TrimPrefix(phone, "+"), phone)
	}
	vcard += "END:VCARD"

	ms.logger.WithFields(logrus.Fields{
		"name":  name,
		"phone": phone,
	}).Debug("Creating contact message with vCard")

	return &proto.Message{
		ContactMessage: &proto.ContactMessage{
			DisplayName: protoUtils.String(displayName),
			Vcard:       protoUtils.String(vcard),
		},
	}, nil
}

// downloadMediaFromURL downloads media from a URL
func (ms *MessageSender) downloadMediaFromURL(ctx context.Context, url string) ([]byte, error) {
	ms.logger.WithField("url", url).Debug("Downloading media from URL")

	req, err := http.NewRequestWithContext(ctx, "GET", url, nil)
	if err != nil {
		return nil, fmt.Errorf("failed to create download request: %w", err)
	}

	// Add User-Agent header to prevent 406 errors from CDN/Apache servers
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36")

	resp, err := ms.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("failed to download media: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("download failed with status %d", resp.StatusCode)
	}

	// Check Content-Length to prevent downloading files that are too large
	if resp.ContentLength > 0 {
		if resp.ContentLength > MaxMediaSize {
			ms.logger.WithFields(logrus.Fields{
				"url":            url,
				"content_length": resp.ContentLength,
				"max_size":       MaxMediaSize,
			}).Warn("Media file exceeds size limit, rejecting download")
			return nil, fmt.Errorf("media file too large: %d bytes (max: %d bytes)", resp.ContentLength, MaxMediaSize)
		}
	}

	data, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read media data: %w", err)
	}

	// Double-check actual downloaded size (in case Content-Length was missing or incorrect)
	if len(data) > MaxMediaSize {
		ms.logger.WithFields(logrus.Fields{
			"url":       url,
			"data_size": len(data),
			"max_size":  MaxMediaSize,
		}).Warn("Downloaded media exceeds size limit")
		return nil, fmt.Errorf("downloaded media too large: %d bytes (max: %d bytes)", len(data), MaxMediaSize)
	}

	ms.logger.WithFields(logrus.Fields{
		"url":       url,
		"data_size": len(data),
	}).Debug("Media downloaded successfully")

	return data, nil
}

// uploadToWhatsApp uploads media to WhatsApp servers
func (ms *MessageSender) uploadToWhatsApp(ctx context.Context, client *whatsmeow.Client, data []byte, mediaType whatsmeow.MediaType) (*whatsmeow.UploadResponse, error) {
	ms.logger.WithFields(logrus.Fields{
		"data_size":  len(data),
		"media_type": mediaType,
	}).Debug("Uploading media to WhatsApp")

	uploaded, err := client.Upload(ctx, data, mediaType)
	if err != nil {
		return nil, fmt.Errorf("failed to upload to WhatsApp: %w", err)
	}

	ms.logger.WithFields(logrus.Fields{
		"url":         uploaded.URL,
		"direct_path": uploaded.DirectPath,
		"file_length": uploaded.FileLength,
	}).Debug("Media uploaded to WhatsApp successfully")

	return &uploaded, nil
}

// detectMimeType detects the MIME type of media
func (ms *MessageSender) detectMimeType(data []byte, url string) string {
	// Try to detect from content
	detected := http.DetectContentType(data)
	if detected != "application/octet-stream" {
		return detected
	}

	// Fall back to extension-based detection
	ext := filepath.Ext(url)
	if ext != "" {
		mimeType := mime.TypeByExtension(ext)
		if mimeType != "" {
			return mimeType
		}
	}

	// Default to octet-stream
	return "application/octet-stream"
}

// extractMediaURL extracts URL from media data
func (ms *MessageSender) extractMediaURL(mediaData interface{}) (string, error) {
	// Handle map[string]interface{} with "url" key
	if mediaMap, ok := mediaData.(map[string]interface{}); ok {
		if urlVal, exists := mediaMap["url"]; exists {
			if urlStr, ok := urlVal.(string); ok && urlStr != "" {
				return urlStr, nil
			}
		}
		return "", fmt.Errorf("url key not found or empty in media data")
	}

	// Handle direct string URL
	if urlStr, ok := mediaData.(string); ok && urlStr != "" {
		return urlStr, nil
	}

	return "", fmt.Errorf("invalid media data format")
}

// ValidateMessage checks if a message can be sent
func (ms *MessageSender) ValidateMessage(session *goTypes.Session, message goTypes.QueuedMessage) error {
	// Check if session is connected
	if session.Status != goTypes.StatusConnected {
		return fmt.Errorf("session not connected")
	}

	// Check if WhatsApp client is available
	if session.Client == nil {
		return fmt.Errorf("WhatsApp client not available")
	}

	// Check if client is connected
	if !session.Client.IsConnected() {
		return fmt.Errorf("WhatsApp client not connected")
	}

	// Validate recipient phone number
	if message.Phone == "" {
		return fmt.Errorf("recipient phone number is empty")
	}

	// Validate message content
	if message.Message == "" {
		return fmt.Errorf("message content is empty")
	}

	return nil
}

// SendMessageWithValidation sends a message with validation
func (ms *MessageSender) SendMessageWithValidation(session *goTypes.Session, message goTypes.QueuedMessage) bool {
	// Validate message before sending
	if err := ms.ValidateMessage(session, message); err != nil {
		ms.logger.WithError(err).WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": message.ID,
		}).Error("Message validation failed")
		return false
	}

	// Send the message
	return ms.SendWhatsAppMessage(session, message)
}
