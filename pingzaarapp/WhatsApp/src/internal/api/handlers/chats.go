package handlers

import (
	"context"
	"fmt"
	"hash/fnv"
	"strconv"

	"github.com/gin-gonic/gin"
	"github.com/sirupsen/logrus"

	"whatsapp-server/internal/api/helpers"
	"whatsapp-server/internal/config"
	"whatsapp-server/internal/whatsapp"
	"whatsapp-server/pkg/types"
)

// ChatHandler handles chat-related API endpoints
type ChatHandler struct {
	config         *config.Config
	sessionMgr     *whatsapp.SessionManager
	messageSvc     *whatsapp.MessageService
	messageFetcher *whatsapp.MessageFetcher
	statusReporter *whatsapp.StatusReporter // Shared reporter to avoid goroutine leaks
	logger         *logrus.Logger
}

// NewChatHandler creates a new chat handler
func NewChatHandler(cfg *config.Config, sessionMgr *whatsapp.SessionManager, logger *logrus.Logger) *ChatHandler {
	return &ChatHandler{
		config:         cfg,
		sessionMgr:     sessionMgr,
		messageSvc:     whatsapp.NewMessageService(sessionMgr, logger),
		messageFetcher: whatsapp.NewMessageFetcher(sessionMgr, logger),
		statusReporter: whatsapp.NewStatusReporter(sessionMgr, logger),
		logger:         logger,
	}
}

// hash generates a simple hash from a string for ID conversion fallback
func hash(s string) uint32 {
	h := fnv.New32a()
	h.Write([]byte(s))
	return h.Sum32()
}

// SendMessage sends a WhatsApp message
func (h *ChatHandler) SendMessage(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")

	// Check if session exists
	session, exists := h.sessionMgr.GetSessionByParams(siteUnique, unique)
	if !exists {
		helpers.Response(c, 404, "Session not found", false)
		return
	}

	// Check if session is connected
	if !h.sessionMgr.IsSessionConnected(session.ID) {
		helpers.Response(c, 503, "Session not connected", false)
		return
	}

	var req types.SendMessageRequest

	// Handle form data (PHP sends form_params)
	if err := c.ShouldBind(&req); err != nil {
		helpers.Response(c, 400, "Invalid form data", false)
		return
	}

	// Convert ID from string to int for message processing
	var messageID int
	if idInt, parseErr := strconv.Atoi(req.ID); parseErr == nil {
		messageID = idInt
	} else {
		h.logger.WithError(parseErr).WithField("id", req.ID).Warn("Failed to parse message ID as integer, using hash")
		// Use a hash of the ID string as fallback
		messageID = int(hash(req.ID))
	}

	// Send message immediately (priority message) using JSON parsing
	err := h.sendPriorityMessage(
		c.Request.Context(),
		session,
		req.Recipient,
		req.Message,
		messageID,
	)

	// Report status back to server (like Node.js sendPriority)
	success := err == nil

	// Create a QueuedMessage for status reporting (with CID=0 for priority messages)
	queuedMsg := types.QueuedMessage{
		ID:       messageID, // Converted to int
		CID:      0,         // Priority messages use CID=0 like Node.js
		Phone:    req.Recipient,
		Message:  req.Message,
		Priority: 1, // High priority
		WID:      session.Cache.Unique,
	}

	// Report status to server using shared reporter (avoids goroutine leak)
	if reportErr := h.statusReporter.ReportMessageStatus(session, queuedMsg, success); reportErr != nil {
		h.logger.WithError(reportErr).WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": req.ID,
			"success":    success,
		}).Error("Failed to report priority message status to server")
	}

	if err != nil {
		h.logger.WithError(err).Error("Failed to send priority message")
		helpers.Response(c, 500, "Failed to send message", false)
		return
	}

	helpers.Response(c, 200, false, gin.H{
		"id":        req.ID,
		"recipient": req.Recipient,
		"message":   req.Message,
		"status":    "sent",
	})
}

