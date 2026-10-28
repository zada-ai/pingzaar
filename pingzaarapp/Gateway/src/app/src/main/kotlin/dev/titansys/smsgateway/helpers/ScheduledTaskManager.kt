package dev.titansys.smsgateway.helpers

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.os.Build
import dev.titansys.smsgateway.helpers.GatewayLogger
import androidx.work.Constraints
import androidx.work.Data
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import dev.titansys.smsgateway.sms.receivers.ScheduledSmsReceiver
import dev.titansys.smsgateway.sms.workers.MessageProcessingWorker
import dev.titansys.smsgateway.workers.TokenSyncWorker
import dev.titansys.smsgateway.workers.FcmTokenRefreshWorker
import dev.titansys.smsgateway.sms.workers.ReceivedSmsWorker
import dev.titansys.smsgateway.workers.MessageStatusWorker
import dev.titansys.smsgateway.workers.UssdProcessingWorker
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import java.util.concurrent.TimeUnit

object ScheduledTaskManager {
    private const val TAG = "ScheduledTaskManager"
    private val receiver = ScheduledSmsReceiver()

    /**
     * Start periodic scheduling of SmsQueueService using hybrid AlarmManager + WorkManager approach
     * @param context Application context
     */
    fun startPeriodicScheduling(context: Context) {
        try {
            // Start AlarmManager-based scheduling (for immediate responsiveness)
            if (!receiver.isPeriodicExecutionScheduled(context)) {
                receiver.schedulePeriodicExecution(context)
                GatewayLogger.d("SMS", "Started AlarmManager periodic scheduling", "Scheduler")
            } else {
                GatewayLogger.d("SMS", "AlarmManager periodic scheduling is already active", "Scheduler")
            }

            // Start WorkManager-based scheduling (for background reliability)
            scheduleWorkManagerProcessing(context)

        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error starting periodic scheduling: ${e.message}", "Scheduler", e)
        }
    }

    /**
     * Stop periodic scheduling of SmsQueueService (both AlarmManager and WorkManager)
     * @param context Application context
     */
    fun stopPeriodicScheduling(context: Context) {
        try {
            // Stop AlarmManager-based scheduling
            if (receiver.isPeriodicExecutionScheduled(context)) {
                receiver.cancelPeriodicExecution(context)
                GatewayLogger.d("SMS", "Stopped AlarmManager periodic scheduling", "Scheduler")
            } else {
                GatewayLogger.d("SMS", "AlarmManager periodic scheduling is not active", "Scheduler")
            }

            // Stop WorkManager-based scheduling
            cancelWorkManagerProcessing(context)

        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error stopping periodic scheduling: ${e.message}", "Scheduler", e)
        }
    }

    /**
     * Check if periodic scheduling is currently active
     * @param context Application context
     * @return True if periodic scheduling is active, false otherwise
     */
    fun isPeriodicSchedulingActive(context: Context): Boolean {
        return try {
            receiver.isPeriodicExecutionScheduled(context)
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error checking periodic scheduling status: ${e.message}", "Scheduler", e)
            false
        }
    }

    /**
     * Toggle periodic scheduling on/off
     * @param context Application context
     * @param enable True to enable, false to disable
     */
    fun togglePeriodicScheduling(context: Context, enable: Boolean) {
        if (enable) {
            startPeriodicScheduling(context)
        } else {
            stopPeriodicScheduling(context)
        }
    }

