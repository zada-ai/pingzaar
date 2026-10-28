package whatsapp

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"time"

	"github.com/sirupsen/logrus"
)

// FileService handles WhatsApp file operations
type FileService struct {
	basePath string
	logger   *logrus.Logger
}

// NewFileService creates a new file service
func NewFileService(basePath string, logger *logrus.Logger) *FileService {
	return &FileService{
		basePath: basePath,
		logger:   logger,
	}
}

// DownloadMedia retrieves a media file for download
func (s *FileService) DownloadMedia(siteUnique, unique, filename string) (io.ReadSeeker, int64, error) {
	// Construct session ID from parameters (matches our session naming convention)
	sessionID := fmt.Sprintf("%s_%s", unique, siteUnique)

	// Try multiple possible file locations
	possiblePaths := []string{
		// New media storage location (from webhook service)
		filepath.Join(s.basePath, "media", sessionID, filename),
		// Legacy received files location
		filepath.Join(s.basePath, "whatsapp", "received", unique, filename),
		// Alternative media storage with unique only
		filepath.Join(s.basePath, "media", unique, filename),
	}

	var filePath string
	var fileInfo os.FileInfo
	var err error

	// Try each possible location
	for _, path := range possiblePaths {
		fileInfo, err = os.Stat(path)
		if err == nil {
			filePath = path
			break
		}
		if !os.IsNotExist(err) {
			s.logger.WithError(err).WithField("path", path).Debug("Error checking file location")
		}
	}

	// If no file found in any location
	if filePath == "" {
		s.logger.WithFields(logrus.Fields{
			"filename":      filename,
			"session_id":    sessionID,
			"site_unique":   siteUnique,
			"unique":        unique,
			"checked_paths": possiblePaths,
		}).Error("File not found in any location")
		return nil, 0, fmt.Errorf("file not found: %s", filename)
	}

	// Open file for reading
	file, err := os.Open(filePath)
	if err != nil {
		s.logger.WithError(err).WithField("file", filePath).Error("Failed to open file")
		return nil, 0, fmt.Errorf("failed to open file: %v", err)
	}

	s.logger.WithFields(logrus.Fields{
		"file":       filePath,
		"size":       fileInfo.Size(),
		"session_id": sessionID,
		"filename":   filename,
	}).Info("Media file opened for download")

	return file, fileInfo.Size(), nil
}

// CleanupOldFiles removes files older than specified days
func (s *FileService) CleanupOldFiles(days int) error {
	if days <= 0 {
		return fmt.Errorf("invalid days parameter: %d", days)
	}

	cutoffTime := time.Now().AddDate(0, 0, -days)
	receivedPath := filepath.Join(s.basePath, "whatsapp", "received")

	var deletedCount int
	var totalSize int64

	err := filepath.Walk(receivedPath, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			s.logger.WithError(err).WithField("path", path).Warn("Error accessing path during cleanup")
			return nil // Continue walking
		}

		// Skip directories
		if info.IsDir() {
			return nil
		}

		// Check if file is older than cutoff time
		if info.ModTime().Before(cutoffTime) {
			size := info.Size()
			if err := os.Remove(path); err != nil {
				s.logger.WithError(err).WithField("file", path).Error("Failed to delete old file")
				return nil // Continue walking
			}

			deletedCount++
			totalSize += size
			s.logger.WithFields(logrus.Fields{
				"file": path,
				"age":  time.Since(info.ModTime()),
				"size": size,
			}).Debug("Deleted old file")
		}

		return nil
	})

	if err != nil {
		s.logger.WithError(err).Error("Error walking directory during cleanup")
		return fmt.Errorf("cleanup failed: %v", err)
	}

	s.logger.WithFields(logrus.Fields{
		"deleted_files": deletedCount,
		"total_size":    totalSize,
		"days":          days,
	}).Info("File cleanup completed")

	return nil
}

// GetFileInfo returns information about a file
func (s *FileService) GetFileInfo(unique, filename string) (os.FileInfo, error) {
	filePath := filepath.Join(s.basePath, "whatsapp", "received", unique, filename)

	info, err := os.Stat(filePath)
	if err != nil {
		s.logger.WithError(err).WithField("file", filePath).Error("Failed to get file info")
		return nil, err
	}

	return info, nil
}

// EnsureDirectory creates directories if they don't exist
func (s *FileService) EnsureDirectory(unique string) error {
	dirPath := filepath.Join(s.basePath, "whatsapp", "received", unique)

	err := os.MkdirAll(dirPath, 0755)
	if err != nil {
		s.logger.WithError(err).WithField("dir", dirPath).Error("Failed to create directory")
		return fmt.Errorf("failed to create directory: %v", err)
	}

	return nil
}
