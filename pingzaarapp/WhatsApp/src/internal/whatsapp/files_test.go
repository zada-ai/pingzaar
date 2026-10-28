package whatsapp

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"github.com/stretchr/testify/suite"

	"whatsapp-server/tests/helpers"
)

type FileServiceTestSuite struct {
	suite.Suite
	tempDir     string
	fileService *FileService
}

func (suite *FileServiceTestSuite) SetupTest() {
	suite.tempDir = suite.T().TempDir()
	logger := helpers.TestLogger()

	suite.fileService = NewFileService(suite.tempDir, logger)
}

func (suite *FileServiceTestSuite) TestNewFileService() {
	logger := helpers.TestLogger()
	basePath := "/test/path"

	service := NewFileService(basePath, logger)

	assert.NotNil(suite.T(), service)
	assert.Equal(suite.T(), basePath, service.basePath)
	assert.Equal(suite.T(), logger, service.logger)
}

func (suite *FileServiceTestSuite) TestDownloadMedia_Success() {
	siteUnique := "site1"
	unique := "user1"
	filename := "test.jpg"

	// Create the directory structure and test file
	mediaDir := filepath.Join(suite.tempDir, "whatsapp", "received", unique)
	err := os.MkdirAll(mediaDir, 0755)
	require.NoError(suite.T(), err)

	testContent := []byte("fake image content")
	testFile := filepath.Join(mediaDir, filename)
	err = os.WriteFile(testFile, testContent, 0644)
	require.NoError(suite.T(), err)

	reader, size, err := suite.fileService.DownloadMedia(siteUnique, unique, filename)

	assert.NoError(suite.T(), err)
	assert.NotNil(suite.T(), reader)
	assert.Equal(suite.T(), int64(len(testContent)), size)

	// Read the content
	content, err := io.ReadAll(reader)
	assert.NoError(suite.T(), err)
	assert.Equal(suite.T(), testContent, content)

	// Close the reader
	if closer, ok := reader.(io.Closer); ok {
		err = closer.Close()
		assert.NoError(suite.T(), err)
	}
}

func (suite *FileServiceTestSuite) TestDownloadMedia_FileNotFound() {
	siteUnique := "site1"
	unique := "user1"
	filename := "nonexistent.jpg"

	reader, size, err := suite.fileService.DownloadMedia(siteUnique, unique, filename)

	assert.Error(suite.T(), err)
	assert.Nil(suite.T(), reader)
	assert.Equal(suite.T(), int64(0), size)
	assert.Contains(suite.T(), err.Error(), "file not found")
}

func (suite *FileServiceTestSuite) TestDownloadMedia_PermissionError() {
	siteUnique := "site1"
	unique := "user1"
	filename := "restricted.jpg"

	// Create the directory structure and test file with restricted permissions
	mediaDir := filepath.Join(suite.tempDir, "whatsapp", "received", unique)
	err := os.MkdirAll(mediaDir, 0755)
	require.NoError(suite.T(), err)

	testFile := filepath.Join(mediaDir, filename)
	err = os.WriteFile(testFile, []byte("content"), 0000) // No permissions
	require.NoError(suite.T(), err)

	reader, size, err := suite.fileService.DownloadMedia(siteUnique, unique, filename)

	assert.Error(suite.T(), err)
	assert.Nil(suite.T(), reader)
	assert.Equal(suite.T(), int64(0), size)
	assert.Contains(suite.T(), err.Error(), "failed to open file")

	// Restore permissions for cleanup
	_ = os.Chmod(testFile, 0644)
}

