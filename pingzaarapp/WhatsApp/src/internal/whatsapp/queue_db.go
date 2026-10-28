package whatsapp

import (
	"database/sql"
	"fmt"
	"time"

	"github.com/sirupsen/logrus"
	_ "modernc.org/sqlite"

	"whatsapp-server/pkg/types"
)

// openQueueDB opens and configures a persistent database connection for message queue operations
// The connection is stored on the session and reused for all queue operations (connection pooling)
func openQueueDB(dbPath string, logger *logrus.Logger) (*sql.DB, error) {
	db, err := sql.Open("sqlite", dbPath)
	if err != nil {
		return nil, fmt.Errorf("failed to open database: %w", err)
	}

	// Configure connection pool for SQLite
	// SQLite supports only 1 writer at a time, but we can have multiple readers
	db.SetMaxOpenConns(1)    // SQLite allows 1 writer
	db.SetMaxIdleConns(1)    // Keep connection alive
	db.SetConnMaxLifetime(0) // Never expire (persistent connection)
	db.SetConnMaxIdleTime(0) // Never close idle connection

	// Test the connection
	if err := db.Ping(); err != nil {
		db.Close()
		return nil, fmt.Errorf("failed to ping database: %w", err)
	}

	logger.WithField("db_path", dbPath).Debug("Queue database connection opened (persistent)")
	return db, nil
}

// closeQueueDB safely closes the queue database connection
func closeQueueDB(session *types.Session, logger *logrus.Logger) {
	if session.QueueDB != nil {
		if err := session.QueueDB.Close(); err != nil {
			logger.WithError(err).WithField("session_id", session.ID).Warn("Error closing queue database")
		} else {
			logger.WithField("session_id", session.ID).Debug("Queue database connection closed")
		}
		session.QueueDB = nil
	}
}

// initMessageQueueTable creates the message_queue table if it doesn't exist
// Uses persistent connection from session.QueueDB if available
func initMessageQueueTable(dbPath string, logger *logrus.Logger) error {
	db, err := sql.Open("sqlite", dbPath)
	if err != nil {
		return fmt.Errorf("failed to open database: %w", err)
	}
	defer db.Close()

	// Create table with index for priority ordering
	createTableSQL := `
		CREATE TABLE IF NOT EXISTS message_queue (
			id INTEGER NOT NULL,
			cid INTEGER DEFAULT 0,
			cstatus INTEGER DEFAULT 1,
			phone TEXT NOT NULL,
			message TEXT NOT NULL,
			priority INTEGER DEFAULT 0,
			wid TEXT NOT NULL,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id, cid)
		);
		CREATE INDEX IF NOT EXISTS idx_message_queue_priority ON message_queue(priority DESC, created_at ASC);
	`

	_, err = db.Exec(createTableSQL)
	if err != nil {
		return fmt.Errorf("failed to create message_queue table: %w", err)
	}

	logger.WithField("db_path", dbPath).Debug("Message queue table initialized")
	return nil
}

// saveMessagesToDB saves messages to the session's SQLite database
// Uses persistent connection from session.QueueDB for better performance
func saveMessagesToDB(session *types.Session, messages []types.QueuedMessage, logger *logrus.Logger) error {
	if len(messages) == 0 {
		return nil
	}

	// Use persistent connection if available, fallback to opening new connection
	db := session.QueueDB
	if db == nil {
		var err error
		db, err = sql.Open("sqlite", session.DBPath)
		if err != nil {
			return fmt.Errorf("failed to open database: %w", err)
		}
		defer db.Close() // Only close if we opened it
	}

	// Use transaction for batch insert
	tx, err := db.Begin()
	if err != nil {
		return fmt.Errorf("failed to begin transaction: %w", err)
	}
	defer tx.Rollback()

	// Prepare insert statement with ON CONFLICT to handle duplicates
	stmt, err := tx.Prepare(`
		INSERT OR IGNORE INTO message_queue (id, cid, cstatus, phone, message, priority, wid, created_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?)
	`)
	if err != nil {
		return fmt.Errorf("failed to prepare statement: %w", err)
	}
	defer stmt.Close()

	inserted := 0
	for _, msg := range messages {
		result, err := stmt.Exec(msg.ID, msg.CID, msg.CStatus, msg.Phone, msg.Message, msg.Priority, msg.WID, time.Now())
		if err != nil {
			logger.WithError(err).WithFields(logrus.Fields{
				"session_id": session.ID,
				"message_id": msg.ID,
				"cid":        msg.CID,
			}).Warn("Failed to insert message to database")
			continue
		}
		rowsAffected, _ := result.RowsAffected()
		if rowsAffected > 0 {
			inserted++
		}
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("failed to commit transaction: %w", err)
	}

	logger.WithFields(logrus.Fields{
		"session_id": session.ID,
		"total":      len(messages),
		"inserted":   inserted,
	}).Debug("Messages saved to database")

	return nil
}

