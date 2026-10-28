package dev.titansys.smsgateway.sms

import android.app.ActivityManager
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.content.ContextCompat
import dev.titansys.smsgateway.services.MessageQueueService
import android.app.role.RoleManager
import dev.titansys.smsgateway.helpers.GatewayLogger
import android.provider.Telephony
import dev.titansys.smsgateway.helpers.ScheduledTaskManager

object SmsServiceManager {
    private const val TAG = "SmsServiceManager"
    private const val CHANNEL_ID = "message_sending_channel"

    fun startMessageQueueService(context: Context) {
        if (!isServiceActuallyRunning(context)) {
            GatewayLogger.d("SMS", "Starting MessageQueueService", "ServiceManager")
            val intent = Intent(context, MessageQueueService::class.java)
            ContextCompat.startForegroundService(context, intent)
        } else {
            GatewayLogger.d("SMS", "MessageQueueService is already running, skipping start", "ServiceManager")
        }
    }

    fun stopMessageQueueService(context: Context) {
        GatewayLogger.d("SMS", "Attempting to stop MessageQueueService", "ServiceManager")
        val intent = Intent(context, MessageQueueService::class.java)
        context.stopService(intent)
        
        // Force clear any stale notifications if service stops
        clearServiceNotification(context)
    }

    /**
     * Check if the MessageQueueService is actually running by checking both
     * the system's running services and active notifications
     */
    fun isServiceActuallyRunning(context: Context): Boolean {
        // First check if service is running in ActivityManager
        val activityManager = context.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
        val runningServices = activityManager.getRunningServices(Integer.MAX_VALUE)
        
        val serviceClassName = MessageQueueService::class.java.name
        val isServiceRunning = runningServices.any { service ->
            service.service.className == serviceClassName
        }
        
        // Also check for active notifications as a secondary verification
        val hasActiveNotification = isNotificationChannelActive(context)
        
        GatewayLogger.d("SMS", "Service running in ActivityManager: $isServiceRunning", "ServiceManager")
        GatewayLogger.d("SMS", "Has active notification: $hasActiveNotification", "ServiceManager")
        
        // Service is running if it's in ActivityManager OR has active notification
        return isServiceRunning || hasActiveNotification
    }

    /**
     * Force clear the service notification - useful when service is stopped
     */
    private fun clearServiceNotification(context: Context) {
        try {
            val notificationManager = context.getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
            notificationManager.cancel(2) // Using the same NOTIFICATION_ID as in the service
            GatewayLogger.d("SMS", "Cleared service notification", "ServiceManager")
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error clearing service notification: ${e.message}", "ServiceManager", e)
        }
    }

    @Deprecated("Use isServiceActuallyRunning instead")
    fun isMessageQueueServiceRunning(): Boolean {
        GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_deprecated_function), "ServiceManager")
        return false // Always return false to force proper checking
    }
    
    private fun isNotificationChannelActive(context: Context): Boolean {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val notificationManager = context.getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
            val channel = notificationManager.getNotificationChannel(CHANNEL_ID)
            
            // Check if the channel exists and has notifications
            if (channel != null) {
                val activeNotifications = notificationManager.activeNotifications
                for (notification in activeNotifications) {
                    if (notification.notification.channelId == CHANNEL_ID) {
                        GatewayLogger.d("SMS", "Found active notification for MessageQueueService", "ServiceManager")
                        return true
                    }
                }
            }
        }
        return false
    }

    /**
     * Check if the app is set as the default SMS app
     * @param context The application context
     * @return True if the app is the default SMS app, false otherwise
     */
    fun isDefaultSmsApp(context: Context): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val roleManager = context.getSystemService(RoleManager::class.java)
            roleManager?.isRoleAvailable(RoleManager.ROLE_SMS) == true && 
            roleManager.isRoleHeld(RoleManager.ROLE_SMS)
        } else {
            Telephony.Sms.getDefaultSmsPackage(context) == context.packageName
        }
    }

    /**
     * Start periodic scheduling of MessageQueueService every 5 minutes
     * @param context Application context
     */
    fun startPeriodicScheduling(context: Context) {
        ScheduledTaskManager.startPeriodicScheduling(context)
    }

    /**
     * Stop periodic scheduling of MessageQueueService
     * @param context Application context
     */
    fun stopPeriodicScheduling(context: Context) {
        ScheduledTaskManager.stopPeriodicScheduling(context)
    }

    /**
     * Check if periodic scheduling is currently active
     * @param context Application context
     * @return True if periodic scheduling is active, false otherwise
     */
    fun isPeriodicSchedulingActive(context: Context): Boolean {
        return ScheduledTaskManager.isPeriodicSchedulingActive(context)
    }
}