package handlers

import (
	"strconv"

	"github.com/gin-gonic/gin"
	"github.com/sirupsen/logrus"

	"whatsapp-server/internal/api/helpers"
	"whatsapp-server/internal/config"
	"whatsapp-server/internal/whatsapp"
	"whatsapp-server/pkg/types"
)

// AccountHandler handles account-related API endpoints
type AccountHandler struct {
	config     *config.Config
	sessionMgr *whatsapp.SessionManager
	logger     *logrus.Logger
}

// NewAccountHandler creates a new account handler
func NewAccountHandler(cfg *config.Config, sessionMgr *whatsapp.SessionManager, logger *logrus.Logger) *AccountHandler {
	return &AccountHandler{
		config:     cfg,
		sessionMgr: sessionMgr,
		logger:     logger,
	}
}

// CreateSession creates a new WhatsApp session
func (h *AccountHandler) CreateSession(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	var req types.CreateSessionRequest

	// Handle form data (PHP sends form_params)
	if err := c.ShouldBind(&req); err != nil {
		helpers.Response(c, 400, "Invalid form data", false)
		return
	}

	// Create session cache
	cache := &types.SessionCache{
		SystemToken: req.SystemToken,
		SiteUnique:  req.SiteUnique,
		SiteURL:     req.SiteURL,
		APIToken:    req.APIToken,
		WSID:        req.WSID,
		UID:         req.UID,
		Hash:        req.Hash,
		Unique:      req.Unique,
	}

	// Create session
	session, err := h.sessionMgr.CreateSession(c.Request.Context(), cache)
	if err != nil {
		h.logger.WithError(err).Error("Failed to create session")
		helpers.Response(c, 500, "Failed to create session", false)
		return
	}

	// Connect session and handle QR
	qrResult, err := h.sessionMgr.ConnectSession(c.Request.Context(), session.ID)
	if err != nil {
		h.logger.WithError(err).Error("Failed to connect session")
		helpers.Response(c, 500, "Failed to connect session", false)
		return
	}

	if qrResult.Code != "" {
		// PHP library expects data.qr (line 86)
		helpers.Response(c, 200, false, gin.H{"qr": qrResult.Code})
	} else {
		helpers.Response(c, 200, false, gin.H{
			"session_id": session.ID,
			"created_at": session.CreatedAt,
		})
	}
}

// GetSessionStatus returns the status of a session
func (h *AccountHandler) GetSessionStatus(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")

	session, exists := h.sessionMgr.GetSessionByParams(siteUnique, unique)
	if !exists {
		helpers.Response(c, 404, "Session not found", false)
		return
	}

	status := h.sessionMgr.GetSessionStatus(session.ID)
	helpers.Response(c, 200, false, status)
}

// DeleteSession deletes a session
func (h *AccountHandler) DeleteSession(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")

	session, exists := h.sessionMgr.GetSessionByParams(siteUnique, unique)
	if !exists {
		helpers.Response(c, 404, "Session not found", false)
		return
	}

	err := h.sessionMgr.DeleteSession(c.Request.Context(), session.ID)
	if err != nil {
		h.logger.WithError(err).Error("Failed to delete session")
		helpers.Response(c, 500, "Failed to delete session", false)
		return
	}

	// PHP library expects status 200 (line 140)
	helpers.Response(c, 200, "Session deleted successfully", false)
}

// GetTotalSessions returns the total number of sessions
func (h *AccountHandler) GetTotalSessions(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	total := h.sessionMgr.GetTotalSessions()
	// PHP library expects data directly (line 52)
	helpers.Response(c, 200, false, total)
}

// UpdateSession updates session settings
func (h *AccountHandler) UpdateSession(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")

	session, exists := h.sessionMgr.GetSessionByParams(siteUnique, unique)
	if !exists {
		helpers.Response(c, 404, "Session not found", false)
		return
	}

	var req types.UpdateSessionRequest

	// Handle form data (PHP sends form_params)
	if err := c.ShouldBind(&req); err != nil {
		helpers.Response(c, 400, "Invalid form data", false)
		return
	}

	// Update session cache with all settings from PHP
	session.Cache.WSID = req.WSID
	session.Cache.ReceiveChats = parseIntSafe(req.ReceiveChats, 1)
	session.Cache.RandomSend = parseIntSafe(req.RandomSend, 1)
	session.Cache.RandomMin = parseIntSafe(req.RandomMin, 1)
	session.Cache.RandomMax = parseIntSafe(req.RandomMax, 5)

	// Save updated cache to file
	if err := h.sessionMgr.SaveSessionCache(session.ID, session.Cache); err != nil {
		h.logger.WithError(err).WithField("session_id", session.ID).
			Warn("Failed to save updated session cache")
	}

	h.logger.WithFields(logrus.Fields{
		"session_id":    session.ID,
		"wsid":          session.Cache.WSID,
		"receive_chats": session.Cache.ReceiveChats,
		"random_send":   session.Cache.RandomSend,
		"random_min":    session.Cache.RandomMin,
		"random_max":    session.Cache.RandomMax,
	}).Info("Session settings updated successfully")

	// PHP library expects status 200 (line 118)
	helpers.Response(c, 200, "Session updated successfully", false)
}

// parseIntSafe safely converts a string to int with a default fallback
func parseIntSafe(value string, defaultValue int) int {
	if intValue, err := strconv.Atoi(value); err == nil {
		return intValue
	}
	return defaultValue
}
