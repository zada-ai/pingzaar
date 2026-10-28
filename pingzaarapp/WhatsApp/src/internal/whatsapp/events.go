package whatsapp

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/sirupsen/logrus"
	"go.mau.fi/whatsmeow/appstate"
	"go.mau.fi/whatsmeow/binary/proto"
	waTypes "go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"

	"whatsapp-server/pkg/types"
)

// defaultConnectPresence is the presence we advertise to WhatsApp servers as
// soon as a session (re)connects. Bots and automation accounts benefit from
// staying Unavailable so contacts don't see the green "online" dot - that
// dot implies "expect a real-time reply" which is misleading for automated
// workflows. Explicit user actions (push-name change, app-state sync) still
// flip the session to PresenceAvailable elsewhere in this file.
const defaultConnectPresence = waTypes.PresenceUnavailable

// EventHandler handles WhatsApp events
type EventHandler struct {
	sessionMgr *SessionManager
	logger     *logrus.Logger
}

// NewEventHandler creates a new event handler
func NewEventHandler(sessionMgr *SessionManager, logger *logrus.Logger) *EventHandler {
	return &EventHandler{
		sessionMgr: sessionMgr,
		logger:     logger,
	}
}

// lidResolver is the narrow slice of whatsmeow's LID map store the extractors
// need. *store.Device's LIDs field satisfies it.
type lidResolver interface {
	GetPNForLID(ctx context.Context, lid waTypes.JID) (waTypes.JID, error)
}

// sessionLIDResolver returns the session's LID map store, or nil when any
// link in the chain is missing (extractors treat nil as "no fallback").
func sessionLIDResolver(session *types.Session) lidResolver {
	if session == nil || session.Client == nil || session.Client.Store == nil || session.Client.Store.LIDs == nil {
		return nil
	}
	return session.Client.Store.LIDs
}

// resolveLIDToPN consults the LID map store; ok is false when the store is
// absent, errors, or has no mapping.
func resolveLIDToPN(ctx context.Context, lid waTypes.JID, lids lidResolver) (waTypes.JID, bool) {
	if lids == nil {
		return waTypes.JID{}, false
	}
	pn, err := lids.GetPNForLID(ctx, lid)
	if err != nil || pn.IsEmpty() {
		return waTypes.JID{}, false
	}
	return pn, true
}

// extractPhoneNumber extracts the sender phone number from MessageSource.
// Resolution order for LID senders: SenderAlt, then the LID map store, then
// the LID unchanged (callers must treat Server=="lid" as unresolved).
func extractPhoneNumber(ctx context.Context, source waTypes.MessageSource, lids lidResolver) waTypes.JID {
	if source.Sender.Server == "lid" {
		if !source.SenderAlt.IsEmpty() {
			return source.SenderAlt
		}
		if pn, ok := resolveLIDToPN(ctx, source.Sender, lids); ok {
			return pn
		}
	}
	return source.Sender
}

// extractChatJID extracts the chat identifier from MessageSource. Groups pass
// through untouched. LID DMs check RecipientAlt first, but whatsmeow only
// populates that field on own-device (fromMe) messages - which handleMessage
// filters out before either extractor runs - so for incoming DMs it's the LID
// map store fallback that does the real resolution work.
func extractChatJID(ctx context.Context, source waTypes.MessageSource, lids lidResolver) waTypes.JID {
	if source.IsGroup {
		return source.Chat
	}
	if source.Chat.Server == "lid" {
		if !source.RecipientAlt.IsEmpty() {
			return source.RecipientAlt
		}
		if pn, ok := resolveLIDToPN(ctx, source.Chat, lids); ok {
			return pn
		}
	}
	return source.Chat
}

// isUnresolvedLIDDM reports whether a direct message's sender identity is
// still a LID after all resolution attempts. Such messages must be dropped:
// forwarding LID digits as a "phone number" poisons Zender's contact data.
// Group messages are exempt - their chat identity is the @g.us JID.
func isUnresolvedLIDDM(isGroup bool, senderJID waTypes.JID) bool {
	return !isGroup && senderJID.Server == "lid"
}

