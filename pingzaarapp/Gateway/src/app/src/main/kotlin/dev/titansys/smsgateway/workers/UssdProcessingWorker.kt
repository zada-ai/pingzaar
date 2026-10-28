package dev.titansys.smsgateway.workers

import android.content.Context
import dev.titansys.smsgateway.helpers.GatewayLogger
import androidx.work.Worker
import androidx.work.WorkerParameters
import dev.titansys.smsgateway.helpers.UssdManager
import dev.titansys.smsgateway.helpers.SessionManager

/**
 * WorkManager-based background worker that ensures USSD processing continues
 * even when the app is closed or killed by the system.
 *
 * This worker runs periodically (every 15 minutes) and triggers the UssdQueueService
 * to process any pending USSD requests, ensuring reliable delivery regardless of app state.
 */
class UssdProcessingWorker(
    context: Context,
    workerParams: WorkerParameters
) : Worker(context, workerParams) {

    companion object {
        private const val TAG = "UssdProcessingWorker"
        const val WORK_NAME = "USSD_PROCESSING_WORK"
    }

    override fun doWork(): Result {
        return try {
            GatewayLogger.d("USSD", "Background USSD processing worker started", "ProcessingWorker")

            // Check if USSD is enabled
            val sessionManager = SessionManager(applicationContext)
            if (!sessionManager.getUssdEnabled()) {
                GatewayLogger.d("USSD", "USSD is disabled, skipping background processing", "ProcessingWorker")
                return Result.success()
            }

            // Start UssdQueueService using existing helper
            GatewayLogger.d("USSD", "Triggering UssdQueueService from background worker", "ProcessingWorker")
            UssdManager.startUssdService(applicationContext)

            GatewayLogger.d("USSD", "Background USSD processing worker completed successfully", "ProcessingWorker")
            Result.success()
        } catch (e: Exception) {
            GatewayLogger.e("USSD", "Error in background USSD processing: ${e.message}", "ProcessingWorker", e)
            // Retry on failure, but don't retry indefinitely
            Result.retry()
        }
    }
}
