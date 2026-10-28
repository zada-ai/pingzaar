package dev.titansys.smsgateway.sms

import android.content.Context
import kotlinx.coroutines.delay
import dev.titansys.smsgateway.activities.GatewayActivity
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.databases.SmsMessage
import dev.titansys.smsgateway.databases.ZenderDatabase
import dev.titansys.smsgateway.models.InternalErrorCode
import kotlin.math.pow

/**
 * Handles message retry logic with exponential backoff and intelligent error analysis.
 * Uses existing database structure without requiring schema changes.
 */
class SmsRetryManager(private val context: Context) {
    companion object {
        private const val TAG = "SmsRetryManager"
        private const val MAX_RETRIES = 3
        private const val BASE_DELAY_MS = 1000L // 1 second base delay
        private const val MAX_DELAY_MS = 30000L // 30 seconds max delay

        // Retry strategy based on error types
        private val RETRYABLE_ERRORS = setOf(
            InternalErrorCode.NETWORK_ERROR,
            InternalErrorCode.NETWORK_TIMEOUT,
            InternalErrorCode.SERVER_ERROR,
            InternalErrorCode.SERVICE_UNAVAILABLE,
            InternalErrorCode.SMS_RESULT_ERROR_GENERIC_FAILURE,
            InternalErrorCode.SMS_RESULT_ERROR_NO_SERVICE,
            InternalErrorCode.QUEUE_FULL
        )

        private val NON_RETRYABLE_ERRORS = setOf(
            InternalErrorCode.PERMISSION_DENIED,
            InternalErrorCode.PHONE_PERMISSION_DENIED,
            InternalErrorCode.INVALID_DESTINATION,
            InternalErrorCode.MESSAGE_TOO_LONG,
            InternalErrorCode.EMPTY_MESSAGE,
            InternalErrorCode.SIM_ABSENT,
            InternalErrorCode.SIM_LOCKED
        )
    }

    /**
     * Check if a message should be retried based on error type and retry count
     */
    fun shouldRetry(message: SmsMessage, errorCode: InternalErrorCode, currentRetryCount: Int): Boolean {
        // Don't retry if max retries reached
        if (currentRetryCount >= MAX_RETRIES) {
            GatewayLogger.d("SMS", "Max retries ($MAX_RETRIES) reached for message #${message.id}", "Retry")
            return false
        }

        // Don't retry non-retryable errors
        if (NON_RETRYABLE_ERRORS.contains(errorCode)) {
            GatewayLogger.d("SMS", "Error ${errorCode.code} is not retryable for message #${message.id}", "Retry")
            return false
        }

        // Always retry retryable errors
        if (RETRYABLE_ERRORS.contains(errorCode)) {
            GatewayLogger.d("SMS", "Error ${errorCode.code} is retryable for message #${message.id}", "Retry")
            return true
        }

        // For unknown errors, retry with caution (limited retries)
        if (errorCode == InternalErrorCode.UNKNOWN_ERROR && currentRetryCount < 1) {
            GatewayLogger.d("SMS", "Unknown error - allowing limited retry for message #${message.id}", "Retry")
            return true
        }

        GatewayLogger.d("SMS", "Error ${errorCode.code} - no retry for message #${message.id}", "Retry")
        return false
    }

    /**
     * Calculate exponential backoff delay with jitter
     */
    fun calculateRetryDelay(retryAttempt: Int): Long {
        // Exponential backoff: base_delay * 2^attempt
        val exponentialDelay = BASE_DELAY_MS * (2.0.pow(retryAttempt.toDouble())).toLong()

        // Add jitter (±25% randomization) to avoid thundering herd
        val jitter = (exponentialDelay * 0.25 * (Math.random() - 0.5)).toLong()
        val delayWithJitter = exponentialDelay + jitter

        // Cap at maximum delay
        val finalDelay = minOf(delayWithJitter, MAX_DELAY_MS)

        GatewayLogger.d("SMS", "Retry attempt $retryAttempt: calculated delay ${finalDelay}ms", "Retry")
        return finalDelay
    }