func (suite *FileServiceTestSuite) TestDownloadMedia_EmptyFile() {
	siteUnique := "site1"
	unique := "user1"
	filename := "empty.txt"

	// Create the directory structure and empty test file
	mediaDir := filepath.Join(suite.tempDir, "whatsapp", "received", unique)
	err := os.MkdirAll(mediaDir, 0755)
	require.NoError(suite.T(), err)

	testFile := filepath.Join(mediaDir, filename)
	err = os.WriteFile(testFile, []byte{}, 0644)
	require.NoError(suite.T(), err)

	reader, size, err := suite.fileService.DownloadMedia(siteUnique, unique, filename)

	assert.NoError(suite.T(), err)
	assert.NotNil(suite.T(), reader)
	assert.Equal(suite.T(), int64(0), size)

	// Read the content
	content, err := io.ReadAll(reader)
	assert.NoError(suite.T(), err)
	assert.Empty(suite.T(), content)

	// Close the reader
	if closer, ok := reader.(io.Closer); ok {
		_ = closer.Close()
	}
}

func (suite *FileServiceTestSuite) TestCleanupOldFiles_Success() {
	// Create directory structure
	receivedDir := filepath.Join(suite.tempDir, "whatsapp", "received")
	err := os.MkdirAll(receivedDir, 0755)
	require.NoError(suite.T(), err)

	// Create old files (older than 7 days)
	oldTime := time.Now().AddDate(0, 0, -10)
	oldFiles := []string{"old1.txt", "old2.jpg", "old3.pdf"}

	for _, filename := range oldFiles {
		filePath := filepath.Join(receivedDir, filename)
		err := os.WriteFile(filePath, []byte("old content"), 0644)
		require.NoError(suite.T(), err)

		// Set modification time to old date
		err = os.Chtimes(filePath, oldTime, oldTime)
		require.NoError(suite.T(), err)
	}

	// Create new files (recent)
	newFiles := []string{"new1.txt", "new2.jpg"}
	for _, filename := range newFiles {
		filePath := filepath.Join(receivedDir, filename)
		err := os.WriteFile(filePath, []byte("new content"), 0644)
		require.NoError(suite.T(), err)
	}

	err = suite.fileService.CleanupOldFiles(7)

	assert.NoError(suite.T(), err)

	// Check that old files were deleted
	for _, filename := range oldFiles {
		filePath := filepath.Join(receivedDir, filename)
		assert.NoFileExists(suite.T(), filePath)
	}

	// Check that new files still exist
	for _, filename := range newFiles {
		filePath := filepath.Join(receivedDir, filename)
		assert.FileExists(suite.T(), filePath)
	}
}

func (suite *FileServiceTestSuite) TestCleanupOldFiles_InvalidDays() {
	err := suite.fileService.CleanupOldFiles(0)
	assert.Error(suite.T(), err)
	assert.Contains(suite.T(), err.Error(), "invalid days parameter")

	err = suite.fileService.CleanupOldFiles(-5)
	assert.Error(suite.T(), err)
	assert.Contains(suite.T(), err.Error(), "invalid days parameter")
}

func (suite *FileServiceTestSuite) TestCleanupOldFiles_NonexistentDirectory() {
	// Use a fileservice with non-existent base path
	logger := helpers.TestLogger()
	service := NewFileService("/nonexistent/path", logger)

	err := service.CleanupOldFiles(30)

	// Should not panic, but may return an error
	// The exact behavior depends on how filepath.Walk handles non-existent paths
	if err != nil {
		assert.Contains(suite.T(), err.Error(), "cleanup failed")
	}
}

func (suite *FileServiceTestSuite) TestCleanupOldFiles_WithSubdirectories() {
	// Create directory structure with subdirectories
	receivedDir := filepath.Join(suite.tempDir, "whatsapp", "received")
	subDir := filepath.Join(receivedDir, "user1")
	err := os.MkdirAll(subDir, 0755)
	require.NoError(suite.T(), err)

	// Create old files in subdirectory
	oldTime := time.Now().AddDate(0, 0, -10)
	oldFile := filepath.Join(subDir, "old_file.txt")
	err = os.WriteFile(oldFile, []byte("old content"), 0644)
	require.NoError(suite.T(), err)
	err = os.Chtimes(oldFile, oldTime, oldTime)
	require.NoError(suite.T(), err)

	err = suite.fileService.CleanupOldFiles(7)

	assert.NoError(suite.T(), err)
	assert.NoFileExists(suite.T(), oldFile)
	assert.DirExists(suite.T(), subDir) // Directory should still exist
}

