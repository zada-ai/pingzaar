package handlers

import (
	"fmt"
	"io"
	"os"
	"strconv"

	"github.com/gin-gonic/gin"
	"github.com/sirupsen/logrus"

	"whatsapp-server/internal/api/helpers"
	"whatsapp-server/internal/config"
	"whatsapp-server/internal/whatsapp"
)

// FileHandler handles file-related API endpoints
type FileHandler struct {
	config     *config.Config
	sessionMgr *whatsapp.SessionManager
	fileSvc    *whatsapp.FileService
	logger     *logrus.Logger
}

// NewFileHandler creates a new file handler
func NewFileHandler(cfg *config.Config, sessionMgr *whatsapp.SessionManager, logger *logrus.Logger) *FileHandler {
	return &FileHandler{
		config:     cfg,
		sessionMgr: sessionMgr,
		fileSvc:    whatsapp.NewFileService("./storage", logger),
		logger:     logger,
	}
}

// Download handles file downloads
func (h *FileHandler) Download(c *gin.Context) {
	// Validate secret
	secret := c.Param("secret")
	if secret != h.config.Server.Secret {
		helpers.Response(c, 401, "Unauthorized", false)
		return
	}

	siteUnique := c.Param("site_unique")
	unique := c.Param("unique")
	filename := c.Param("file")

	// Check if session exists
	session, exists := h.sessionMgr.GetSessionByParams(siteUnique, unique)
	if !exists {
		helpers.Response(c, 404, "Session not found", false)
		return
	}

	// Get file
	reader, size, err := h.fileSvc.DownloadMedia(siteUnique, session.Cache.Unique, filename)
	if err != nil {
		h.logger.WithError(err).WithField("filename", filename).Error("Failed to download file")
		helpers.Response(c, 404, "File not found", false)
		return
	}

	// Construct expected file path for cleanup (matches webhook.go save location)
	// This path should match where saveMediaFile() saves files
	sessionID := session.ID
	filePath := fmt.Sprintf("storage/media/%s/%s", sessionID, filename)

	defer func() {
		// Close reader
		if closer, ok := reader.(io.Closer); ok {
			closer.Close()
		}

		// Delete file after streaming completes (failsafe cleanup)
		// Primary cleanup happens in webhook.go after webhook success
		// This serves as backup for edge cases
		if removeErr := os.Remove(filePath); removeErr != nil {
			if !os.IsNotExist(removeErr) {
				h.logger.WithError(removeErr).WithFields(logrus.Fields{
					"filename":   filename,
					"file_path":  filePath,
					"session_id": session.ID,
				}).Warn("Failed to delete media file after download")
			}
			// Silently ignore "file not found" - may have been deleted by webhook cleanup
		} else {
			h.logger.WithFields(logrus.Fields{
				"filename":   filename,
				"file_path":  filePath,
				"session_id": session.ID,
			}).Debug("Media file deleted after download (failsafe cleanup)")
		}
	}()

	// Set headers for file download
	c.Header("Content-Type", "application/octet-stream")
	c.Header("Content-Disposition", "attachment; filename=\""+filename+"\"")
	c.Header("Content-Length", strconv.FormatInt(size, 10))

	// Stream the file
	_, err = io.Copy(c.Writer, reader)
	if err != nil {
		h.logger.WithError(err).Error("Failed to stream file")
		return
	}

	h.logger.WithFields(logrus.Fields{
		"filename":   filename,
		"size":       size,
		"session_id": session.ID,
	}).Info("File downloaded successfully")
}

// GarbageCollect handles cleanup of old files
func (h *FileHandler) GarbageCollect(c *gin.Context) {
	// Note: No secret validation needed per PHP implementation
	daysParam := c.Param("days")

	days, err := strconv.Atoi(daysParam)
	if err != nil || days <= 0 {
		helpers.Response(c, 400, "Invalid days parameter", false)
		return
	}

	err = h.fileSvc.CleanupOldFiles(days)
	if err != nil {
		h.logger.WithError(err).Error("Failed to cleanup files")
		helpers.Response(c, 500, "Failed to cleanup files", false)
		return
	}

	// PHP library expects status 200 (line 265: return $garbage->status == 200)
	helpers.Response(c, 200, "Cleanup completed", false)
}