// msgSessionID returns the session ID for logging, tolerating nil sessions
// from the legacy Handle path.
func msgSessionID(session *types.Session) string {
	if session == nil {
		return ""
	}
	return session.ID
}

// Handle processes WhatsApp events (legacy - without session context)
func (eh *EventHandler) Handle(evt interface{}) {
	switch v := evt.(type) {
	case *events.Message:
		eh.handleMessage(v, nil)
	case *events.Receipt:
		eh.handleReceipt(v, nil)
	case *events.Presence:
		eh.handlePresence(v, nil)
	case *events.Connected:
		eh.handleConnected(v, nil)
	case *events.Disconnected:
		eh.handleDisconnected(v, nil)
	case *events.LoggedOut:
		eh.handleLoggedOut(v, nil)
	case *events.PairSuccess:
		eh.handlePairSuccess(v, nil)
	case *events.AppStateSyncComplete:
		eh.handleAppStateSyncComplete(v, nil)
	case *events.PushNameSetting:
		eh.handlePushNameSetting(v, nil)
	case *events.StreamReplaced:
		eh.handleStreamReplaced(v, nil)
	case *events.TemporaryBan:
		eh.handleTemporaryBan(v, nil)
	case *events.ClientOutdated:
		eh.handleClientOutdated(v, nil)
	case *events.ConnectFailure:
		eh.handleConnectFailure(v, nil)
	case *events.CATRefreshError:
		eh.handleCATRefreshError(v, nil)
	case *events.HistorySync:
		eh.handleHistorySync(v, nil)
	case *events.OfflineSyncPreview:
		eh.handleOfflineSyncPreview(v, nil)
	case *events.OfflineSyncCompleted:
		eh.handleOfflineSyncCompleted(v, nil)
	default:
		eh.logger.WithField("event_type", fmt.Sprintf("%T", v)).Debug("Unhandled event")
	}
}

// HandleWithSession processes WhatsApp events with session context
func (eh *EventHandler) HandleWithSession(evt interface{}, sessionID string) {
	session, exists := eh.sessionMgr.GetSession(sessionID)
	if !exists {
		eh.logger.WithField("session_id", sessionID).Error("Event received for unknown session")
		return
	}

	// Update last activity (thread-safe)
	session.Mu.Lock()
	session.LastActivity = time.Now()
	session.Mu.Unlock()

	switch v := evt.(type) {
	case *events.Message:
		eh.handleMessage(v, session)
	case *events.Receipt:
		eh.handleReceipt(v, session)
	case *events.Presence:
		eh.handlePresence(v, session)
	case *events.Connected:
		eh.handleConnected(v, session)
	case *events.Disconnected:
		eh.handleDisconnected(v, session)
	case *events.LoggedOut:
		eh.handleLoggedOut(v, session)
	case *events.PairSuccess:
		eh.handlePairSuccess(v, session)
	case *events.AppStateSyncComplete:
		eh.handleAppStateSyncComplete(v, session)
	case *events.PushNameSetting:
		eh.handlePushNameSetting(v, session)
	case *events.StreamReplaced:
		eh.handleStreamReplaced(v, session)
	case *events.TemporaryBan:
		eh.handleTemporaryBan(v, session)
	case *events.ClientOutdated:
		eh.handleClientOutdated(v, session)
	case *events.ConnectFailure:
		eh.handleConnectFailure(v, session)
	case *events.CATRefreshError:
		eh.handleCATRefreshError(v, session)
	case *events.HistorySync:
		eh.handleHistorySync(v, session)
	case *events.OfflineSyncPreview:
		eh.handleOfflineSyncPreview(v, session)
	case *events.OfflineSyncCompleted:
		eh.handleOfflineSyncCompleted(v, session)
	default:
		eh.logger.WithFields(logrus.Fields{
			"event_type": fmt.Sprintf("%T", v),
			"session_id": sessionID,
		}).Debug("Unhandled event")
	}
}