func (suite *FileServiceTestSuite) TestGetFileInfo_Success() {
	unique := "user1"
	filename := "info_test.txt"

	// Create the directory structure and test file
	mediaDir := filepath.Join(suite.tempDir, "whatsapp", "received", unique)
	err := os.MkdirAll(mediaDir, 0755)
	require.NoError(suite.T(), err)

	testContent := []byte("file info test")
	testFile := filepath.Join(mediaDir, filename)
	err = os.WriteFile(testFile, testContent, 0644)
	require.NoError(suite.T(), err)

	info, err := suite.fileService.GetFileInfo(unique, filename)

	assert.NoError(suite.T(), err)
	assert.NotNil(suite.T(), info)
	assert.Equal(suite.T(), filename, info.Name())
	assert.Equal(suite.T(), int64(len(testContent)), info.Size())
	assert.False(suite.T(), info.IsDir())
}

func (suite *FileServiceTestSuite) TestGetFileInfo_NotFound() {
	unique := "user1"
	filename := "nonexistent.txt"

	info, err := suite.fileService.GetFileInfo(unique, filename)

	assert.Error(suite.T(), err)
	assert.Nil(suite.T(), info)
}

func (suite *FileServiceTestSuite) TestEnsureDirectory_Success() {
	unique := "new_user"

	err := suite.fileService.EnsureDirectory(unique)

	assert.NoError(suite.T(), err)

	// Check that directory was created
	expectedDir := filepath.Join(suite.tempDir, "whatsapp", "received", unique)
	assert.DirExists(suite.T(), expectedDir)
}

func (suite *FileServiceTestSuite) TestEnsureDirectory_AlreadyExists() {
	unique := "existing_user"

	// Create directory first
	existingDir := filepath.Join(suite.tempDir, "whatsapp", "received", unique)
	err := os.MkdirAll(existingDir, 0755)
	require.NoError(suite.T(), err)

	err = suite.fileService.EnsureDirectory(unique)

	assert.NoError(suite.T(), err)
	assert.DirExists(suite.T(), existingDir)
}

func (suite *FileServiceTestSuite) TestConcurrentFileOperations() {
	unique := "concurrent_user"

	// Ensure directory exists
	err := suite.fileService.EnsureDirectory(unique)
	require.NoError(suite.T(), err)

	// Create test files
	mediaDir := filepath.Join(suite.tempDir, "whatsapp", "received", unique)
	for i := 0; i < 5; i++ {
		filename := fmt.Sprintf("concurrent_file_%d.txt", i)
		content := []byte(fmt.Sprintf("Content %d", i))
		testFile := filepath.Join(mediaDir, filename)
		err := os.WriteFile(testFile, content, 0644)
		require.NoError(suite.T(), err)
	}

	done := make(chan bool, 10)

	// Concurrent downloads
	for i := 0; i < 5; i++ {
		go func(index int) {
			defer func() { done <- true }()

			filename := fmt.Sprintf("concurrent_file_%d.txt", index)
			reader, size, err := suite.fileService.DownloadMedia("site", unique, filename)
			assert.NoError(suite.T(), err)
			assert.NotNil(suite.T(), reader)
			assert.Greater(suite.T(), size, int64(0))

			if closer, ok := reader.(io.Closer); ok {
				_ = closer.Close()
			}
		}(i)
	}

	// Concurrent file info requests
	for i := 0; i < 5; i++ {
		go func(index int) {
			defer func() { done <- true }()

			filename := fmt.Sprintf("concurrent_file_%d.txt", index)
			info, err := suite.fileService.GetFileInfo(unique, filename)
			assert.NoError(suite.T(), err)
			assert.NotNil(suite.T(), info)
		}(i)
	}

	// Wait for all operations
	for i := 0; i < 10; i++ {
		<-done
	}
}

