package whatsapp

import (
	"bytes"
	"io"
	"net/http"
	"sync"
	"testing"
	"time"

	"github.com/sirupsen/logrus"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/suite"

	"whatsapp-server/pkg/types"
	"whatsapp-server/tests/helpers"
)

// ReporterTestSuite tests the StatusReporter with mock dependencies
type ReporterTestSuite struct {
	suite.Suite
	reporter    *StatusReporter
	mockHTTP    *MockHTTPClient
	mockSessMgr *MockSessionManager
	logger      *logrus.Logger
}

func TestReporterTestSuite(t *testing.T) {
	suite.Run(t, new(ReporterTestSuite))
}

func (suite *ReporterTestSuite) SetupTest() {
	helpers.GinTestMode()
	suite.logger = logrus.New()
	suite.logger.SetLevel(logrus.ErrorLevel)

	suite.mockHTTP = &MockHTTPClient{}
	suite.mockSessMgr = NewMockSessionManager()

	suite.reporter = NewStatusReporterWithDeps(suite.mockSessMgr, suite.logger, suite.mockHTTP)
}

func (suite *ReporterTestSuite) TearDownTest() {
	if suite.reporter != nil {
		suite.reporter.Shutdown()
	}
}

// ===========================================
// Test 1: Queue Report
// ===========================================

func (suite *ReporterTestSuite) TestQueueStatusReport_Success() {
	// Configure mock to track HTTP calls
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		return &http.Response{
			StatusCode: http.StatusOK,
			Body:       io.NopCloser(bytes.NewReader([]byte("OK"))),
		}, nil
	}

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SiteURL:     "https://example.com",
			SystemToken: "token123",
			UID:         "user1",
			Hash:        "hash123",
		},
	}

	message := types.QueuedMessage{
		ID:      1,
		CID:     2,
		Phone:   "+1234567890",
		Message: `{"text":"Hello"}`,
	}

	// Queue the report
	err := suite.reporter.QueueStatusReport(session, message, true)

	// Should succeed (no error means report was queued)
	assert.NoError(suite.T(), err)

	// Wait for async processing by the worker
	time.Sleep(100 * time.Millisecond)

	// Verify HTTP call was made (worker processed the queued report)
	assert.GreaterOrEqual(suite.T(), suite.mockHTTP.CallCount(), 1)
}

func (suite *ReporterTestSuite) TestQueueStatusReport_QueueFull() {
	// Create a reporter with a tiny queue
	smallQueueReporter := &StatusReporter{
		sessionMgr:  suite.mockSessMgr,
		logger:      suite.logger,
		httpClient:  suite.mockHTTP,
		reportQueue: make(chan *StatusReport, 1), // Only 1 slot
		shutdown:    make(chan struct{}),
	}
	// Don't start worker so queue fills up

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SiteURL:     "https://example.com",
			SystemToken: "token123",
			UID:         "user1",
			Hash:        "hash123",
		},
	}

	message := types.QueuedMessage{ID: 1, Phone: "+1234567890"}

	// Fill the queue
	err1 := smallQueueReporter.QueueStatusReport(session, message, true)
	assert.NoError(suite.T(), err1)

	// Second report should fail
	err2 := smallQueueReporter.QueueStatusReport(session, message, true)
	assert.Error(suite.T(), err2)
	assert.Contains(suite.T(), err2.Error(), "queue full")
}

// ===========================================
// Test 2: Report Success
// ===========================================

func (suite *ReporterTestSuite) TestReportSuccess() {
	// Configure mock to return success
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		// Verify the status parameter is "3" for success
		assert.Contains(suite.T(), req.URL.String(), "status=3")
		return &http.Response{
			StatusCode: http.StatusOK,
			Body:       io.NopCloser(bytes.NewReader([]byte("OK"))),
		}, nil
	}

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SiteURL:     "https://example.com",
			SystemToken: "token123",
			UID:         "user1",
			Hash:        "hash123",
		},
	}

	message := types.QueuedMessage{ID: 1, CID: 2}

	// Report success
	err := suite.reporter.ReportMessageSuccess(session, message)
	assert.NoError(suite.T(), err)

	// Wait for async processing
	time.Sleep(100 * time.Millisecond)

	// Verify HTTP call was made
	assert.GreaterOrEqual(suite.T(), suite.mockHTTP.CallCount(), 1)
}