func (eh *EventHandler) handleMessage(evt *events.Message, session *types.Session) {
	if evt.Info.IsFromMe {
		return // Skip own messages
	}

	// Filter out WhatsApp status broadcasts (status@g.us) - Node.js behavior
	// Extract phone number/chat identifier with LID support
	chatJID := extractChatJID(context.Background(), evt.Info.MessageSource, sessionLIDResolver(session))
	chatIdentifier := chatJID.String()
	if strings.HasPrefix(chatIdentifier, "status@") {
		eh.logger.WithFields(logrus.Fields{
			"chat":       chatIdentifier,
			"session_id": session.ID,
		}).Debug("Skipping WhatsApp status broadcast message")
		return
	}

	// Extract sender phone number with LID support
	senderJID := extractPhoneNumber(context.Background(), evt.Info.MessageSource, sessionLIDResolver(session))

	if isUnresolvedLIDDM(evt.Info.IsGroup, senderJID) {
		eh.logger.WithFields(logrus.Fields{
			"session_id": msgSessionID(session),
			"message_id": evt.Info.ID,
			"sender":     senderJID.String(),
		}).Warn("Dropping DM with unresolvable LID sender - refusing to forward LID as phone number")
		return
	}

	msg := &types.IncomingMessage{
		From:        senderJID.String(),
		Chat:        chatJID.String(),
		MessageID:   evt.Info.ID,
		Timestamp:   evt.Info.Timestamp,
		IsGroup:     evt.Info.IsGroup,
		MessageType: eh.getMessageType(evt.Message),
		Content:     eh.extractMessageContent(evt.Message),
	}

	if session != nil {
		msg.SessionID = session.ID
	}

	eh.logger.WithFields(logrus.Fields{
		"from":       msg.From,
		"chat":       msg.Chat,
		"type":       msg.MessageType,
		"content":    msg.Content,
		"session_id": msg.SessionID,
	}).Info("Message received")

	// Implement auto-read functionality (Node.js behavior)
	// Only auto-read when receive_chats is enabled (not 2) - same as webhook logic
	if session != nil && eh.sessionMgr.config.Server.AutoRead && session.Cache.ReceiveChats != 2 {
		SafeGo(eh.logger, "auto_read", func() {
			// Use extracted JIDs with LID support for MarkRead
			err := session.Client.MarkRead(context.Background(), []waTypes.MessageID{evt.Info.ID},
				time.Now(), chatJID, senderJID)
			if err != nil {
				eh.logger.WithError(err).WithField("session_id", session.ID).
					Error("Failed to mark message as read")
			} else {
				eh.logger.WithField("session_id", session.ID).
					Debug("Message marked as read")
			}
		})
	}

	// Send webhook to Zender (FIXED: was TODO)
	// Check receive_chats setting: 1=enabled, 2=disabled
	if session != nil && session.Cache.ReceiveChats != 2 {
		SafeGo(eh.logger, "webhook_send", func() {
			eh.sendWebhookToZender(evt, session)
		})
	} else if session != nil && session.Cache.ReceiveChats == 2 {
		eh.logger.WithField("session_id", session.ID).
			Debug("Webhook skipped - receive_chats disabled")
	}
}

func (eh *EventHandler) getMessageType(msg *proto.Message) types.MessageType {
	switch {
	case msg.GetConversation() != "":
		return types.MessageTypeText
	case msg.GetExtendedTextMessage() != nil:
		return types.MessageTypeText
	case msg.GetImageMessage() != nil:
		return types.MessageTypeImage
	case msg.GetVideoMessage() != nil:
		return types.MessageTypeVideo
	case msg.GetAudioMessage() != nil:
		return types.MessageTypeAudio
	case msg.GetDocumentMessage() != nil:
		return types.MessageTypeDocument
	case msg.GetLocationMessage() != nil:
		return types.MessageTypeLocation
	default:
		return types.MessageTypeUnknown
	}
}

func (eh *EventHandler) extractMessageContent(msg *proto.Message) string {
	switch {
	case msg.GetConversation() != "":
		return msg.GetConversation()
	case msg.GetExtendedTextMessage() != nil:
		return msg.GetExtendedTextMessage().GetText()
	case msg.GetImageMessage() != nil:
		return msg.GetImageMessage().GetCaption()
	case msg.GetVideoMessage() != nil:
		return msg.GetVideoMessage().GetCaption()
	case msg.GetDocumentMessage() != nil:
		return msg.GetDocumentMessage().GetCaption()
	case msg.GetLocationMessage() != nil:
		lat := msg.GetLocationMessage().GetDegreesLatitude()
		lng := msg.GetLocationMessage().GetDegreesLongitude()
		return fmt.Sprintf("Location: %f, %f", lat, lng)
	default:
		return ""
	}
}

