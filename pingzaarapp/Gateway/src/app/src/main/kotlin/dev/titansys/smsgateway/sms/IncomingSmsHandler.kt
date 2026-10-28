package dev.titansys.smsgateway.sms

import android.content.Context
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import dev.titansys.smsgateway.databases.ReceivedMessage
import dev.titansys.smsgateway.databases.ZenderDatabase
import dev.titansys.smsgateway.services.ReceivedRequest
import dev.titansys.smsgateway.helpers.ScheduledTaskManager
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.helpers.DeviceInfoProvider

/**
 * Helper class for managing received SMS messages and server synchronization
 * Provides database storage and background sync using existing API infrastructure
 */
class IncomingSmsHandler(private val context: Context) {

    companion object {
        private const val TAG = "IncomingSmsHandler"
        private const val MAX_RETRY_ATTEMPTS = 3
        private const val BATCH_SIZE = 10
    }

    private val database = ZenderDatabase.getInstance(context)
    private val receivedSmsDao = database.receivedSmsDao()
    private val helperScope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    /**
     * Generate a unique message ID that fits in database INT column (max 2,147,483,647)
     * Uses bulletproof algorithm: 6-digit timestamp + 3-digit random = max 9 digits
     */
    private fun generateUniqueMessageId(): Long {
        // Use last 6 digits of Unix timestamp + 3-digit random
        val timestamp = (System.currentTimeMillis() / 1000) % 1000000 // Max 6 digits
        val random = (100..999).random() // 3-digit random number
        return timestamp * 1000 + random // Max 9 digits total (999,999,999)
    }

    /**
     * Store received SMS message in local database
     */
    suspend fun storeReceivedMessage(
        sender: String,
        message: String,
        simSlot: Int,
        timestamp: Long
    ): Long? {
        return try {
            val receivedMessage = ReceivedMessage(
                messageId = generateUniqueMessageId(),
                senderNumber = sender,
                messageContent = message,
                simSlot = simSlot,
                timestamp = timestamp,
                receivedAt = System.currentTimeMillis(),
                isSynced = false
            )

            receivedSmsDao.insertMessage(receivedMessage)
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error storing received SMS in database", "IncomingHandler", e)
            null
        }
    }

    /**
     * Sync unsynced messages with server using existing API
     */
    fun syncUnsyncedMessages() {
        helperScope.launch {
            try {
                val unsyncedMessages = receivedSmsDao.getUnsyncedMessages()
                if (unsyncedMessages.isEmpty()) {
                    GatewayLogger.d("SMS", "No unsynced messages to process", "IncomingHandler")
                    return@launch
                }

                GatewayLogger.d("SMS", "Syncing ${unsyncedMessages.size} unsynced messages with server", "IncomingHandler")

                // Process messages in batches
                val batches = unsyncedMessages.chunked(BATCH_SIZE)
                batches.forEach { batch ->
                    processBatch(batch)
                }

                // Clean up old synced messages (older than 7 days)
                val oneWeekAgo = System.currentTimeMillis() - (7 * 24 * 60 * 60 * 1000L)
                receivedSmsDao.deleteOldMessages(oneWeekAgo)

            } catch (e: Exception) {
                GatewayLogger.e("SMS", "Error during sync process", "IncomingHandler", e)
                GatewayLogger.e("SMS", "Error syncing received messages: ${e.message}", "IncomingHandler")
            }
        }
    }

