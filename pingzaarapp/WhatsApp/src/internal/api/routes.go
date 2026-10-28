package api

import (
	"fmt"
	"time"

	"github.com/gin-contrib/cors"
	"github.com/gin-gonic/gin"
	"github.com/sirupsen/logrus"

	"whatsapp-server/internal/api/handlers"
	"whatsapp-server/internal/api/helpers"
	"whatsapp-server/internal/api/middleware"
	"whatsapp-server/internal/config"
	"whatsapp-server/internal/whatsapp"
)

// SetupRouter creates and configures the Gin router
func SetupRouter(cfg *config.Config, sessionMgr *whatsapp.SessionManager, logger *logrus.Logger, version string) *gin.Engine {
	if !cfg.Server.Debug {
		gin.SetMode(gin.ReleaseMode)
	}

	r := gin.New()

	// Middleware
	r.Use(gin.LoggerWithFormatter(func(param gin.LogFormatterParams) string {
		return fmt.Sprintf("%s - [%s] \"%s %s %s %d %s \"%s\" %s\"\n",
			param.ClientIP,
			param.TimeStamp.Format(time.RFC1123),
			param.Method,
			param.Path,
			param.Request.Proto,
			param.StatusCode,
			param.Latency,
			param.Request.UserAgent(),
			param.ErrorMessage,
		)
	}))
	r.Use(gin.Recovery())

	// Per-request timeout. Long-lived flows are explicitly skipped:
	//  - /accounts/create: user may take up to ~3 min to scan the QR code.
	//  - /files/download : binary media transfers can legitimately exceed 60s.
	r.Use(middleware.Timeout(60*time.Second,
		"/accounts/create",
		"/files/download",
	))

	// CORS
	r.Use(cors.New(cors.Config{
		AllowAllOrigins:  true,
		AllowMethods:     []string{"GET", "POST", "PUT", "DELETE", "OPTIONS"},
		AllowHeaders:     []string{"*"},
		ExposeHeaders:    []string{"Content-Length"},
		AllowCredentials: true,
		MaxAge:           12 * time.Hour,
	}))

	// Create handlers
	accountHandler := handlers.NewAccountHandler(cfg, sessionMgr, logger)
	chatHandler := handlers.NewChatHandler(cfg, sessionMgr, logger)
	contactHandler := handlers.NewContactHandler(cfg, sessionMgr, logger)
	fileHandler := handlers.NewFileHandler(cfg, sessionMgr, logger)

	// Account routes
	accounts := r.Group("/accounts")
	{
		accounts.GET("/total/:site_unique/:secret", accountHandler.GetTotalSessions)
		accounts.GET("/status/:site_unique/:unique/:secret", accountHandler.GetSessionStatus)
		accounts.GET("/delete/:site_unique/:unique/:secret", accountHandler.DeleteSession)
		accounts.POST("/create/:secret", accountHandler.CreateSession)
		accounts.POST("/update/:site_unique/:unique/:secret", accountHandler.UpdateSession)
	}

	// Chat routes
	chats := r.Group("/chats")
	{
		chats.GET("/send/:site_unique/:unique/:secret", chatHandler.GetChatQueue)
		chats.POST("/send/:site_unique/:unique/:secret", chatHandler.SendMessage)
		chats.POST("/delete/:site_unique/:unique/:hash/:cid/:id/:secret", chatHandler.DeleteChat)
		chats.GET("/check/:site_unique/:unique/:message_id/:cid/:secret", chatHandler.CheckMessageInQueue)

		// Campaign routes
		campaigns := chats.Group("/campaign")
		{
			campaigns.GET("/start/:site_unique/:unique/:hash/:cid/:secret", chatHandler.StartCampaign)
			campaigns.GET("/stop/:site_unique/:unique/:hash/:cid/:secret", chatHandler.StopCampaign)
			campaigns.GET("/remove/:site_unique/:unique/:hash/:cid/:secret", chatHandler.DeleteCampaign)
		}
	}

	// Contact routes
	contacts := r.Group("/contacts")
	{
		contacts.GET("/groups/:site_unique/:unique/:secret", contactHandler.GetGroups)
		contacts.GET("/validate/:site_unique/:unique/:address/:secret", contactHandler.ValidateNumber)
	}

	// File routes
	files := r.Group("/files")
	{
		files.GET("/download/:site_unique/:unique/:secret/:file", fileHandler.Download)
		files.GET("/garbage/:days", fileHandler.GarbageCollect)
	}

	// Default route
	r.GET("/", func(c *gin.Context) {
		helpers.Response(c, 200, false, gin.H{
			"version": version,
		})
	})

	// Not found handler
	r.NoRoute(func(c *gin.Context) {
		helpers.Response(c, 404, "Route not found", false)
	})

	return r
}
