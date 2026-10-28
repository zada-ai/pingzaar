package dev.titansys.smsgateway.workers

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters
import kotlinx.coroutines.runBlocking
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.helpers.RestApiClient
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.services.ReportRequest

/**
 * Specialized worker for message status synchronization with server
 * Based on Burat's SyncMessagesStatusWorker pattern for efficient delivery report sync
 *
 * This worker handles:
 * - Message delivery status reports to server
 * - SMS success/failure notifications
 * - Background sync of message states
 */
class MessageStatusWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {

    companion object {
        const val KEY_UID = "uid"
        const val KEY_MID = "mid"
        const val KEY_STATUS = "status"
        const val KEY_ERROR_CODE = "error_code"
        private const val TAG = "MessageStatusWorker"

        // Status constants for message delivery
        const val STATUS_SENT = "sent"
        const val STATUS_DELIVERED = "delivered"
        const val STATUS_FAILED = "failed"
    }

    override fun doWork(): Result {
        return try {
            GatewayLogger.d("SMS", "Starting message status synchronization", "StatusWorker")

            // Get session data (following Burat's SharedPreferences pattern)
            val sessionManager = SessionManager(applicationContext)
            val deviceHelper = DeviceInfoProvider(applicationContext)
            val hash = sessionManager.getHash()
            val did = deviceHelper.getAndroidId()

            // Validate session data
            if (hash.isNullOrEmpty() || did.isNullOrEmpty()) {
                GatewayLogger.e("SMS", "Missing session data - hash: ${hash != null}, did: ${did != null}", "StatusWorker")
                return Result.failure()
            }

            // Extract status data from input (following Burat's LinkedHashMap pattern)
            val uid = inputData.getInt(KEY_UID, 0)
            val mid = inputData.getInt(KEY_MID, 0)
            val status = inputData.getString(KEY_STATUS)
            val errorCode = inputData.getString(KEY_ERROR_CODE)

            // Validate status data
            if (uid == 0 || mid == 0 || status.isNullOrEmpty()) {
                GatewayLogger.e("SMS", "Missing status data - uid: $uid, mid: $mid, status: ${status != null}", "StatusWorker")
                return Result.failure()
            }

            GatewayLogger.d("SMS", "Syncing message status to server - UID: $uid, MID: $mid, Status: $status", "StatusWorker")

            // Create request using existing API structure
            val request = ReportRequest(
                hash = hash,
                did = did,
                uid = uid,
                mid = mid,
                code = errorCode,
                status = status
            )

            // Use existing API client with runBlocking for suspend function
            val response = runBlocking {
                RestApiClient.apiService.reportSentMessage(request)
            }

            when {
                response.isSuccessful -> {
                    val responseBody = response.body()
                    GatewayLogger.d("SMS", "Message status sync successful - status: ${responseBody?.status}", "StatusWorker")
                    Result.success()
                }
                response.code() in 400..499 -> {
                    // Client error - don't retry
                    GatewayLogger.e("SMS", "Message status sync client error: ${response.code()} - ${response.message()}", "StatusWorker")
                    Result.failure()
                }
                else -> {
                    // Server error or network issue - retry
                    GatewayLogger.w("SMS", dev.titansys.smsgateway.R.string.log_worker_status_sync_failed, "StatusWorker", response.code(), response.message())
                    Result.retry()
                }
            }
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Message status sync exception: ${e.message}", "StatusWorker", e)
            Result.retry()
        }
    }
}