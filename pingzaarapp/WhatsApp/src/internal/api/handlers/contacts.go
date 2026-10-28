package handlers

import (
	"github.com/gin-gonic/gin"
	"github.com/sirupsen/logrus"

	"whatsapp-server/internal/api/helpers"
	"whatsapp-server/internal/config"
	"whatsapp-server/internal/whatsapp"
)

// ContactHandler handles contact-related API endpoints
type ContactHandler struct {
	config     *config.Config
	sessionMgr *whatsapp.SessionManager
	contactSvc *whatsapp.ContactService
	logger     *logrus.Logger
}

// NewContactHandler creates a new contact handler
func NewContactHandler(cfg *config.Config, sessionMgr *whatsapp.SessionManager, logger *logrus.Logger) *ContactHandler {
	return &ContactHandler{
		config:     cfg,
		sessionMgr: sessionMgr,
		contactSvc: whatsapp.NewContactService(sessionMgr, logger),
		logger:     logger,
	}
}

// GetGroups returns all WhatsApp groups for a session
func (h *ContactHandler) GetGroups(c *gin.Context) {
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

	// Get groups
	groups, err := h.contactSvc.GetGroups(session.ID)
	if err != nil {
		h.logger.WithError(err).Error("Failed to get groups")
		helpers.Response(c, 500, "Failed to get groups", false)
		return
	}

	// PHP library expects array in data (line 376)
	helpers.Response(c, 200, false, groups)
}

// ValidateNumber validates if a phone number is registered on WhatsApp
func (h *ContactHandler) ValidateNumber(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")
	address := c.Param("address")

	// Check if session exists
	session, exists := h.sessionMgr.GetSessionByParams(siteUnique, unique)
	if !exists {
		helpers.Response(c, 404, "Session not found", false)
		return
	}

	// Validate phone number
	result, err := h.contactSvc.ValidateNumber(session.ID, address)
	if err != nil {
		h.logger.WithError(err).Error("Failed to validate number")
		helpers.Response(c, 404, "Phone number not on WhatsApp", false)
		return
	}

	// PHP library expects object with jid and phone (lines 430-433)
	helpers.Response(c, 200, false, gin.H{
		"jid":   result.JID,
		"phone": result.Phone,
	})
}
