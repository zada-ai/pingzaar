package dev.titansys.smsgateway.sms.workers

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import androidx.work.Data
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.Constraints
import androidx.work.NetworkType
import androidx.work.WorkManager
import dev.titansys.smsgateway.sms.SmsReportManager
import dev.titansys.smsgateway.helpers.GatewayLogger

/**
 * Background worker for syncing SMS delivery reports (Burat's approach)
 * Uses WorkManager instead of foreground service for battery efficiency
 */
class SmsReportWorker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    companion object {
        private const val KEY_USER_ID = "uid"
        private const val KEY_MESSAGE_ID = "mid"
        private const val KEY_SUCCESS = "success"
        private const val KEY_ERROR_CODE = "error_code"

        /**
         * Schedule background report sync (Burat's pattern)
         */
        fun scheduleReport(
            context: Context,
            uid: Int,
            mid: Int,
            success: Boolean,
            errorCode: String
        ) {
            val inputData = Data.Builder()
                .putInt(KEY_USER_ID, uid)
                .putInt(KEY_MESSAGE_ID, mid)
                .putBoolean(KEY_SUCCESS, success)
                .putString(KEY_ERROR_CODE, errorCode)
                .build()

            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val workRequest = OneTimeWorkRequestBuilder<SmsReportWorker>()
                .setInputData(inputData)
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueue(workRequest)

            GatewayLogger.d("SMS", "Scheduled report sync for message #$mid", "ReportWorker")
        }
    }

    override suspend fun doWork(): Result {
        val uid = inputData.getInt(KEY_USER_ID, 0)
        val mid = inputData.getInt(KEY_MESSAGE_ID, 0)
        val success = inputData.getBoolean(KEY_SUCCESS, false)
        val errorCode = inputData.getString(KEY_ERROR_CODE) ?: "UNKNOWN"

        return try {
            GatewayLogger.d("SMS", "Syncing report for message #$mid", "ReportWorker")

            SmsReportManager().sendReport(
                context = applicationContext,
                uid = uid,
                mid = mid,
                success = success,
                errorCode = errorCode
            )

            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_report_synced, mid), "ReportWorker")
            Result.success()

        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Failed to sync report for message #$mid: ${e.message}", "ReportWorker", e)

            // Retry on failure (up to 3 attempts)
            if (runAttemptCount < 3) {
                Result.retry()
            } else {
                Result.failure()
            }
        }
    }
}