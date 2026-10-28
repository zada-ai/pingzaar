package dev.titansys.smsgateway.workers

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters
import kotlinx.coroutines.runBlocking
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.helpers.RestApiClient
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.services.TokenRequest

/**
 * Specialized worker for FCM token synchronization with server
 * Based on Burat's UpdateTokenWorker pattern for efficient background token sync
 *
 * This worker handles:
 * - FCM token submission to server
 * - Retry logic for failed token sync
 * - Network-aware execution with constraints
 */
class TokenSyncWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {

    companion object {
        const val KEY_HASH = "hash"
        const val KEY_DID = "did"
        const val KEY_TOKEN = "token"
        private const val TAG = "TokenSyncWorker"
    }

    override fun doWork(): Result {
        return try {
            GatewayLogger.d("FCM", "Starting FCM token synchronization", "TokenWorker")

            // Extract input data (following Burat's LinkedHashMap pattern)
            val hash = inputData.getString(KEY_HASH)
            val did = inputData.getString(KEY_DID)
            val token = inputData.getString(KEY_TOKEN)

            // Validate required parameters
            if (hash.isNullOrEmpty() || did.isNullOrEmpty() || token.isNullOrEmpty()) {
                GatewayLogger.e("FCM", "Missing required parameters - hash: ${hash != null}, did: ${did != null}, token: ${token != null}", "TokenWorker")
                return Result.failure()
            }

            GatewayLogger.d("FCM", "Syncing token with server - hash: ${hash.take(5)}..., did: ${did.take(5)}...", "TokenWorker")

            // Use existing API client with runBlocking for suspend function
            val response = runBlocking {
                RestApiClient.apiService.submitToken(TokenRequest(hash, did, token))
            }

            when {
                response.isSuccessful -> {
                    val responseBody = response.body()
                    GatewayLogger.d("FCM", "Token sync successful - status: ${responseBody?.status}", "TokenWorker")
                    Result.success()
                }
                response.code() in 400..499 -> {
                    // Client error - don't retry
                    GatewayLogger.e("FCM", "Token sync client error: ${response.code()} - ${response.message()}", "TokenWorker")
                    Result.failure()
                }
                else -> {
                    // Server error or network issue - retry
                    GatewayLogger.w("FCM", dev.titansys.smsgateway.R.string.log_worker_token_sync_failed, "TokenWorker", response.code(), response.message())
                    Result.retry()
                }
            }
        } catch (e: Exception) {
            GatewayLogger.e("FCM", "Token sync exception: ${e.message}", "TokenWorker", e)
            Result.retry()
        }
    }
}