    /**
     * Schedule a message for retry by updating its processed status
     * Uses existing database fields to track retry without schema changes
     */
    suspend fun scheduleRetry(message: SmsMessage, errorCode: InternalErrorCode, retryAttempt: Int): Boolean {
        try {
            val database = ZenderDatabase.getInstance(context)

            // Calculate retry delay
            val retryDelay = calculateRetryDelay(retryAttempt)

            // Mark message as unprocessed so it gets picked up again
            // We use the existing 'isProcessed' field - false means it will be retried
            val retryMessage = message.copy(isProcessed = false)
            database.messagesDao().updateMessage(retryMessage)

            GatewayLogger.d("SMS", "Scheduled retry for message #${message.id} after ${retryDelay}ms (attempt ${retryAttempt + 1})", "Retry")
            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_scheduled_retry, message.id, retryAttempt + 1), "Retry")

            // Apply the calculated delay
            delay(retryDelay)

            return true
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error scheduling retry for message #${message.id}: ${e.message}", "Retry", e)
            return false
        }
    }

    /**
     * Process a message with automatic retry logic
     * This wraps the existing message processing with retry capability
     */
    suspend fun processMessageWithRetry(
        message: SmsMessage,
        maxRetries: Int = MAX_RETRIES,
        processFunction: suspend (SmsMessage) -> Unit
    ): Boolean {
        var currentRetry = 0
        var lastError: InternalErrorCode = InternalErrorCode.UNKNOWN_ERROR

        while (currentRetry <= maxRetries) {
            try {
                GatewayLogger.d("SMS", "Processing message #${message.id} (attempt ${currentRetry + 1}/${maxRetries + 1})", "Retry")

                // Execute the actual message processing
                processFunction(message)

                // Success - mark as processed and exit
                val database = ZenderDatabase.getInstance(context)
                val successMessage = message.copy(isProcessed = true)
                database.messagesDao().updateMessage(successMessage)

                GatewayLogger.d("SMS", "Message #${message.id} processed successfully on attempt ${currentRetry + 1}", "Retry")
                GatewayLogger.i("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_message_sent_after_retry, message.id, currentRetry + 1), "Retry")

                return true

            } catch (e: Exception) {
                lastError = InternalErrorCode.fromException(e)
                currentRetry++

                GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_retry_attempt_failed, currentRetry, message.id, e.message ?: ""), "Retry")

                // Check if we should retry
                if (currentRetry <= maxRetries && shouldRetry(message, lastError, currentRetry - 1)) {
                    val retryDelay = calculateRetryDelay(currentRetry - 1)
                    GatewayLogger.d("SMS", "Retrying message #${message.id} in ${retryDelay}ms", "Retry")
                    GatewayLogger.i("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_retrying_message, message.id, retryDelay/1000), "Retry")

                    delay(retryDelay)
                } else {
                    // No more retries - mark as processed to prevent infinite retry
                    GatewayLogger.e("SMS", "All retries exhausted for message #${message.id}", "Retry")
                    GatewayLogger.i("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_message_failed_all_retries, message.id, currentRetry), "Retry")

                    val database = ZenderDatabase.getInstance(context)
                    val failedMessage = message.copy(isProcessed = true)
                    database.messagesDao().updateMessage(failedMessage)

                    // Send final error report
                    try {
                        SmsReportManager().sendReport(
                            context = context,
                            uid = message.userId,
                            mid = message.id,
                            success = false,
                            errorCode = lastError
                        )
                    } catch (reportException: Exception) {
                        GatewayLogger.e("SMS", "Failed to send final error report: ${reportException.message}", "Retry")
                    }

                    return false
                }
            }
        }

        return false
    }

    /**
     * Get retry statistics for monitoring
     */
    fun getRetryStatistics(): Map<String, Any> {
        return mapOf(
            "maxRetries" to MAX_RETRIES,
            "baseDelayMs" to BASE_DELAY_MS,
            "maxDelayMs" to MAX_DELAY_MS,
            "retryableErrorCount" to RETRYABLE_ERRORS.size,
            "nonRetryableErrorCount" to NON_RETRYABLE_ERRORS.size
        )
    }
}