func (eh *EventHandler) handleConnected(evt *events.Connected, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID

		// Get WhatsApp ID from session client
		whatsappID := ""
		if session.Client != nil && session.Client.Store != nil && session.Client.Store.ID != nil {
			whatsappID = session.Client.Store.ID.String()
		}

		// Track first connection for logging purposes
		// ProcessConnectionEvent handles deduplication based on status
		if !session.FirstConnectionNotified {
			eh.logger.WithFields(logrus.Fields{
				"session_id":  sessionID,
				"whatsapp_id": whatsappID,
			}).Info("First connection established")
			session.FirstConnectionNotified = true
		} else {
			eh.logger.WithFields(logrus.Fields{
				"session_id":  sessionID,
				"whatsapp_id": whatsappID,
			}).Info("Reconnected to WhatsApp")
		}

		// Notify server on ALL connections (first connection AND reconnections)
		if eh.sessionMgr.linkNotifier != nil {
			eh.sessionMgr.linkNotifier.ProcessConnectionEvent(session, "connected", whatsappID)
		}

		// Send presence to WhatsApp servers. Default is Unavailable so bots
		// don't appear online - see defaultConnectPresence rationale above.
		if session.Client != nil && len(session.Client.Store.PushName) > 0 {
			SafeGo(eh.logger, "send_presence", func() {
				if err := session.Client.SendPresence(context.Background(), defaultConnectPresence); err != nil {
					eh.logger.WithError(err).WithField("session_id", sessionID).
						Warn("Failed to send connect-time presence")
				} else {
					eh.logger.WithFields(logrus.Fields{
						"session_id": sessionID,
						"presence":   string(defaultConnectPresence),
					}).Info("Sent connect-time presence to WhatsApp")
				}
			})
		}
	}
	eh.logger.WithField("session_id", sessionID).Info("WhatsApp connected")
}

func (eh *EventHandler) handleDisconnected(evt *events.Disconnected, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID

		// Update session status to reflect disconnection (thread-safe)
		session.Mu.Lock()
		session.Status = types.StatusDisconnected
		session.Mu.Unlock()

		// DO NOT notify server about temporary disconnections (Node.js strategy)
		// Let whatsmeow's AutoReconnect handle reconnection silently
		// Only genuine logouts (handleLoggedOut) will notify the PHP server
		eh.logger.WithField("session_id", sessionID).
			Info("WhatsApp disconnected, auto-reconnect will handle reconnection")
	}
	eh.logger.WithField("session_id", sessionID).Info("WhatsApp disconnected")
}

func (eh *EventHandler) handleLoggedOut(evt *events.LoggedOut, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID

		// Notify server about logout (LinkNotifier will update status)
		eh.sessionMgr.NotifyAccountLoggedOut(sessionID)

		// Automatically clean up session and all related files when account is unlinked
		if err := eh.sessionMgr.DeleteSession(context.Background(), sessionID); err != nil {
			eh.logger.WithError(err).WithField("session_id", sessionID).
				Error("Failed to cleanup session after logout")
		} else {
			eh.logger.WithField("session_id", sessionID).
				Info("Session automatically cleaned up after WhatsApp logout")
		}
	}
	eh.logger.WithFields(logrus.Fields{
		"reason":     evt.Reason,
		"session_id": sessionID,
	}).Info("WhatsApp logged out")
}

