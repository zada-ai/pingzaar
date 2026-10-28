package dev.titansys.smsgateway.receivers

import android.content.BroadcastReceiver
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import com.google.firebase.messaging.FirebaseMessaging
import dev.titansys.smsgateway.activities.GatewayActivity
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.helpers.ScheduledTaskManager
import dev.titansys.smsgateway.helpers.ServiceStateManager
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.services.MessageQueueService
import dev.titansys.smsgateway.R

class DeviceBootReceiver : BroadcastReceiver() {
    companion object {
        private const val TAG = "BootReceiver"
    }

    override fun onReceive(context: Context, intent: Intent) {
        when (intent.action) {
            Intent.ACTION_BOOT_COMPLETED,
            "android.intent.action.QUICKBOOT_POWERON",
            "com.htc.intent.action.QUICKBOOT_POWERON" -> {
                GatewayLogger.d("BOOT", "Boot completed (${intent.action}), initializing background services", "Receiver")

                try {
                    val sessionManager = SessionManager(context)

                    // Nothing may be scheduled for a device that is not paired.
                    //
                    // getSmsEnabled()/getUssdEnabled() both default to true, so a
                    // fresh install that has never been paired used to schedule
                    // workers on its first reboot. Those workers reach for
                    // RestApiClient.apiService, which throws when no site URL is
                    // stored, and their catch turns that into Result.retry() --
                    // so WorkManager retried forever, draining battery, with no
                    // possible success and nothing shown to the user.
                    //
                    // This gate covers the FCM refresh below as well: it schedules
                    // a token sync worker, which fails the same way, and on an
                    // unpaired device Firebase is not initialised for it to query.
                    if (!sessionManager.isLoggedIn()) {
                        GatewayLogger.d("BOOT", "Device is not paired - skipping all background scheduling", "Receiver")
                        return
                    }

                    // Refresh FCM token after device reboot
                    refreshFcmToken(context)

                    // WorkManager scheduling is INDEPENDENT of foreground service state,
                    // so 15-minute polling resumes after reboot even if the foreground
                    // service was not running.

                    // Schedule SMS WorkManager if enabled
                    if (sessionManager.getSmsEnabled()) {
                        GatewayLogger.d("BOOT", "SMS enabled - scheduling WorkManager background processing", "Receiver")
                        ScheduledTaskManager.startPeriodicScheduling(context)
                    } else {
                        GatewayLogger.d("BOOT", "SMS disabled - skipping WorkManager scheduling", "Receiver")
                    }

                    // Schedule USSD WorkManager if enabled
                    if (sessionManager.getUssdEnabled()) {
                        GatewayLogger.d("BOOT", "USSD enabled - scheduling WorkManager background processing", "Receiver")
                        ScheduledTaskManager.scheduleUssdBackgroundProcessing(context)
                    } else {
                        GatewayLogger.d("BOOT", "USSD disabled - skipping USSD scheduling", "Receiver")
                    }

                    // SEPARATE CONCERN: Only restart foreground services if they were running before shutdown
                    // This is independent from WorkManager scheduling above
                    if (ServiceStateManager.isServiceRunning(context)) {
                        GatewayLogger.d("BOOT", "Foreground service was active before shutdown - restarting MessageQueueService", "Receiver")
                        startMessageQueueService(context)
                    } else {
                        GatewayLogger.d("BOOT", "Foreground service was not active - not restarting (WorkManager still scheduled above)", "Receiver")
                    }

                } catch (e: Exception) {
                    GatewayLogger.e("BOOT", "Error during boot initialization: ${e.message}", "Receiver", e)
                }
            }
        }
    }

    private fun startMessageQueueService(context: Context) {
        val messageServiceIntent = Intent(context, MessageQueueService::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            context.startForegroundService(messageServiceIntent)
        } else {
            context.startService(messageServiceIntent)
        }
        GatewayLogger.d("BOOT", "MessageQueueService started", "Receiver")
    }

    /**
     * Refresh FCM token after device boot to ensure backend has the current token
     * Fixes issue where FCM notifications stop working after device restart
     */
    private fun refreshFcmToken(context: Context) {
        try {
            GatewayLogger.d("BOOT", "Requesting FCM token refresh after device boot", "Receiver")
            GatewayLogger.i("BOOT", R.string.log_fcm_token_syncing_boot, "Receiver")

            FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
                if (task.isSuccessful) {
                    val token = task.result
                    GatewayLogger.d("BOOT", "FCM token retrieved: ${token?.take(10)}...", "Receiver")
                    GatewayLogger.i("BOOT", R.string.log_fcm_token_refreshed, "Receiver")

                    // Schedule token sync with backend via WorkManager
                    if (token != null) {
                        ScheduledTaskManager.scheduleTokenSync(context, token)
                        GatewayLogger.d("BOOT", "FCM token sync scheduled successfully", "Receiver")
                    }
                } else {
                    GatewayLogger.e("BOOT", "Failed to retrieve FCM token: ${task.exception?.message}", "Receiver", task.exception)
                    GatewayLogger.w("BOOT", R.string.log_fcm_token_refresh_failed, "Receiver")
                }
            }
        } catch (e: Exception) {
            GatewayLogger.e("BOOT", "Error refreshing FCM token: ${e.message}", "Receiver", e)
            GatewayLogger.w("BOOT", R.string.log_fcm_token_refresh_failed, "Receiver")
        }
    }

}
