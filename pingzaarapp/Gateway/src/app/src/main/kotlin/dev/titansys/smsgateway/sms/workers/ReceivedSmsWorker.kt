package dev.titansys.smsgateway.sms.workers

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters
import kotlinx.coroutines.runBlocking
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.helpers.RestApiClient
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.services.ReceivedRequest

/**
 * Specialized worker for received SMS synchronization with server
 * Based on Burat's SyncReceivedMessagesWorker pattern for efficient background SMS sync
 *
 * This worker handles:
 * - Received SMS message submission to server
 * - Background sync of incoming messages
 * - Network-aware execution with retry logic
 */
class ReceivedSmsWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {

    companion object {
        const val KEY_MESSAGE_ID = "message_id"
        const val KEY_SENDER = "sender"
        const val KEY_MESSAGE = "message"
        const val KEY_SIM = "sim"
        const val KEY_ATTACHMENT = "attachment"
        private const val TAG = "ReceivedSmsWorker"
    }

    override fun doWork(): Result {
        return try {
            GatewayLogger.d("SMS", "Starting message synchronization", "ReceivedWorker")

            // Get session data (following Burat's SharedPreferences pattern)
            val sessionManager = SessionManager(applicationContext)
            val deviceHelper = DeviceInfoProvider(applicationContext)
            val hash = sessionManager.getHash()
            val did = deviceHelper.getAndroidId()

            // Validate session data
            if (hash.isNullOrEmpty() || did.isNullOrEmpty()) {
                GatewayLogger.e("SMS", "Missing session data - hash: ${hash != null}, did: ${did != null}", "ReceivedWorker")
                return Result.failure()
            }

            // Extract SMS data from input (following Burat's LinkedHashMap pattern)
            val messageId = inputData.getLong(KEY_MESSAGE_ID, 0L)
            val sender = inputData.getString(KEY_SENDER)
            val message = inputData.getString(KEY_MESSAGE)
            val sim = inputData.getInt(KEY_SIM, 0)
            val attachment = inputData.getString(KEY_ATTACHMENT) ?: ""

            // Validate SMS data
            if (messageId == 0L || sender.isNullOrEmpty() || message.isNullOrEmpty()) {
                GatewayLogger.e("SMS", "Missing required data - messageId: $messageId, sender: ${sender != null}, message: ${message != null}", "ReceivedWorker")
                return Result.failure()
            }

            GatewayLogger.d("SMS", "Syncing message to server - ID: $messageId, sim: $sim", "ReceivedWorker")

            // Create request using existing API structure
            val request = ReceivedRequest(
                hash = hash,
                did = did,
                sim = sim,
                message_id = messageId,
                sender = sender,
                message = message,
                attachment = attachment
            )

            // Use existing API client with runBlocking for suspend function
            val response = runBlocking {
                RestApiClient.apiService.receivedMessage(request)
            }

            when {
                response.isSuccessful -> {
                    val responseBody = response.body()
                    GatewayLogger.d("SMS", "Message sync successful - status: ${responseBody?.status}", "ReceivedWorker")
                    Result.success()
                }
                response.code() in 400..499 -> {
                    // Client error - don't retry
                    GatewayLogger.e("SMS", "Message sync client error: ${response.code()} - ${response.message()}", "ReceivedWorker")
                    Result.failure()
                }
                else -> {
                    // Server error or network issue - retry
                    GatewayLogger.w("SMS", dev.titansys.smsgateway.R.string.log_worker_message_sync_failed, "ReceivedWorker", response.code(), response.message())
                    Result.retry()
                }
            }
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Message sync exception: ${e.message}", "ReceivedWorker", e)
            Result.retry()
        }
    }
}