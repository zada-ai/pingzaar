package middleware

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/stretchr/testify/assert"
)

func init() {
	gin.SetMode(gin.TestMode)
}

// newRouterWithTimeout builds a gin engine with Timeout middleware and a set
// of routes whose handlers cooperate (or not) with context cancellation.
func newRouterWithTimeout(d time.Duration, skipPrefixes ...string) *gin.Engine {
	r := gin.New()
	r.Use(Timeout(d, skipPrefixes...))

	r.GET("/fast", func(c *gin.Context) {
		c.Status(http.StatusOK)
	})

	// Blocks until request context cancellation or 500 ms max.
	r.GET("/slow", func(c *gin.Context) {
		select {
		case <-c.Request.Context().Done():
			return
		case <-time.After(500 * time.Millisecond):
			c.Status(http.StatusOK)
		}
	})

	// Writes 200 then waits past the timeout - verifies Written() guard.
	r.GET("/slow-prewritten", func(c *gin.Context) {
		c.Status(http.StatusOK)
		c.Writer.WriteHeaderNow()
		<-c.Request.Context().Done()
	})

	r.GET("/accounts/create/:secret", func(c *gin.Context) {
		select {
		case <-c.Request.Context().Done():
			return
		case <-time.After(300 * time.Millisecond):
			c.Status(http.StatusOK)
		}
	})

	r.GET("/files/download/:site/:unique/:secret/:file", func(c *gin.Context) {
		select {
		case <-c.Request.Context().Done():
			return
		case <-time.After(300 * time.Millisecond):
			c.Status(http.StatusOK)
		}
	})

	return r
}

func TestTimeoutMiddleware_AbortsSlowHandler_Returns504(t *testing.T) {
	r := newRouterWithTimeout(50 * time.Millisecond)

	w := httptest.NewRecorder()
	req := httptest.NewRequest("GET", "/slow", nil)
	r.ServeHTTP(w, req)

	assert.Equal(t, http.StatusGatewayTimeout, w.Code)
	assert.True(t, strings.Contains(w.Body.String(), "timed out"),
		"body should surface the timeout reason, got: %s", w.Body.String())
}

func TestTimeoutMiddleware_PassesThroughFastHandler(t *testing.T) {
	r := newRouterWithTimeout(100 * time.Millisecond)

	w := httptest.NewRecorder()
	req := httptest.NewRequest("GET", "/fast", nil)
	r.ServeHTTP(w, req)

	assert.Equal(t, http.StatusOK, w.Code)
}

func TestTimeoutMiddleware_SkipsExcludedPath_CompletesNormally(t *testing.T) {
	r := newRouterWithTimeout(50*time.Millisecond, "/accounts/create")

	w := httptest.NewRecorder()
	req := httptest.NewRequest("GET", "/accounts/create/secret", nil)
	r.ServeHTTP(w, req)

	assert.Equal(t, http.StatusOK, w.Code,
		"excluded path must run to completion even though it exceeds the timeout")
}

func TestTimeoutMiddleware_MultipleSkipPrefixes(t *testing.T) {
	r := newRouterWithTimeout(50*time.Millisecond, "/accounts/create", "/files/download")

	// /accounts/create → skipped, completes 200
	w1 := httptest.NewRecorder()
	r.ServeHTTP(w1, httptest.NewRequest("GET", "/accounts/create/secret", nil))
	assert.Equal(t, http.StatusOK, w1.Code)

	// /files/download → skipped, completes 200
	w2 := httptest.NewRecorder()
	r.ServeHTTP(w2, httptest.NewRequest("GET", "/files/download/s/u/sec/file.png", nil))
	assert.Equal(t, http.StatusOK, w2.Code)

	// /slow is NOT excluded → times out
	w3 := httptest.NewRecorder()
	r.ServeHTTP(w3, httptest.NewRequest("GET", "/slow", nil))
	assert.Equal(t, http.StatusGatewayTimeout, w3.Code)
}

func TestTimeoutMiddleware_DoesNotOverwriteWrittenResponse(t *testing.T) {
	r := newRouterWithTimeout(50 * time.Millisecond)

	w := httptest.NewRecorder()
	req := httptest.NewRequest("GET", "/slow-prewritten", nil)
	r.ServeHTTP(w, req)

	// The handler wrote 200 before the deadline; the post-Next guard must
	// refuse to overwrite that with a 504 JSON body.
	assert.Equal(t, http.StatusOK, w.Code,
		"timeout middleware must not overwrite a response the handler already started streaming")
}

func TestTimeoutMiddleware_PropagatesDeadlineToRequestContext(t *testing.T) {
	r := gin.New()
	r.Use(Timeout(80 * time.Millisecond))

	var sawDeadline bool
	r.GET("/probe", func(c *gin.Context) {
		_, ok := c.Request.Context().Deadline()
		sawDeadline = ok
		c.Status(http.StatusOK)
	})

	w := httptest.NewRecorder()
	r.ServeHTTP(w, httptest.NewRequest("GET", "/probe", nil))

	assert.Equal(t, http.StatusOK, w.Code)
	assert.True(t, sawDeadline, "handler's request context must carry the timeout deadline")
}

// Compile-time assertion that our middleware factory's return value is a
// gin.HandlerFunc.
var _ gin.HandlerFunc = Timeout(1 * time.Second)

// Compile-time assertion to exercise the signature's variadic form.
var _ = func() gin.HandlerFunc { return Timeout(1*time.Second, "/a", "/b") }
