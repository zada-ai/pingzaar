package dev.titansys.smsgateway.workers

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters
import com.google.firebase.messaging.FirebaseMessaging
import dev.titansys.smsgateway.R
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.helpers.ScheduledTaskManager

/**
 * Periodic worker for refreshing FCM tokens to prevent expiration
 *
 * FCM tokens can expire after 30-90 days without app restarts or Firebase refresh.
 * This worker runs every 7 days to proactively refresh and sync tokens with the backend.
 *
 * Benefits:
 * - Prevents FCM notification failures due to stale tokens
 * - Survives device reboots via WorkManager persistence
 * - Network-aware: only runs when connectivity is available
 * - Automatic retry on failure
 *
 * Based on Burat's UpdateTokenWorker pattern for reliable token management
 */
class FcmTokenRefreshWorker(
    context: Context,
    params: WorkerParameters
) : Worker(context, params) {

    companion object {
        const val WORK_NAME = "FCM_TOKEN_REFRESH"
        private const val TAG = "FcmTokenRefreshWorker"
    }

    override fun doWork(): Result {
        return try {
            GatewayLogger.d("FCM", "Starting periodic FCM token refresh (7-day cycle)", "TokenRefresh")

            // Request fresh token from Firebase
            var success = false
            val lock = Object()

            FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
                synchronized(lock) {
                    if (task.isSuccessful) {
                        val token = task.result
                        GatewayLogger.d("FCM", "Token retrieved successfully: ${token?.take(10)}...", "TokenRefresh")
                        GatewayLogger.i("FCM", R.string.log_fcm_token_refreshed, "TokenRefresh")

                        // Schedule sync with backend via ScheduledTaskManager
                        if (token != null) {
                            ScheduledTaskManager.scheduleTokenSync(applicationContext, token)
                            GatewayLogger.d("FCM", "Token sync scheduled successfully", "TokenRefresh")
                            success = true
                        }
                    } else {
                        GatewayLogger.e("FCM", "Token refresh failed: ${task.exception?.message}", "TokenRefresh", task.exception)
                        GatewayLogger.w("FCM", R.string.log_fcm_token_refresh_failed, "TokenRefresh")
                    }
                    lock.notify()
                }
            }

            // Wait for async Firebase call to complete (max 10 seconds)
            synchronized(lock) {
                lock.wait(10000)
            }

            if (success) {
                Result.success()
            } else {
                GatewayLogger.w("FCM", "FCM token refresh timed out or failed, will retry", "TokenRefresh")
                Result.retry()
            }
        } catch (e: Exception) {
            GatewayLogger.e("FCM", "Exception during token refresh: ${e.message}", "TokenRefresh", e)
            GatewayLogger.w("FCM", R.string.log_fcm_token_refresh_failed, "TokenRefresh")
            Result.retry()
        }
    }
}