// sendPriorityMessage sends a message immediately using JSON parsing like queued messages
func (h *ChatHandler) sendPriorityMessage(ctx context.Context, session *types.Session, recipient, message string, messageID int) error {
	// Create MessageSender instance
	messageSender := whatsapp.NewMessageSender(h.sessionMgr, h.logger)

	// Create a temporary QueuedMessage to leverage existing JSON parsing logic
	queuedMsg := types.QueuedMessage{
		ID:       messageID,
		CID:      0, // Priority messages use CID=0
		Phone:    recipient,
		Message:  message,
		Priority: 1, // High priority
		WID:      session.Cache.Unique,
	}

	// Use the existing SendWhatsAppMessage method which handles JSON parsing
	success := messageSender.SendWhatsAppMessage(session, queuedMsg)
	if !success {
		return fmt.Errorf("failed to send priority message via WhatsApp")
	}

	h.logger.WithFields(logrus.Fields{
		"session_id": session.ID,
		"recipient":  recipient,
		"message":    message,
		"message_id": messageID,
	}).Info("Priority message sent successfully with JSON parsing")

	return nil
}

// GetChatQueue triggers message fetching and returns queue status
// This corresponds to the PHP library's "send" method that triggers fetching
func (h *ChatHandler) GetChatQueue(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")

	// Check if session exists
	session, exists := h.sessionMgr.GetSessionByParams(siteUnique, unique)
	if !exists {
		helpers.Response(c, 404, "Session not found", false)
		return
	}

	// Check if session is connected
	if !h.sessionMgr.IsSessionConnected(session.ID) {
		helpers.Response(c, 503, "Session not connected", false)
		return
	}

	h.logger.WithFields(logrus.Fields{
		"session_id":        session.ID,
		"site_unique":       siteUnique,
		"unique":            unique,
		"queue_size_before": session.MessageQueue.Size(),
	}).Info("Triggered message fetching for session")

	// Fetch messages from server (on-demand)
	if err := h.messageFetcher.FetchMessagesOnDemand(session); err != nil {
		h.logger.WithError(err).WithFields(logrus.Fields{
			"session_id":  session.ID,
			"site_unique": siteUnique,
			"unique":      unique,
		}).Error("Failed to fetch messages from server")

		// Still return success but with error info for debugging
		helpers.Response(c, 200, false, gin.H{
			"status":          "fetch_error",
			"error":           err.Error(),
			"queue_size":      session.MessageQueue.Size(),
			"queue_available": session.MessageQueue.GetAvailableSpace(),
			"queue_max_size":  session.MessageQueue.GetMaxSize(),
		})
		return
	}

	// Return success with queue information
	helpers.Response(c, 200, false, gin.H{
		"status":          "messages_fetched",
		"queue_size":      session.MessageQueue.Size(),
		"queue_available": session.MessageQueue.GetAvailableSpace(),
		"queue_max_size":  session.MessageQueue.GetMaxSize(),
		"account_settings": gin.H{
			"receive_chats": session.Cache.ReceiveChats,
			"random_send":   session.Cache.RandomSend,
			"random_min":    session.Cache.RandomMin,
			"random_max":    session.Cache.RandomMax,
		},
	})
}

// DeleteChat deletes a chat message (placeholder)
func (h *ChatHandler) DeleteChat(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")
	hash := c.Param("hash")
	cid := c.Param("cid")
	id := c.Param("id")

	h.logger.WithFields(logrus.Fields{
		"site_unique": siteUnique,
		"unique":      unique,
		"hash":        hash,
		"cid":         cid,
		"id":          id,
	}).Info("Delete chat requested")

	helpers.Response(c, 200, "Chat deleted successfully", false)
}

// StartCampaign starts/resumes a messaging campaign
func (h *ChatHandler) StartCampaign(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")
	hash := c.Param("hash")
	cidStr := c.Param("cid")

	// Parse campaign ID
	cid, err := strconv.Atoi(cidStr)
	if err != nil {
		h.logger.WithError(err).WithField("cid", cidStr).Warn("Invalid campaign ID format")
		helpers.Response(c, 400, "Invalid campaign ID", false)
		return
	}

	// Check if session exists
	session, exists := h.sessionMgr.GetSessionByParams(siteUnique, unique)
	if !exists {
		helpers.Response(c, 404, "Session not found", false)
		return
	}

	// Resume campaign in memory
	session.ResumeCampaign(cid)

	// Update CStatus on all queued messages with this CID
	updatedCount := session.MessageQueue.UpdateCampaignStatus(cid, 1) // 1 = running

	// Trigger processing to start sending
	if updatedCount > 0 {
		h.sessionMgr.OnMessagesAdded(session.ID)
	}

	h.logger.WithFields(logrus.Fields{
		"session_id":       session.ID,
		"site_unique":      siteUnique,
		"unique":           unique,
		"hash":             hash,
		"cid":              cid,
		"messages_resumed": updatedCount,
		"queue_size":       session.MessageQueue.Size(),
	}).Info("Campaign resumed successfully")

	helpers.Response(c, 200, false, gin.H{
		"status":           "resumed",
		"messages_resumed": updatedCount,
		"queue_size":       session.MessageQueue.Size(),
	})
}

