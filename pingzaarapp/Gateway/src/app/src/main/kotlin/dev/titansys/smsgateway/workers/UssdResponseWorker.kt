package dev.titansys.smsgateway.workers

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.helpers.RestApiClient
import dev.titansys.smsgateway.services.UssdResponseRequest

/**
 * WorkManager worker for queuing USSD response reports
 * Ensures USSD responses are not lost when internet is unavailable
 *
 * Key features:
 * - Queues USSD responses when offline
 * - Automatically retries when network returns
 * - Exponential backoff for server errors
 * - Permanent failure for client errors (4xx)
 *
 * This solves the problem where USSD codes are sent via cellular (works offline)
 * but response reporting requires internet API call
 */
class UssdResponseWorker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    companion object {
        private const val TAG = "UssdResponseWorker"
    }

    override suspend fun doWork(): Result {
        return try {
            // Extract USSD response data from input
            val hash = inputData.getString("hash") ?: run {
                GatewayLogger.e("USSD", "Missing hash in UssdResponseWorker", "Worker")
                return Result.failure()
            }

            val did = inputData.getString("did") ?: run {
                GatewayLogger.e("USSD", "Missing device ID in UssdResponseWorker", "Worker")
                return Result.failure()
            }

            val ussdId = inputData.getInt("ussd_id", 0)
            if (ussdId == 0) {
                GatewayLogger.e("USSD", "Invalid ussd_id in UssdResponseWorker", "Worker")
                return Result.failure()
            }

            val response = inputData.getString("response") ?: ""
            val status = inputData.getInt("status", 4) // Default to failed if not specified

            GatewayLogger.d("USSD", "Sending USSD response to server - ID: $ussdId, status: $status", "Worker")

            // Make API call to send USSD response
            val apiResponse = RestApiClient.apiService.sendUssdResponse(
                UssdResponseRequest(
                    hash = hash,
                    did = did,
                    ussd_id = ussdId,
                    response = response,
                    status = status
                )
            )

            // Handle response based on HTTP status code
            when {
                apiResponse.isSuccessful -> {
                    GatewayLogger.d("USSD", "USSD response sent successfully for ID: $ussdId", "Worker")
                    Result.success()
                }
                apiResponse.code() in 400..499 -> {
                    // Client error (bad request, auth failure, etc.) - don't retry
                    GatewayLogger.e("USSD", "Client error sending USSD response: ${apiResponse.code()} - ${apiResponse.message()}", "Worker")
                    Result.failure()
                }
                else -> {
                    // Server error (5xx) or network issue - retry with exponential backoff
                    GatewayLogger.w("USSD", applicationContext.getString(dev.titansys.smsgateway.R.string.log_worker_ussd_response_retry, apiResponse.code()), "Worker")
                    Result.retry()
                }
            }
        } catch (e: Exception) {
            // Network exception or other error - retry
            GatewayLogger.e("USSD", "Exception sending USSD response: ${e.message}", "Worker", e)
            Result.retry()
        }
    }
}
