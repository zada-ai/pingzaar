package dev.titansys.smsgateway.helpers

import android.app.ActivityManager
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.content.ContextCompat
import dev.titansys.smsgateway.services.UssdQueueService
import dev.titansys.smsgateway.helpers.GatewayLogger

object UssdManager {
    private const val TAG = "UssdManager"
    private const val CHANNEL_ID = "ussd_sending_channel"

    fun startUssdService(context: Context) {
        if (!ServiceStateManager.isUssdServiceRunning(context) && !isNotificationChannelActive(context)) {
            GatewayLogger.d("USSD", "Starting UssdQueueService", "Manager")
            val intent = Intent(context, UssdQueueService::class.java)
            ContextCompat.startForegroundService(context, intent)
        } else {
            GatewayLogger.d("USSD", "UssdQueueService is already running, skipping start", "Manager")
        }
    }

    fun stopUssdService(context: Context) {
        if (ServiceStateManager.isUssdServiceRunning(context)) {
            GatewayLogger.d("USSD", "Stopping UssdQueueService", "Manager")
            val intent = Intent(context, UssdQueueService::class.java)
            context.stopService(intent)
        } else {
            GatewayLogger.d("USSD", "UssdQueueService is not running, skipping stop", "Manager")
        }
    }

    fun isUssdServiceRunning(context: Context): Boolean {
        return ServiceStateManager.isUssdServiceRunning(context)
    }

    @Deprecated("Use ServiceStateManager.setUssdServiceRunning() instead")
    fun setServiceRunning(running: Boolean) {
        GatewayLogger.d("USSD", "Deprecated method called. Use ServiceStateManager.setUssdServiceRunning() instead", "Manager")
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
                        GatewayLogger.d("USSD", "Found active notification for UssdQueueService", "Manager")
                        return true
                    }
                }
            }
        }
        return false
    }
} 