// loadMessagesFromDB loads all queued messages from the session's SQLite database
// Uses persistent connection from session.QueueDB for better performance
func loadMessagesFromDB(session *types.Session, logger *logrus.Logger) ([]types.QueuedMessage, error) {
	// Use persistent connection if available, fallback to opening new connection
	db := session.QueueDB
	shouldClose := false
	if db == nil {
		var err error
		db, err = sql.Open("sqlite", session.DBPath)
		if err != nil {
			return nil, fmt.Errorf("failed to open database: %w", err)
		}
		shouldClose = true
	}
	if shouldClose {
		defer db.Close()
	}

	// Query messages ordered by priority (desc) and creation time (asc)
	rows, err := db.Query(`
		SELECT id, cid, cstatus, phone, message, priority, wid
		FROM message_queue
		ORDER BY priority DESC, created_at ASC
		LIMIT 100
	`)
	if err != nil {
		return nil, fmt.Errorf("failed to query messages: %w", err)
	}
	defer rows.Close()

	var messages []types.QueuedMessage
	for rows.Next() {
		var msg types.QueuedMessage
		err := rows.Scan(&msg.ID, &msg.CID, &msg.CStatus, &msg.Phone, &msg.Message, &msg.Priority, &msg.WID)
		if err != nil {
			logger.WithError(err).WithField("session_id", session.ID).Warn("Failed to scan message row")
			continue
		}
		messages = append(messages, msg)
	}

	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("error iterating rows: %w", err)
	}

	logger.WithFields(logrus.Fields{
		"session_id": session.ID,
		"loaded":     len(messages),
	}).Debug("Messages loaded from database")

	return messages, nil
}

// MessageExistsInDB checks if a message exists in the session's SQLite database
// Uses persistent connection from session.QueueDB for better performance
// Returns true if message exists, false otherwise
func MessageExistsInDB(session *types.Session, messageID int, cid int, logger *logrus.Logger) (bool, error) {
	// Use persistent connection if available, fallback to opening new connection
	db := session.QueueDB
	shouldClose := false
	if db == nil {
		var err error
		db, err = sql.Open("sqlite", session.DBPath)
		if err != nil {
			return false, fmt.Errorf("failed to open database: %w", err)
		}
		shouldClose = true
	}
	if shouldClose {
		defer db.Close()
	}

	var count int
	err := db.QueryRow(`SELECT COUNT(*) FROM message_queue WHERE id = ? AND cid = ?`, messageID, cid).Scan(&count)
	if err != nil {
		return false, fmt.Errorf("failed to query message: %w", err)
	}

	logger.WithFields(logrus.Fields{
		"session_id": session.ID,
		"message_id": messageID,
		"cid":        cid,
		"exists":     count > 0,
	}).Debug("Checked message existence in database")

	return count > 0, nil
}

// deleteMessageFromDB removes a message from the session's SQLite database
// Uses persistent connection from session.QueueDB for better performance
func deleteMessageFromDB(session *types.Session, messageID int, cid int, logger *logrus.Logger) error {
	// Use persistent connection if available, fallback to opening new connection
	db := session.QueueDB
	shouldClose := false
	if db == nil {
		var err error
		db, err = sql.Open("sqlite", session.DBPath)
		if err != nil {
			return fmt.Errorf("failed to open database: %w", err)
		}
		shouldClose = true
	}
	if shouldClose {
		defer db.Close()
	}

	result, err := db.Exec(`DELETE FROM message_queue WHERE id = ? AND cid = ?`, messageID, cid)
	if err != nil {
		return fmt.Errorf("failed to delete message: %w", err)
	}

	rowsAffected, _ := result.RowsAffected()
	logger.WithFields(logrus.Fields{
		"session_id":    session.ID,
		"message_id":    messageID,
		"cid":           cid,
		"rows_affected": rowsAffected,
	}).Debug("Message deleted from database")

	return nil
}