    /**
     * Process a batch of messages with the server
     */
    private suspend fun processBatch(messages: List<ReceivedMessage>) {
        val sessionManager = SessionManager(context)
        val deviceHelper = DeviceInfoProvider(context)

        val hash = sessionManager.getHash()
        val did = deviceHelper.getAndroidId()

        if (hash == null || did == null) {
            GatewayLogger.e("SMS", "Missing hash or device ID for sync - hash: ${hash != null}, did: ${did != null}", "IncomingHandler")
            GatewayLogger.e("SMS", "Cannot sync received SMS: missing credentials", "IncomingHandler")
            return
        }

        val successfulIds = mutableListOf<Long>()

        messages.forEach { receivedMessage ->
            try {
                // Use Burat-inspired worker scheduling instead of direct API call
                GatewayLogger.d("SMS", "Scheduling received SMS worker", "IncomingHandler")
                ScheduledTaskManager.scheduleReceivedSmsSync(
                    context = context,
                    messageId = receivedMessage.messageId,
                    sender = receivedMessage.senderNumber,
                    message = receivedMessage.messageContent,
                    sim = receivedMessage.simSlot,
                    attachment = "" // Empty string for PHP isset() compatibility
                )

                // Mark as scheduled for sync (worker will handle actual sync)
                successfulIds.add(receivedMessage.id)
                GatewayLogger.d("SMS", "Scheduled SMS worker successfully", "IncomingHandler")

            } catch (e: Exception) {
                GatewayLogger.e("SMS", "Error scheduling SMS worker", "IncomingHandler", e)
                GatewayLogger.e("SMS", "Error scheduling SMS sync: ${e.message}", "IncomingHandler")
            }
        }

        // Mark successfully synced messages
        if (successfulIds.isNotEmpty()) {
            receivedSmsDao.markMultipleAsSynced(successfulIds)
            GatewayLogger.d("SMS", "Marked ${successfulIds.size} messages as synced", "IncomingHandler")
        }
    }

    /**
     * Get count of unsynced messages
     */
    suspend fun getUnsyncedCount(): Int {
        return try {
            receivedSmsDao.getUnsyncedCount()
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error getting unsynced count", "IncomingHandler", e)
            0
        }
    }

    /**
     * Get recent received messages
     */
    suspend fun getRecentMessages(limit: Int = 100): List<ReceivedMessage> {
        return try {
            receivedSmsDao.getRecentMessages(limit)
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error getting recent messages", "IncomingHandler", e)
            emptyList()
        }
    }

    /**
     * Combined store and sync operation for immediate processing
     */
    fun storeAndSync(sender: String, message: String, simSlot: Int, timestamp: Long) {
        helperScope.launch {
            try {
                GatewayLogger.i("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_processing_incoming), "IncomingHandler")

                // Store in database
                val messageId = storeReceivedMessage(sender, message, simSlot, timestamp)
                GatewayLogger.d("SMS", "storeAndSync: Stored message with ID: $messageId", "IncomingHandler")

                if (messageId != null) {
                    GatewayLogger.i("SMS", dev.titansys.smsgateway.R.string.log_sms_stored_in_db, "IncomingHandler", messageId)

                    // Immediately try to sync this message
                    val storedMessage = receivedSmsDao.getMessageById(messageId)
                    if (storedMessage != null && !storedMessage.isSynced) {
                        GatewayLogger.d("SMS", "storeAndSync: Syncing message to server", "IncomingHandler")
                        GatewayLogger.i("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_syncing_to_server), "IncomingHandler")
                        processBatch(listOf(storedMessage))
                    } else {
                        GatewayLogger.d("SMS", "storeAndSync: Message already synced or not found", "IncomingHandler")
                        GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_already_synced), "IncomingHandler")
                    }
                } else {
                    GatewayLogger.e("SMS", "storeAndSync: Failed to store message in database", "IncomingHandler")
                    GatewayLogger.e("SMS", "Failed to store SMS in database", "IncomingHandler")
                }
            } catch (e: Exception) {
                GatewayLogger.e("SMS", "Error in storeAndSync", "IncomingHandler", e)
                GatewayLogger.e("SMS", "IncomingHandler", e) { "Error processing SMS: ${e.message}" }
            }
        }
    }

    /**
     * Manual sync trigger for testing or user-initiated sync
     */
    fun triggerManualSync() {
        GatewayLogger.d("SMS", "Manual sync triggered", "IncomingHandler")
        GatewayLogger.i("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_manually_syncing), "IncomingHandler")
        syncUnsyncedMessages()
    }

    /**
     * Clear all synced messages (cleanup operation)
     */
    suspend fun clearSyncedMessages() {
        try {
            val oneWeekAgo = System.currentTimeMillis() - (7 * 24 * 60 * 60 * 1000L)
            receivedSmsDao.deleteOldMessages(oneWeekAgo)
            GatewayLogger.d("SMS", "Cleared old synced messages", "IncomingHandler")
            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_cleared_old_messages), "IncomingHandler")
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error clearing synced messages", "IncomingHandler", e)
        }
    }
}