// StopCampaign stops/pauses a messaging campaign
func (h *ChatHandler) StopCampaign(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")
	hash := c.Param("hash")
	cidStr := c.Param("cid")

	// Parse campaign ID
	cid, err := strconv.Atoi(cidStr)
	if err != nil {
		h.logger.WithError(err).WithField("cid", cidStr).Warn("Invalid campaign ID format")
		helpers.Response(c, 400, "Invalid campaign ID", false)
		return
	}

	// Check if session exists
	session, exists := h.sessionMgr.GetSessionByParams(siteUnique, unique)
	if !exists {
		helpers.Response(c, 404, "Session not found", false)
		return
	}

	// Mark campaign as paused in memory
	session.PauseCampaign(cid)

	// Update CStatus on all queued messages with this CID
	// This ensures messages are skipped even if server restarts
	updatedCount := session.MessageQueue.UpdateCampaignStatus(cid, 2) // 2 = paused

	h.logger.WithFields(logrus.Fields{
		"session_id":      session.ID,
		"site_unique":     siteUnique,
		"unique":          unique,
		"hash":            hash,
		"cid":             cid,
		"messages_paused": updatedCount,
		"queue_size":      session.MessageQueue.Size(),
	}).Info("Campaign paused successfully")

	helpers.Response(c, 200, false, gin.H{
		"status":          "paused",
		"messages_paused": updatedCount,
		"queue_size":      session.MessageQueue.Size(),
	})
}

// DeleteCampaign deletes a messaging campaign (placeholder)
func (h *ChatHandler) DeleteCampaign(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")
	hash := c.Param("hash")
	cid := c.Param("cid")

	h.logger.WithFields(logrus.Fields{
		"site_unique": siteUnique,
		"unique":      unique,
		"hash":        hash,
		"cid":         cid,
	}).Info("Campaign delete requested")

	helpers.Response(c, 200, "Campaign deleted successfully", false)
}

// CheckMessageInQueue checks if a message exists in the session's queue (RAM or SQLite)
// Used by PHP cron job to detect stuck messages that were sent but callback failed
func (h *ChatHandler) CheckMessageInQueue(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")
	messageIDStr := c.Param("message_id")
	cidStr := c.Param("cid")

	// Parse message_id
	messageID, err := strconv.Atoi(messageIDStr)
	if err != nil {
		h.logger.WithError(err).WithField("message_id", messageIDStr).Warn("Invalid message ID format")
		helpers.Response(c, 400, "Invalid message ID", false)
		return
	}

	// Parse cid
	cid, err := strconv.Atoi(cidStr)
	if err != nil {
		h.logger.WithError(err).WithField("cid", cidStr).Warn("Invalid campaign ID format")
		helpers.Response(c, 400, "Invalid campaign ID", false)
		return
	}

	// Check if session exists
	session, exists := h.sessionMgr.GetSessionByParams(siteUnique, unique)
	if !exists {
		// Session not found - message is definitely not in queue
		helpers.Response(c, 200, false, gin.H{
			"in_queue": false,
		})
		return
	}

	// Check RAM queue first (faster)
	inRAMQueue := session.MessageQueue.Contains(messageID, cid)

	if inRAMQueue {
		h.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": messageID,
			"cid":        cid,
			"location":   "ram",
		}).Debug("Message found in RAM queue")

		helpers.Response(c, 200, false, gin.H{
			"in_queue": true,
		})
		return
	}

	// Check SQLite database (persistence layer)
	inDBQueue, err := whatsapp.MessageExistsInDB(session, messageID, cid, h.logger)
	if err != nil {
		h.logger.WithError(err).WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": messageID,
			"cid":        cid,
		}).Warn("Failed to check message in database")

		// On error, assume not in queue (safer - will mark as sent)
		helpers.Response(c, 200, false, gin.H{
			"in_queue": false,
		})
		return
	}

	if inDBQueue {
		h.logger.WithFields(logrus.Fields{
			"session_id": session.ID,
			"message_id": messageID,
			"cid":        cid,
			"location":   "sqlite",
		}).Debug("Message found in SQLite database")
	}

	helpers.Response(c, 200, false, gin.H{
		"in_queue": inDBQueue,
	})
}
