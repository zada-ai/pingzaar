package whatsapp

import (
	"context"
	"errors"

	"github.com/sirupsen/logrus"
)

// ContactService handles WhatsApp contact operations
type ContactService struct {
	sessionMgr *SessionManager
	logger     *logrus.Logger
}

// NewContactService creates a new contact service
func NewContactService(sessionMgr *SessionManager, logger *logrus.Logger) *ContactService {
	return &ContactService{
		sessionMgr: sessionMgr,
		logger:     logger,
	}
}

// GroupInfo represents a WhatsApp group
type GroupInfo struct {
	ID      string `json:"id"`
	Subject string `json:"subject"`
}

// ValidationResult represents phone validation result
type ValidationResult struct {
	JID   string `json:"jid"`
	Phone string `json:"phone"`
}

// GetGroups retrieves all groups for a session
func (s *ContactService) GetGroups(sessionID string) ([]GroupInfo, error) {
	session, exists := s.sessionMgr.GetSession(sessionID)
	if !exists {
		return nil, errors.New("session not found")
	}

	if session.Client == nil {
		return nil, errors.New("client not initialized")
	}

	groups, err := session.Client.GetJoinedGroups(context.Background())
	if err != nil {
		s.logger.WithError(err).Error("Failed to get joined groups")
		return nil, err
	}

	var result []GroupInfo
	for _, g := range groups {
		result = append(result, GroupInfo{
			ID:      g.JID.String(),
			Subject: g.Name,
		})
	}

	return result, nil
}

// ValidateNumber checks if a phone number is registered on WhatsApp
func (s *ContactService) ValidateNumber(sessionID, phone string) (*ValidationResult, error) {
	session, exists := s.sessionMgr.GetSession(sessionID)
	if !exists {
		return nil, errors.New("session not found")
	}

	if session.Client == nil {
		return nil, errors.New("client not initialized")
	}

	// Remove + and any non-numeric characters for validation
	cleanPhone := phone
	if len(phone) > 0 && phone[0] == '+' {
		cleanPhone = phone[1:]
	}

	resp, err := session.Client.IsOnWhatsApp(context.Background(), []string{cleanPhone})
	if err != nil {
		s.logger.WithError(err).Error("Failed to validate phone number")
		return nil, err
	}

	if len(resp) == 0 || !resp[0].IsIn {
		return nil, errors.New("phone number not on WhatsApp")
	}

	return &ValidationResult{
		JID:   resp[0].JID.String(),
		Phone: "+" + resp[0].Query,
	}, nil
}