    /**
     * Schedule WorkManager-based background processing
     * Runs every 15 minutes - works offline (messages sent via cellular network)
     * @param context Application context
     */
    private fun scheduleWorkManagerProcessing(context: Context) {
        try {
            // CRITICAL FIX: SMS processing doesn't need internet
            // Messages are read from local database and sent via cellular network
            // API operations (status reporting) are handled by separate workers with network constraints
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.NOT_REQUIRED)
                .build()

            val workRequest = PeriodicWorkRequestBuilder<MessageProcessingWorker>(15, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                MessageProcessingWorker.WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                workRequest
            )

            GatewayLogger.d("SMS", "Scheduled WorkManager background processing (15-minute intervals)", "Scheduler")
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error scheduling WorkManager processing: ${e.message}", "Scheduler", e)
        }
    }

    /**
     * Cancel WorkManager-based background processing
     * @param context Application context
     */
    private fun cancelWorkManagerProcessing(context: Context) {
        try {
            WorkManager.getInstance(context).cancelUniqueWork(MessageProcessingWorker.WORK_NAME)
            GatewayLogger.d("SMS", "Cancelled WorkManager background processing", "Scheduler")
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error cancelling WorkManager processing: ${e.message}", "Scheduler", e)
        }
    }

    /**
     * Schedule WorkManager-based background processing for USSD
     * Runs every 15 minutes - works offline (USSD codes sent via cellular network)
     * @param context Application context
     */
    fun scheduleUssdBackgroundProcessing(context: Context) {
        try {
            GatewayLogger.d("USSD", "Scheduling WorkManager background processing", "Scheduler")

            // CRITICAL FIX: USSD processing doesn't need internet
            // USSD requests are read from local database and sent via cellular network
            // Response reporting to API is handled by separate worker with network constraints
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.NOT_REQUIRED)
                .build()

            val workRequest = PeriodicWorkRequestBuilder<UssdProcessingWorker>(15, TimeUnit.MINUTES)
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                UssdProcessingWorker.WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                workRequest
            )

            GatewayLogger.d("USSD", "WorkManager background processing scheduled successfully (15-minute intervals)", "Scheduler")
        } catch (e: Exception) {
            GatewayLogger.e("USSD", "Error scheduling WorkManager processing: ${e.message}", "Scheduler", e)
        }
    }

    /**
     * Cancel WorkManager-based background processing for USSD
     * @param context Application context
     */
    fun cancelUssdBackgroundProcessing(context: Context) {
        try {
            WorkManager.getInstance(context).cancelUniqueWork(UssdProcessingWorker.WORK_NAME)
            GatewayLogger.d("USSD", "Cancelled USSD WorkManager background processing", "Scheduler")
        } catch (e: Exception) {
            GatewayLogger.e("USSD", "Error cancelling USSD WorkManager processing: ${e.message}", "Scheduler", e)
        }
    }

    // ====== BURAT-INSPIRED ENHANCED ALARMMANAGER METHODS ======

    /**
     * Enhanced alarm scheduling using Burat's multi-strategy approach
     * Implements multiple timing strategies based on Android version and device state
     * @param context Application context
     * @param delay Delay in milliseconds
     * @param intent PendingIntent to trigger
     */
    fun scheduleWithMultipleStrategies(context: Context, delay: Long, intent: PendingIntent) {
        try {
            val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            val triggerTime = System.currentTimeMillis() + delay

            // MinSdk is 26 (Android O), so always use setExactAndAllowWhileIdle
            try {
                alarmManager.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerTime, intent)
                GatewayLogger.d("SMS", "Scheduled with setExactAndAllowWhileIdle (doze-compatible)", "Scheduler")
            } catch (e: Exception) {
                // Fallback to setExact
                alarmManager.setExact(AlarmManager.RTC_WAKEUP, triggerTime, intent)
                GatewayLogger.d("SMS", "Fallback to setExact due to: ${e.message}", "Scheduler")
            }
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error in multi-strategy alarm scheduling: ${e.message}", "Scheduler", e)
        }
    }

    // ====== BURAT-INSPIRED SPECIALIZED WORKER SCHEDULING ======

    /**
     * Schedule FCM token synchronization worker
     * Based on Burat's UpdateTokenWorker pattern
     * @param context Application context
     * @param token FCM token to sync
     */
    fun scheduleTokenSync(context: Context, token: String) {
        try {
            val sessionManager = SessionManager(context)
            val deviceHelper = DeviceInfoProvider(context)
            val hash = sessionManager.getHash()
            val did = deviceHelper.getAndroidId()

            if (hash.isNullOrEmpty() || did.isNullOrEmpty()) {
                GatewayLogger.e("SMS", "Cannot schedule token sync - missing session data", "Scheduler")
                return
            }

            val data = Data.Builder()
                .putString(TokenSyncWorker.KEY_HASH, hash)
                .putString(TokenSyncWorker.KEY_DID, did)
                .putString(TokenSyncWorker.KEY_TOKEN, token)
                .build()

            val request = OneTimeWorkRequestBuilder<TokenSyncWorker>()
                .setInputData(data)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()

            WorkManager.getInstance(context).enqueue(request)
            GatewayLogger.d("SMS", "Token sync worker scheduled for token: ${token.take(5)}...", "Scheduler")
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error scheduling token sync worker: ${e.message}", "Scheduler", e)
        }
    }

    /**
     * Schedule received SMS synchronization worker
     * Based on Burat's SyncReceivedMessagesWorker pattern
     * @param context Application context
     * @param messageId Unique message identifier
     * @param sender SMS sender
     * @param message SMS content
     * @param sim SIM slot number
     * @param attachment Optional attachment data
     */
    fun scheduleReceivedSmsSync(
        context: Context,
        messageId: Long,
        sender: String,
        message: String,
        sim: Int,
        attachment: String = ""
    ) {
        try {
            val data = Data.Builder()
                .putLong(ReceivedSmsWorker.KEY_MESSAGE_ID, messageId)
                .putString(ReceivedSmsWorker.KEY_SENDER, sender)
                .putString(ReceivedSmsWorker.KEY_MESSAGE, message)
                .putInt(ReceivedSmsWorker.KEY_SIM, sim)
                .putString(ReceivedSmsWorker.KEY_ATTACHMENT, attachment)
                .build()

            val request = OneTimeWorkRequestBuilder<ReceivedSmsWorker>()
                .setInputData(data)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()

            WorkManager.getInstance(context).enqueue(request)
            GatewayLogger.d("SMS", "Received SMS sync worker scheduled - ID: $messageId, sender: ${sender.take(5)}...", "Scheduler")
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error scheduling received SMS sync worker: ${e.message}", "Scheduler", e)
        }
    }

    /**
     * Schedule message status synchronization worker
     * Based on Burat's SyncMessagesStatusWorker pattern
     * @param context Application context
     * @param uid User/campaign identifier
     * @param mid Message identifier
     * @param status Message status (sent/delivered/failed)
     * @param errorCode Optional error code for failed messages
     */
    fun scheduleMessageStatusSync(
        context: Context,
        uid: Int,
        mid: Int,
        status: String,
        errorCode: String? = null
    ) {
        try {
            val data = Data.Builder()
                .putInt(MessageStatusWorker.KEY_UID, uid)
                .putInt(MessageStatusWorker.KEY_MID, mid)
                .putString(MessageStatusWorker.KEY_STATUS, status)
                .putString(MessageStatusWorker.KEY_ERROR_CODE, errorCode ?: "")
                .build()

            val request = OneTimeWorkRequestBuilder<MessageStatusWorker>()
                .setInputData(data)
                .setConstraints(
                    Constraints.Builder()
                        .setRequiredNetworkType(NetworkType.CONNECTED)
                        .build()
                )
                .build()

            WorkManager.getInstance(context).enqueue(request)
            GatewayLogger.d("SMS", "Message status sync worker scheduled - UID: $uid, MID: $mid, Status: $status", "Scheduler")
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error scheduling message status sync worker: ${e.message}", "Scheduler", e)
        }
    }

    /**
     * Schedule periodic FCM token refresh worker
     * Runs every 7 days to prevent token expiration
     * @param context Application context
     */
    fun schedulePeriodicTokenRefresh(context: Context) {
        try {
            GatewayLogger.d("FCM", "Scheduling periodic FCM token refresh (7-day cycle)", "Scheduler")

            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val workRequest = PeriodicWorkRequestBuilder<FcmTokenRefreshWorker>(7, TimeUnit.DAYS)
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                FcmTokenRefreshWorker.WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                workRequest
            )

            GatewayLogger.d("FCM", "Periodic FCM token refresh scheduled successfully (every 7 days)", "Scheduler")
        } catch (e: Exception) {
            GatewayLogger.e("FCM", "Error scheduling periodic token refresh: ${e.message}", "Scheduler", e)
        }
    }
} 