func (suite *FileServiceTestSuite) TestCleanupOldFiles_PermissionError() {
	// Create directory structure
	receivedDir := filepath.Join(suite.tempDir, "whatsapp", "received")
	err := os.MkdirAll(receivedDir, 0755)
	require.NoError(suite.T(), err)

	// Create a file with restricted deletion permissions
	restrictedFile := filepath.Join(receivedDir, "restricted.txt")
	err = os.WriteFile(restrictedFile, []byte("content"), 0644)
	require.NoError(suite.T(), err)

	// Set old modification time
	oldTime := time.Now().AddDate(0, 0, -10)
	err = os.Chtimes(restrictedFile, oldTime, oldTime)
	require.NoError(suite.T(), err)

	// Remove write permissions from directory to prevent file deletion
	err = os.Chmod(receivedDir, 0555)
	require.NoError(suite.T(), err)

	err = suite.fileService.CleanupOldFiles(7)

	// Should not fail completely, just log errors and continue
	assert.NoError(suite.T(), err)

	// Restore permissions for cleanup
	_ = os.Chmod(receivedDir, 0755)
}

func TestFileServiceTestSuite(t *testing.T) {
	suite.Run(t, new(FileServiceTestSuite))
}

// Additional unit tests for edge cases
func TestFileService_EdgeCases(t *testing.T) {
	tempDir := t.TempDir()
	logger := helpers.TestLogger()
	service := NewFileService(tempDir, logger)

	t.Run("Download media with special characters in filename", func(t *testing.T) {
		unique := "special_user"
		filename := "file with spaces & special chars.jpg"

		// Create directory and file
		mediaDir := filepath.Join(tempDir, "whatsapp", "received", unique)
		err := os.MkdirAll(mediaDir, 0755)
		require.NoError(t, err)

		testContent := []byte("special content")
		testFile := filepath.Join(mediaDir, filename)
		err = os.WriteFile(testFile, testContent, 0644)
		require.NoError(t, err)

		reader, size, err := service.DownloadMedia("site", unique, filename)
		assert.NoError(t, err)
		assert.Equal(t, int64(len(testContent)), size)

		if closer, ok := reader.(io.Closer); ok {
			_ = closer.Close()
		}
	})

	t.Run("Very long filename", func(t *testing.T) {
		unique := "long_filename_user"
		filename := strings.Repeat("a", 200) + ".txt"

		// Create directory and file
		mediaDir := filepath.Join(tempDir, "whatsapp", "received", unique)
		err := os.MkdirAll(mediaDir, 0755)
		require.NoError(t, err)

		testContent := []byte("long filename content")
		testFile := filepath.Join(mediaDir, filename)
		err = os.WriteFile(testFile, testContent, 0644)
		require.NoError(t, err)

		reader, size, err := service.DownloadMedia("site", unique, filename)
		assert.NoError(t, err)
		assert.Equal(t, int64(len(testContent)), size)

		if closer, ok := reader.(io.Closer); ok {
			_ = closer.Close()
		}
	})

	t.Run("Binary file handling", func(t *testing.T) {
		unique := "binary_user"
		filename := "image.png"

		// Create directory
		mediaDir := filepath.Join(tempDir, "whatsapp", "received", unique)
		err := os.MkdirAll(mediaDir, 0755)
		require.NoError(t, err)

		// PNG header bytes
		binaryContent := []byte{0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A}
		testFile := filepath.Join(mediaDir, filename)
		err = os.WriteFile(testFile, binaryContent, 0644)
		require.NoError(t, err)

		reader, size, err := service.DownloadMedia("site", unique, filename)
		assert.NoError(t, err)
		assert.Equal(t, int64(len(binaryContent)), size)

		content, err := io.ReadAll(reader)
		assert.NoError(t, err)
		assert.Equal(t, binaryContent, content)

		if closer, ok := reader.(io.Closer); ok {
			_ = closer.Close()
		}
	})

	t.Run("Directory with no files for cleanup", func(t *testing.T) {
		// Create empty directory structure
		receivedDir := filepath.Join(tempDir, "whatsapp", "received", "empty")
		err := os.MkdirAll(receivedDir, 0755)
		require.NoError(t, err)

		err = service.CleanupOldFiles(30)
		assert.NoError(t, err)
	})
}