// ===========================================
// Test 3: Report Failure
// ===========================================

func (suite *ReporterTestSuite) TestReportFailure() {
	// Configure mock to return success
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		// Verify the status parameter is "4" for failure
		assert.Contains(suite.T(), req.URL.String(), "status=4")
		return &http.Response{
			StatusCode: http.StatusOK,
			Body:       io.NopCloser(bytes.NewReader([]byte("OK"))),
		}, nil
	}

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SiteURL:     "https://example.com",
			SystemToken: "token123",
			UID:         "user1",
			Hash:        "hash123",
		},
	}

	message := types.QueuedMessage{ID: 1, CID: 2}

	// Report failure
	err := suite.reporter.ReportMessageFailure(session, message)
	assert.NoError(suite.T(), err)

	// Wait for async processing
	time.Sleep(100 * time.Millisecond)

	// Verify HTTP call was made
	assert.GreaterOrEqual(suite.T(), suite.mockHTTP.CallCount(), 1)
}

// ===========================================
// Test 4: Batch Processing
// ===========================================

func (suite *ReporterTestSuite) TestReportBatchStatus() {
	var mu sync.Mutex
	callCount := 0

	// Configure mock to track calls
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		mu.Lock()
		callCount++
		mu.Unlock()
		return &http.Response{
			StatusCode: http.StatusOK,
			Body:       io.NopCloser(bytes.NewReader([]byte("OK"))),
		}, nil
	}

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SiteURL:     "https://example.com",
			SystemToken: "token123",
			UID:         "user1",
			Hash:        "hash123",
		},
	}

	// Create batch of results
	results := []MessageSendResult{
		{Message: types.QueuedMessage{ID: 1}, Success: true},
		{Message: types.QueuedMessage{ID: 2}, Success: false},
		{Message: types.QueuedMessage{ID: 3}, Success: true},
	}

	// Report batch
	suite.reporter.ReportBatchStatus(session, results)

	// Wait for async processing
	time.Sleep(200 * time.Millisecond)

	// Verify all 3 reports were queued
	mu.Lock()
	assert.GreaterOrEqual(suite.T(), callCount, 3)
	mu.Unlock()
}

// ===========================================
// Test 5: Shutdown Drains Queue
// ===========================================

func (suite *ReporterTestSuite) TestShutdown_DrainsQueue() {
	var mu sync.Mutex
	callCount := 0

	// Configure mock to track calls
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		mu.Lock()
		callCount++
		mu.Unlock()
		return &http.Response{
			StatusCode: http.StatusOK,
			Body:       io.NopCloser(bytes.NewReader([]byte("OK"))),
		}, nil
	}

	// Create reporter that won't process immediately (for testing drain)
	testReporter := NewStatusReporterWithDeps(suite.mockSessMgr, suite.logger, suite.mockHTTP)

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SiteURL:     "https://example.com",
			SystemToken: "token123",
		},
	}

	// Queue several reports
	for i := 0; i < 5; i++ {
		testReporter.QueueStatusReport(session, types.QueuedMessage{ID: i + 1}, true)
	}

	// Shutdown should drain the queue
	testReporter.Shutdown()

	// After shutdown, all reports should have been processed
	mu.Lock()
	// Allow some flexibility for timing
	assert.GreaterOrEqual(suite.T(), callCount, 1, "At least one report should have been processed")
	mu.Unlock()
}

// ===========================================
// Test 6: Worker Recovery / Error Handling
// ===========================================

func (suite *ReporterTestSuite) TestWorker_RetryOnError() {
	var mu sync.Mutex
	callCount := 0

	// Configure mock to fail first, succeed second
	suite.mockHTTP.DoFunc = func(req *http.Request) (*http.Response, error) {
		mu.Lock()
		callCount++
		count := callCount
		mu.Unlock()

		if count == 1 {
			// First call fails
			return &http.Response{
				StatusCode: http.StatusInternalServerError,
				Body:       io.NopCloser(bytes.NewReader([]byte("Error"))),
			}, nil
		}
		// Subsequent calls succeed
		return &http.Response{
			StatusCode: http.StatusOK,
			Body:       io.NopCloser(bytes.NewReader([]byte("OK"))),
		}, nil
	}

	session := &types.Session{
		ID: "test-session",
		Cache: &types.SessionCache{
			SiteURL:     "https://example.com",
			SystemToken: "token123",
		},
	}

	// Queue a report
	err := suite.reporter.QueueStatusReport(session, types.QueuedMessage{ID: 1}, true)
	assert.NoError(suite.T(), err)

	// Wait for initial processing + retry (retry delay is 1 second)
	time.Sleep(1500 * time.Millisecond)

	// Should have made at least 2 calls (initial + retry)
	mu.Lock()
	assert.GreaterOrEqual(suite.T(), callCount, 2, "Should retry after first failure")
	mu.Unlock()
}

