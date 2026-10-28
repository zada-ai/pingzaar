package dev.titansys.smsgateway.sms.receivers

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.sms.SmsServiceManager
import dev.titansys.smsgateway.helpers.SessionManager
import java.util.Date

class ScheduledSmsReceiver : BroadcastReceiver() {
    companion object {
        private const val TAG = "ScheduledSmsReceiver"
        private const val ACTION_PERIODIC_MESSAGE = "dev.titansys.smsgateway.PERIODIC_MESSAGE"
        private const val REQUEST_CODE = 2001
        private const val INTERVAL_1_MINUTE = 1 * 60 * 1000L // 1 minute in milliseconds
    }

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == ACTION_PERIODIC_MESSAGE) {
            GatewayLogger.d("SMS", "Received periodic message broadcast", "ScheduledReceiver")
            
            // Check if SMS is enabled before starting service
            val sessionManager = SessionManager(context)
            if (sessionManager.getSmsEnabled()) {
                GatewayLogger.d("SMS", "SMS is enabled, starting SmsQueueService", "ScheduledReceiver")
                SmsServiceManager.startMessageQueueService(context)

                // CRITICAL: Reschedule next alarm immediately for continuous operation
                GatewayLogger.d("SMS", "Rescheduling next alarm for continuous operation", "ScheduledReceiver")
                schedulePeriodicExecution(context)
            } else {
                GatewayLogger.d("SMS", "SMS is disabled, skipping SmsQueueService start", "ScheduledReceiver")
                // Still reschedule to check again later, even if SMS is disabled
                schedulePeriodicExecution(context)
            }
        }
    }

    /**
     * Schedule periodic execution of SmsQueueService every 1 minute
     */
    fun schedulePeriodicExecution(context: Context) {
        val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        val intent = Intent(context, ScheduledSmsReceiver::class.java).apply {
            action = ACTION_PERIODIC_MESSAGE
        }
        
        val pendingIntent = PendingIntent.getBroadcast(
            context,
            REQUEST_CODE,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        // Cancel any existing alarm first
        alarmManager.cancel(pendingIntent)

        // Schedule doze-compatible single alarm (will self-reschedule)
        val triggerTime = System.currentTimeMillis() + INTERVAL_1_MINUTE

        when {
            // Strategy 1: setExactAndAllowWhileIdle for Android M+ (doze-compatible)
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.M -> {
                try {
                    alarmManager.setExactAndAllowWhileIdle(
                        AlarmManager.RTC_WAKEUP,
                        triggerTime,
                        pendingIntent
                    )
                    GatewayLogger.d("SMS", "Scheduled doze-compatible alarm with setExactAndAllowWhileIdle", "ScheduledReceiver")
                } catch (e: Exception) {
                    // Fallback to setExact if setExactAndAllowWhileIdle fails
                    alarmManager.setExact(AlarmManager.RTC_WAKEUP, triggerTime, pendingIntent)
                    GatewayLogger.d("SMS", "Fallback to setExact due to: ${e.message}", "ScheduledReceiver")
                }
            }
            // Strategy 2: setExact for Android KitKat+
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.KITKAT -> {
                alarmManager.setExact(AlarmManager.RTC_WAKEUP, triggerTime, pendingIntent)
                GatewayLogger.d("SMS", "Scheduled with setExact (precision timing)", "ScheduledReceiver")
            }
            // Strategy 3: Basic set for older versions
            else -> {
                alarmManager.set(AlarmManager.RTC_WAKEUP, triggerTime, pendingIntent)
                GatewayLogger.d("SMS", "Scheduled with basic set (legacy compatibility)", "ScheduledReceiver")
            }
        }
        
        GatewayLogger.d("SMS", "Scheduled self-rescheduling SmsQueueService alarm for ${java.util.Date(triggerTime)}", "ScheduledReceiver")
    }

    /**
     * Cancel the periodic execution
     */
    fun cancelPeriodicExecution(context: Context) {
        val alarmManager = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        val intent = Intent(context, ScheduledSmsReceiver::class.java).apply {
            action = ACTION_PERIODIC_MESSAGE
        }
        
        val pendingIntent = PendingIntent.getBroadcast(
            context,
            REQUEST_CODE,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        
        alarmManager.cancel(pendingIntent)
        GatewayLogger.d("SMS", "Cancelled periodic SmsQueueService execution", "ScheduledReceiver")
    }

    /**
     * Check if periodic execution is currently scheduled
     */
    fun isPeriodicExecutionScheduled(context: Context): Boolean {
        val intent = Intent(context, ScheduledSmsReceiver::class.java).apply {
            action = ACTION_PERIODIC_MESSAGE
        }
        
        val pendingIntent = PendingIntent.getBroadcast(
            context,
            REQUEST_CODE,
            intent,
            PendingIntent.FLAG_NO_CREATE or PendingIntent.FLAG_IMMUTABLE
        )
        
        return pendingIntent != null
    }
} 