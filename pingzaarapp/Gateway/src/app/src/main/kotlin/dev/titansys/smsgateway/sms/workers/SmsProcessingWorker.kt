package dev.titansys.smsgateway.sms.workers

import android.content.Context
import dev.titansys.smsgateway.helpers.GatewayLogger
import androidx.work.Worker
import androidx.work.WorkerParameters
import dev.titansys.smsgateway.sms.SmsServiceManager
import dev.titansys.smsgateway.helpers.SessionManager

/**
 * WorkManager-based background worker that ensures message processing continues
 * even when the app is closed or killed by the system.
 *
 * This worker runs periodically (every 15 minutes) and triggers the SmsQueueService
 * to process any pending messages, ensuring reliable delivery regardless of app state.
 */
class MessageProcessingWorker(
    context: Context,
    workerParams: WorkerParameters
) : Worker(context, workerParams) {

    companion object {
        private const val TAG = "MessageProcessingWorker"
        const val WORK_NAME = "MESSAGE_PROCESSING_WORK"
    }

    override fun doWork(): Result {
        return try {
            GatewayLogger.d("SMS", "Background message processing worker started", "ProcessingWorker")

            // Check if SMS is enabled
            val sessionManager = SessionManager(applicationContext)
            if (!sessionManager.getSmsEnabled()) {
                GatewayLogger.d("SMS", "SMS is disabled, skipping background processing", "ProcessingWorker")
                return Result.success()
            }

            // Start SmsQueueService using existing helper
            GatewayLogger.d("SMS", "Triggering SmsQueueService from background worker", "ProcessingWorker")
            SmsServiceManager.startMessageQueueService(applicationContext)

            GatewayLogger.d("SMS", "Background message processing worker completed successfully", "ProcessingWorker")
            Result.success()
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error in background message processing: ${e.message}", "ProcessingWorker", e)
            // Retry on failure, but don't retry indefinitely
            Result.retry()
        }
    }
}