func (eh *EventHandler) handlePairSuccess(evt *events.PairSuccess, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID

		// Get WhatsApp ID from session client
		whatsappID := ""
		if session.Client != nil && session.Client.Store != nil && session.Client.Store.ID != nil {
			whatsappID = session.Client.Store.ID.String()
		}

		// Only notify server on FIRST successful pairing (Node.js behavior)
		// Subsequent reconnections don't trigger notifications
		if !session.FirstConnectionNotified {
			eh.logger.WithFields(logrus.Fields{
				"session_id":  sessionID,
				"whatsapp_id": whatsappID,
			}).Info("First pairing - sending link notification")

			if eh.sessionMgr.linkNotifier != nil {
				eh.sessionMgr.linkNotifier.ProcessConnectionEvent(session, "connected", whatsappID)
			}

			// Mark as notified to prevent duplicate notifications
			session.FirstConnectionNotified = true
		} else {
			eh.logger.WithFields(logrus.Fields{
				"session_id":  sessionID,
				"whatsapp_id": whatsappID,
			}).Info("Re-pairing detected - skipping link notification (already notified)")
		}
	}
	eh.logger.WithField("session_id", sessionID).Info("WhatsApp pairing successful")
}

// isLinkedDeviceReceipt returns true when the receipt originates from a
// non-primary linked device of the sender. WhatsApp dispatches one receipt
// per linked device; forwarding duplicates to webhooks wastes work.
func isLinkedDeviceReceipt(evt *events.Receipt) bool {
	return evt.Sender.Device != 0
}

func (eh *EventHandler) handleReceipt(evt *events.Receipt, session *types.Session) {
	if isLinkedDeviceReceipt(evt) {
		return
	}

	sessionID := ""
	if session != nil {
		sessionID = session.ID
	}
	eh.logger.WithFields(logrus.Fields{
		"chat":       evt.Chat.String(),
		"timestamp":  evt.Timestamp,
		"type":       evt.Type,
		"session_id": sessionID,
	}).Debug("Receipt received")
}

func (eh *EventHandler) handlePresence(evt *events.Presence, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID
	}
	eh.logger.WithFields(logrus.Fields{
		"chat":        evt.From.String(),
		"unavailable": evt.Unavailable,
		"last_seen":   evt.LastSeen,
		"session_id":  sessionID,
	}).Debug("Presence update")
}

func (eh *EventHandler) handleAppStateSyncComplete(evt *events.AppStateSyncComplete, session *types.Session) {
	if session == nil || session.Client == nil {
		return
	}
	// Only send presence after critical block sync (contains push name and locale)
	if evt.Name != appstate.WAPatchCriticalBlock {
		return
	}
	if len(session.Client.Store.PushName) == 0 {
		return
	}
	// Critical-block app-state sync fires on every reconnect after login, so
	// using PresenceAvailable here would immediately override the Unavailable
	// default set in handleConnected. Use the same default to preserve bot
	// offline-appearance semantics across the connect -> sync sequence.
	SafeGo(eh.logger, "appstate_presence", func() {
		if err := session.Client.SendPresence(context.Background(), defaultConnectPresence); err != nil {
			eh.logger.WithError(err).WithField("session_id", session.ID).
				Warn("Failed to send presence after app state sync")
		} else {
			eh.logger.WithFields(logrus.Fields{
				"session_id": session.ID,
				"presence":   string(defaultConnectPresence),
			}).Info("Sent presence after critical block sync")
		}
	})
}

func (eh *EventHandler) handlePushNameSetting(evt *events.PushNameSetting, session *types.Session) {
	if session == nil || session.Client == nil {
		return
	}
	if len(session.Client.Store.PushName) == 0 {
		return
	}
	SafeGo(eh.logger, "pushname_presence", func() {
		if err := session.Client.SendPresence(context.Background(), waTypes.PresenceAvailable); err != nil {
			eh.logger.WithError(err).WithField("session_id", session.ID).
				Warn("Failed to send presence after push name change")
		} else {
			eh.logger.WithField("session_id", session.ID).
				Info("Sent available presence after push name change")
		}
	})
}

func (eh *EventHandler) handleStreamReplaced(evt *events.StreamReplaced, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID

		eh.logger.WithField("session_id", sessionID).
			Warn("Stream replaced - another client connected with same session")

		// Notify PHP server (sets status to LoggedOut via linkNotifier)
		eh.sessionMgr.NotifyAccountLoggedOut(sessionID)

		// Clean up session
		if err := eh.sessionMgr.DeleteSession(context.Background(), sessionID); err != nil {
			eh.logger.WithError(err).WithField("session_id", sessionID).
				Error("Failed to cleanup session after stream replaced")
		} else {
			eh.logger.WithField("session_id", sessionID).
				Info("Session cleaned up after stream replaced")
		}
	}
	eh.logger.WithField("session_id", sessionID).Info("WhatsApp stream replaced")
}

