package dev.titansys.smsgateway.ui.notifications

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build

/**
 * Manages notification channels for different service types
 */
class NotificationChannelManager(private val context: Context) {
    companion object {
        // Notification channels
        const val SMS_CHANNEL_ID = "message_sending_channel"
        const val USSD_CHANNEL_ID = "ussd_sending_channel"
        const val UNIFIED_CHANNEL_ID = "unified_processing_channel"
    }

    fun createAllNotificationChannels() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val notificationManager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

            // SMS Channel
            val smsChannel = NotificationChannel(
                SMS_CHANNEL_ID,
                "SMS Sending",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Notifications for SMS sending progress"
                setShowBadge(false)
                enableLights(false)
                enableVibration(false)
            }

            // USSD Channel
            val ussdChannel = NotificationChannel(
                USSD_CHANNEL_ID,
                "USSD Sending",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Notifications for USSD sending progress"
                setShowBadge(false)
                enableLights(false)
                enableVibration(false)
            }

            // Unified Processing Channel
            val unifiedChannel = NotificationChannel(
                UNIFIED_CHANNEL_ID,
                "Message Processing",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Unified notifications for all message processing activities"
                setShowBadge(false)
                enableLights(false)
                enableVibration(false)
            }

            notificationManager.createNotificationChannels(listOf(smsChannel, ussdChannel, unifiedChannel))
        }
    }
}