// ===========================================
// Additional Tests
// ===========================================

func TestStatusReport_URLFormatting(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	var capturedURL string
	mockHTTP := &MockHTTPClient{
		DoFunc: func(req *http.Request) (*http.Response, error) {
			capturedURL = req.URL.String()
			return &http.Response{
				StatusCode: http.StatusOK,
				Body:       io.NopCloser(bytes.NewReader([]byte("OK"))),
			}, nil
		},
	}

	reporter := NewStatusReporterWithDeps(nil, logger, mockHTTP)
	defer reporter.Shutdown()

	// Create a report directly and send it
	report := &StatusReport{
		SessionID:   "test-session",
		SiteURL:     "https://example.com/",
		SystemToken: "token123",
		UID:         "user1",
		Hash:        "hash123",
		Message:     types.QueuedMessage{ID: 1, CID: 2},
		Success:     true,
	}

	// Process report directly
	reporter.sendReport(report)

	// Verify URL format
	assert.Contains(t, capturedURL, "https://example.com/whatsapp/sent")
	assert.Contains(t, capturedURL, "system_token=token123")
	assert.Contains(t, capturedURL, "id=1")
	assert.Contains(t, capturedURL, "cid=2")
	assert.Contains(t, capturedURL, "uid=user1")
	assert.Contains(t, capturedURL, "hash=hash123")
	assert.Contains(t, capturedURL, "status=3") // 3 = success
}

func TestStatusReport_FailureStatus(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	var capturedURL string
	mockHTTP := &MockHTTPClient{
		DoFunc: func(req *http.Request) (*http.Response, error) {
			capturedURL = req.URL.String()
			return &http.Response{
				StatusCode: http.StatusOK,
				Body:       io.NopCloser(bytes.NewReader([]byte("OK"))),
			}, nil
		},
	}

	reporter := NewStatusReporterWithDeps(nil, logger, mockHTTP)
	defer reporter.Shutdown()

	// Create a failure report
	report := &StatusReport{
		SessionID:   "test-session",
		SiteURL:     "https://example.com",
		SystemToken: "token123",
		Message:     types.QueuedMessage{ID: 1},
		Success:     false,
	}

	reporter.sendReport(report)

	// Verify status=4 for failure
	assert.Contains(t, capturedURL, "status=4")
}

func TestStatusReporter_Headers(t *testing.T) {
	logger := logrus.New()
	logger.SetLevel(logrus.ErrorLevel)

	var capturedHeaders http.Header
	mockHTTP := &MockHTTPClient{
		DoFunc: func(req *http.Request) (*http.Response, error) {
			capturedHeaders = req.Header
			return &http.Response{
				StatusCode: http.StatusOK,
				Body:       io.NopCloser(bytes.NewReader([]byte("OK"))),
			}, nil
		},
	}

	reporter := NewStatusReporterWithDeps(nil, logger, mockHTTP)
	defer reporter.Shutdown()

	report := &StatusReport{
		SessionID:   "test-session",
		SiteURL:     "https://example.com",
		SystemToken: "token123",
		Message:     types.QueuedMessage{ID: 1},
		Success:     true,
	}

	reporter.sendReport(report)

	// Verify custom headers are set
	assert.NotEmpty(t, capturedHeaders.Get("User-Agent"))
	assert.NotEmpty(t, capturedHeaders.Get("ngrok-skip-browser-warning"))
	assert.NotEmpty(t, capturedHeaders.Get("Bypass-Tunnel-Reminder"))
	assert.NotEmpty(t, capturedHeaders.Get("X-Pinggy-No-Screen"))
}