// sendWebhookToZender sends incoming messages to Zender's webhook endpoint
func (eh *EventHandler) sendWebhookToZender(evt *events.Message, session *types.Session) {
	if eh.sessionMgr.webhookService != nil {
		err := eh.sessionMgr.webhookService.SendMessageReceived(evt, session)
		if err != nil {
			eh.logger.WithError(err).WithField("session_id", session.ID).
				Error("Failed to send webhook to Zender")
		}
	} else {
		eh.logger.WithField("session_id", session.ID).
			Warn("Webhook service not initialized")
	}
}

// handleTemporaryBan notifies the PHP server that the account is unusable while
// preserving local session files so the device can recover once the ban expires.
func (eh *EventHandler) handleTemporaryBan(evt *events.TemporaryBan, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID
	}

	eh.logger.WithFields(logrus.Fields{
		"session_id":  sessionID,
		"code":        int(evt.Code),
		"expire":      evt.Expire,
		"description": evt.String(),
	}).Warn("WhatsApp temporary ban received - keeping files for recovery")

	if session != nil {
		eh.sessionMgr.NotifyAccountLoggedOut(sessionID)
	}
}

// handleClientOutdated logs the version rejection. No session action is taken;
// the fix is a dependency bump (run 'make update').
func (eh *EventHandler) handleClientOutdated(evt *events.ClientOutdated, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID
	}

	eh.logger.WithField("session_id", sessionID).
		Error("WhatsApp rejected connection - whatsmeow proto is outdated, update dependency (run 'make update')")
}

// handleConnectFailure records the failure and marks the session disconnected.
// whatsmeow's auto-reconnect loop will continue attempting to reconnect.
func (eh *EventHandler) handleConnectFailure(evt *events.ConnectFailure, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID
	}

	eh.logger.WithFields(logrus.Fields{
		"session_id":  sessionID,
		"reason_code": evt.Reason.NumberString(),
		"reason":      evt.Reason.String(),
		"message":     evt.Message,
	}).Error("WhatsApp connection failed")

	if session != nil {
		session.Mu.Lock()
		session.Status = types.StatusDisconnected
		session.Mu.Unlock()
	}
}

// handleCATRefreshError logs a CAT (crypto auth token) refresh failure. No
// session action - whatsmeow will retry internally.
func (eh *EventHandler) handleCATRefreshError(evt *events.CATRefreshError, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID
	}

	eh.logger.WithFields(logrus.Fields{
		"session_id": sessionID,
		"error":      evt.Error,
	}).Error("WhatsApp CAT refresh failed")
}

// handleHistorySync drains history sync events. Explicit handling prevents
// debug-level "unhandled event" spam and matches upstream mdtest convention.
func (eh *EventHandler) handleHistorySync(evt *events.HistorySync, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID
	}

	fields := logrus.Fields{"session_id": sessionID}
	if evt.Data != nil && evt.Data.SyncType != nil {
		fields["sync_type"] = evt.Data.GetSyncType().String()
	}
	eh.logger.WithFields(fields).Debug("History sync drained")
}

// handleOfflineSyncPreview logs the counts of missed events the server is
// about to replay after reconnect.
func (eh *EventHandler) handleOfflineSyncPreview(evt *events.OfflineSyncPreview, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID
	}

	eh.logger.WithFields(logrus.Fields{
		"session_id":       sessionID,
		"total":            evt.Total,
		"app_data_changes": evt.AppDataChanges,
		"messages":         evt.Messages,
		"notifications":    evt.Notifications,
		"receipts":         evt.Receipts,
	}).Info("Offline sync preview received")
}

// handleOfflineSyncCompleted logs the total events the server replayed after
// reconnect.
func (eh *EventHandler) handleOfflineSyncCompleted(evt *events.OfflineSyncCompleted, session *types.Session) {
	sessionID := ""
	if session != nil {
		sessionID = session.ID
	}

	eh.logger.WithFields(logrus.Fields{
		"session_id": sessionID,
		"count":      evt.Count,
	}).Info("Offline sync completed")
}
