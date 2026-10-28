package dev.titansys.smsgateway.helpers

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.graphics.BitmapFactory
import android.os.Build
import dev.titansys.smsgateway.helpers.GatewayLogger
import androidx.core.app.NotificationCompat
import dev.titansys.smsgateway.R
import dev.titansys.smsgateway.activities.PermissionSetupActivity
import kotlin.math.roundToInt

/**
 * Enhanced notification helper for unified SMS and USSD processing progress tracking.
 * Provides sophisticated progress indicators, time estimates, and queue statistics.
 */
class NotificationManager(private val context: Context) {
    companion object {
        private const val TAG = "NotificationManager"

        // Notification channels
        private const val SMS_CHANNEL_ID = "message_sending_channel"
        private const val USSD_CHANNEL_ID = "ussd_sending_channel"
        private const val UNIFIED_CHANNEL_ID = "unified_processing_channel"

        // Notification IDs
        const val SMS_NOTIFICATION_ID = 2
        const val USSD_NOTIFICATION_ID = 3
        const val UNIFIED_NOTIFICATION_ID = 4

        // Throttling configuration
        // Android enforces ~6-8 notifications/second limit
        // We use 500ms (2 notifications/second max) for safety margin
        private const val MIN_NOTIFICATION_INTERVAL_MS = 500L

        // Service types
        enum class ServiceType {
            SMS, USSD, UNIFIED
        }

        // Processing stages
        enum class ProcessingStage {
            INITIALIZING, QUEUING, PROCESSING_PRIORITY, PROCESSING_STANDARD, PROCESSING_INDEFINITE, COMPLETING, ERROR
        }
    }

    /**
     * Progress information for notification updates
     */
    data class ProgressInfo(
        val current: Int = 0,
        val total: Int = 0,
        val stage: ProcessingStage = ProcessingStage.INITIALIZING,
        val queueStats: QueueStats = QueueStats(),
        val processingRate: Double = 0.0,
        val estimatedTimeRemaining: Long = 0L,
        val lastProcessedItem: String? = null,
        val errorCount: Int = 0
    )

    /**
     * Queue statistics for notification display
     */
    data class QueueStats(
        val priorityItems: Int = 0,
        val standardItems: Int = 0,
        val processingItems: Int = 0,
        val completedItems: Int = 0,
        val failedItems: Int = 0
    ) {
        val totalPending: Int get() = priorityItems + standardItems
        val totalProcessed: Int get() = completedItems + failedItems
        val totalItems: Int get() = totalPending + processingItems + totalProcessed
    }

    /**
     * Performance tracking for rate calculation
     */
    private data class PerformanceTracker(
        var startTime: Long = System.currentTimeMillis(),
        var itemsProcessed: Int = 0,
        var lastUpdateTime: Long = System.currentTimeMillis()
    )

    /**
     * Data class for pending notification updates
     */
    private data class PendingNotificationUpdate(
        val progressInfo: ProgressInfo,
        val customTitle: String?,
        val customMessage: String?,
        val timestamp: Long
    )

    private val performanceTrackers = mutableMapOf<ServiceType, PerformanceTracker>()

    // Throttling state tracking
    private val lastNotificationTimes = mutableMapOf<ServiceType, Long>()
    private val pendingNotifications = mutableMapOf<ServiceType, PendingNotificationUpdate?>()
    private val throttleHandler = android.os.Handler(android.os.Looper.getMainLooper())
    private val pendingRunnables = mutableMapOf<ServiceType, Runnable>()

    init {
        createAllNotificationChannels()
    }

    /**
     * Create all notification channels
     */
    private fun createAllNotificationChannels() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val notificationManager = context.getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager

            // SMS Channel
            val smsChannel = NotificationChannel(
                SMS_CHANNEL_ID,
                context.getString(dev.titansys.smsgateway.R.string.channel_sms_processing),
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = context.getString(dev.titansys.smsgateway.R.string.channel_sms_processing_desc)
                enableLights(false)
                enableVibration(false)
                setShowBadge(false)
            }

            // USSD Channel
            val ussdChannel = NotificationChannel(
                USSD_CHANNEL_ID,
                context.getString(dev.titansys.smsgateway.R.string.channel_ussd_processing),
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = context.getString(dev.titansys.smsgateway.R.string.channel_ussd_processing_desc)
                enableLights(false)
                enableVibration(false)
                setShowBadge(false)
            }

            // Unified Channel
            val unifiedChannel = NotificationChannel(
                UNIFIED_CHANNEL_ID,
                context.getString(dev.titansys.smsgateway.R.string.channel_unified_processing),
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = context.getString(dev.titansys.smsgateway.R.string.channel_unified_processing_desc)
                enableLights(false)
                enableVibration(false)
                setShowBadge(false)
            }

            notificationManager.createNotificationChannels(listOf(smsChannel, ussdChannel, unifiedChannel))
            GatewayLogger.d("UI", "Created all notification channels successfully", "NotificationManager")
        }
    }

    /**
     * Create enhanced notification with progress tracking
     */
    fun createEnhancedNotification(
        serviceType: ServiceType,
        progressInfo: ProgressInfo,
        customTitle: String? = null,
        customMessage: String? = null
    ): Notification {
        val channelId = when (serviceType) {
            ServiceType.SMS -> SMS_CHANNEL_ID
            ServiceType.USSD -> USSD_CHANNEL_ID
            ServiceType.UNIFIED -> UNIFIED_CHANNEL_ID
        }

        val title = customTitle ?: getDefaultTitle(serviceType, progressInfo)
        val message = customMessage ?: generateProgressMessage(progressInfo)

        val notificationIntent = Intent(context, PermissionSetupActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(
            context,
            0,
            notificationIntent,
            PendingIntent.FLAG_IMMUTABLE
        )

        val builder = NotificationCompat.Builder(context, channelId)
            .setContentTitle(title)
            .setContentText(message)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentIntent(pendingIntent)
            .setLargeIcon(BitmapFactory.decodeResource(context.resources, R.mipmap.ic_launcher))
            .setColor(context.getColor(R.color.colorPrimary))
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setOnlyAlertOnce(true)

        // Add progress bar
        when (progressInfo.stage) {
            ProcessingStage.INITIALIZING -> {
                builder.setProgress(0, 0, true) // Indeterminate
            }
            ProcessingStage.PROCESSING_INDEFINITE -> {
                builder.setProgress(0, 0, true) // Indeterminate
            }
            ProcessingStage.ERROR -> {
                builder.setProgress(100, 100, false) // Full with error color
            }
            else -> {
                if (progressInfo.total > 0) {
                    val progressPercentage = ((progressInfo.current.toDouble() / progressInfo.total) * 100).roundToInt()
                    builder.setProgress(100, progressPercentage, false)
                } else {
                    builder.setProgress(0, 0, true) // Indeterminate
                }
            }
        }

        // Add expanded information
        val expandedText = generateExpandedMessage(progressInfo, serviceType)
        builder.setStyle(NotificationCompat.BigTextStyle().bigText(expandedText))

        // Add subtext with queue stats
        val queueInfo = context.getString(dev.titansys.smsgateway.R.string.notification_queue_subtext, progressInfo.queueStats.totalPending, progressInfo.queueStats.totalProcessed)
        builder.setSubText(queueInfo)

        return builder.build()
    }

    /**
     * Update notification with built-in throttling to prevent Android rate limiting.
     *
     * Throttling strategy:
     * - If last update was < 500ms ago: Queue update for later
     * - If last update was >= 500ms ago: Update immediately
     * - Queued updates are collapsed (only latest is kept)
     *
     * This ensures we never exceed 2 notifications/second per service.
     */
    fun updateNotification(
        serviceType: ServiceType,
        progressInfo: ProgressInfo,
        customTitle: String? = null,
        customMessage: String? = null
    ) {
        val currentTime = System.currentTimeMillis()
        val lastUpdateTime = lastNotificationTimes[serviceType] ?: 0L
        val timeSinceLastUpdate = currentTime - lastUpdateTime

        // Check if we should throttle
        if (timeSinceLastUpdate < MIN_NOTIFICATION_INTERVAL_MS) {
            // Store pending update (overwrites any previous pending update)
            val pendingUpdate = PendingNotificationUpdate(
                progressInfo = progressInfo,
                customTitle = customTitle,
                customMessage = customMessage,
                timestamp = currentTime
            )
            pendingNotifications[serviceType] = pendingUpdate

            // Remove any previously scheduled update for this service
            pendingRunnables[serviceType]?.let { throttleHandler.removeCallbacks(it) }

            // Schedule update to run when throttle interval elapses
            val delayMs = MIN_NOTIFICATION_INTERVAL_MS - timeSinceLastUpdate
            val runnable = Runnable {
                // Apply the pending update (if still exists)
                pendingNotifications.remove(serviceType)?.let { pending ->
                    performNotificationUpdate(
                        serviceType,
                        pending.progressInfo,
                        pending.customTitle,
                        pending.customMessage
                    )
                }
                pendingRunnables.remove(serviceType)
            }
            pendingRunnables[serviceType] = runnable
            throttleHandler.postDelayed(runnable, delayMs)

            GatewayLogger.d(
                "UI",
                "Throttled ${serviceType.name} notification (${timeSinceLastUpdate}ms since last, delayed ${delayMs}ms)",
                TAG
            )
            return
        }

        // Enough time elapsed, update immediately
        performNotificationUpdate(serviceType, progressInfo, customTitle, customMessage)
    }

    /**
     * Perform immediate notification update (internal method).
     * Called either immediately or after throttle delay.
     */
    private fun performNotificationUpdate(
        serviceType: ServiceType,
        progressInfo: ProgressInfo,
        customTitle: String?,
        customMessage: String?
    ) {
        // Update performance tracking
        updatePerformanceTracking(serviceType, progressInfo)

        val notificationId = when (serviceType) {
            ServiceType.SMS -> SMS_NOTIFICATION_ID
            ServiceType.USSD -> USSD_NOTIFICATION_ID
            ServiceType.UNIFIED -> UNIFIED_NOTIFICATION_ID
        }

        // Create and post notification
        val notification = createEnhancedNotification(serviceType, progressInfo, customTitle, customMessage)
        val notificationManager = context.getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
        notificationManager.notify(notificationId, notification)

        // Record this update time
        lastNotificationTimes[serviceType] = System.currentTimeMillis()

        GatewayLogger.d(
            "UI",
            "Updated ${serviceType.name} notification: ${progressInfo.current}/${progressInfo.total} (${progressInfo.stage})",
            TAG
        )
    }

    /**
     * Cancel notification for a service and clean up all state
     */
    fun cancelNotification(serviceType: ServiceType) {
        val notificationId = when (serviceType) {
            ServiceType.SMS -> SMS_NOTIFICATION_ID
            ServiceType.USSD -> USSD_NOTIFICATION_ID
            ServiceType.UNIFIED -> UNIFIED_NOTIFICATION_ID
        }

        val notificationManager = context.getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
        notificationManager.cancel(notificationId)

        // Clean up throttling state
        lastNotificationTimes.remove(serviceType)
        pendingNotifications.remove(serviceType)
        pendingRunnables[serviceType]?.let { throttleHandler.removeCallbacks(it) }
        pendingRunnables.remove(serviceType)

        // Clean up performance tracking
        performanceTrackers.remove(serviceType)

        GatewayLogger.d("UI", "Cancelled ${serviceType.name} notification and cleaned up state", TAG)
    }

    /**
     * Get default title based on service type and progress
     */
    private fun getDefaultTitle(serviceType: ServiceType, progressInfo: ProgressInfo): String {
        val baseTitle = when (serviceType) {
            ServiceType.SMS -> context.getString(dev.titansys.smsgateway.R.string.notification_sms_service)
            ServiceType.USSD -> context.getString(dev.titansys.smsgateway.R.string.notification_ussd_service)
            ServiceType.UNIFIED -> context.getString(dev.titansys.smsgateway.R.string.notification_message_service)
        }

        return when (progressInfo.stage) {
            ProcessingStage.INITIALIZING -> context.getString(dev.titansys.smsgateway.R.string.notification_initializing, baseTitle)
            ProcessingStage.QUEUING -> context.getString(dev.titansys.smsgateway.R.string.notification_queuing, baseTitle)
            ProcessingStage.PROCESSING_PRIORITY -> context.getString(dev.titansys.smsgateway.R.string.notification_priority_queue, baseTitle)
            ProcessingStage.PROCESSING_STANDARD -> context.getString(dev.titansys.smsgateway.R.string.notification_standard_queue, baseTitle)
            ProcessingStage.PROCESSING_INDEFINITE -> context.getString(dev.titansys.smsgateway.R.string.notification_processing, baseTitle)
            ProcessingStage.COMPLETING -> context.getString(dev.titansys.smsgateway.R.string.notification_completing, baseTitle)
            ProcessingStage.ERROR -> context.getString(dev.titansys.smsgateway.R.string.notification_error, baseTitle)
        }
    }

    /**
     * Generate progress message with current status
     */
    private fun generateProgressMessage(progressInfo: ProgressInfo): String {
        return when (progressInfo.stage) {
            ProcessingStage.INITIALIZING -> context.getString(dev.titansys.smsgateway.R.string.notification_starting_up)
            ProcessingStage.QUEUING -> context.getString(dev.titansys.smsgateway.R.string.notification_organizing, progressInfo.total)
            ProcessingStage.PROCESSING_PRIORITY -> {
                if (progressInfo.total > 0) {
                    val percentage = ((progressInfo.current.toDouble() / progressInfo.total) * 100).roundToInt()
                    context.getString(dev.titansys.smsgateway.R.string.notification_processing_priority_progress, percentage, progressInfo.current, progressInfo.total)
                } else {
                    context.getString(dev.titansys.smsgateway.R.string.notification_processing_priority)
                }
            }
            ProcessingStage.PROCESSING_STANDARD -> {
                if (progressInfo.total > 0) {
                    val percentage = ((progressInfo.current.toDouble() / progressInfo.total) * 100).roundToInt()
                    context.getString(dev.titansys.smsgateway.R.string.notification_processing_standard_progress, percentage, progressInfo.current, progressInfo.total)
                } else {
                    context.getString(dev.titansys.smsgateway.R.string.notification_processing_standard)
                }
            }
            ProcessingStage.PROCESSING_INDEFINITE -> context.getString(dev.titansys.smsgateway.R.string.notification_processing_ussd)
            ProcessingStage.COMPLETING -> context.getString(dev.titansys.smsgateway.R.string.notification_finishing)
            ProcessingStage.ERROR -> context.getString(dev.titansys.smsgateway.R.string.notification_error_occurred)
        }
    }

    /**
     * Generate expanded message with detailed information
     */
    private fun generateExpandedMessage(progressInfo: ProgressInfo, serviceType: ServiceType): String {
        val sb = StringBuilder()

        // Basic progress info
        if (progressInfo.total > 0) {
            val percentage = ((progressInfo.current.toDouble() / progressInfo.total) * 100).roundToInt()
            sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_progress, progressInfo.current, progressInfo.total, percentage))
        }

        // Queue statistics
        with(progressInfo.queueStats) {
            if (totalItems > 0) {
                sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_queue_status))
                if (priorityItems > 0) sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_priority_count, priorityItems))
                if (standardItems > 0) sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_standard_count, standardItems))
                if (processingItems > 0) sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_processing_count, processingItems))
                if (completedItems > 0) sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_completed_count, completedItems))
                if (failedItems > 0) sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_failed_count, failedItems))
            }
        }

        // Performance info
        val tracker = performanceTrackers[serviceType]
        if (tracker != null && progressInfo.processingRate > 0) {
            sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_performance))
            sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_rate, progressInfo.processingRate))

            if (progressInfo.estimatedTimeRemaining > 0) {
                val timeStr = formatDuration(progressInfo.estimatedTimeRemaining)
                sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_time_remaining, timeStr))
            }
        }

        // Last processed item
        progressInfo.lastProcessedItem?.let {
            sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_last_processed, it))
        }

        // Error count
        if (progressInfo.errorCount > 0) {
            sb.appendLine(context.getString(dev.titansys.smsgateway.R.string.notification_errors_encountered, progressInfo.errorCount))
        }

        return sb.toString().trim()
    }

    /**
     * Update performance tracking for rate calculation
     */
    private fun updatePerformanceTracking(serviceType: ServiceType, progressInfo: ProgressInfo) {
        val tracker = performanceTrackers.getOrPut(serviceType) { PerformanceTracker() }

        val currentTime = System.currentTimeMillis()
        val elapsedMinutes = (currentTime - tracker.startTime) / 60000.0

        if (elapsedMinutes > 0) {
            val currentRate = progressInfo.current / elapsedMinutes

            // Update tracker
            tracker.itemsProcessed = progressInfo.current
            tracker.lastUpdateTime = currentTime

            // Calculate estimated time remaining
            val remainingItems = progressInfo.total - progressInfo.current
            if (currentRate > 0 && remainingItems > 0) {
                val estimatedMinutes = remainingItems / currentRate
                // Store estimated time in progressInfo (this would need to be modified in the calling code)
            }
        }
    }

    /**
     * Format duration in milliseconds to human-readable string
     */
    private fun formatDuration(durationMs: Long): String {
        val seconds = durationMs / 1000
        return when {
            seconds < 60 -> "${seconds}s"
            seconds < 3600 -> "${seconds / 60}m ${seconds % 60}s"
            else -> "${seconds / 3600}h ${(seconds % 3600) / 60}m"
        }
    }

    /**
     * Create progress info from basic parameters
     */
    fun createProgressInfo(
        current: Int,
        total: Int,
        stage: ProcessingStage,
        priorityCount: Int = 0,
        standardCount: Int = 0,
        completedCount: Int = 0,
        failedCount: Int = 0,
        lastItem: String? = null
    ): ProgressInfo {
        return ProgressInfo(
            current = current,
            total = total,
            stage = stage,
            queueStats = QueueStats(
                priorityItems = priorityCount,
                standardItems = standardCount,
                processingItems = if (current < total) 1 else 0,
                completedItems = completedCount,
                failedItems = failedCount
            ),
            lastProcessedItem = lastItem
        )
    }

    /**
     * Get processing statistics for monitoring
     */
    fun getProcessingStatistics(serviceType: ServiceType): Map<String, Any> {
        val tracker = performanceTrackers[serviceType]
        return if (tracker != null) {
            val elapsedTime = System.currentTimeMillis() - tracker.startTime
            val rate = if (elapsedTime > 0) tracker.itemsProcessed / (elapsedTime / 60000.0) else 0.0

            mapOf(
                "elapsedTimeMs" to elapsedTime,
                "itemsProcessed" to tracker.itemsProcessed,
                "processingRatePerMinute" to rate,
                "startTime" to tracker.startTime,
                "lastUpdateTime" to tracker.lastUpdateTime
            )
        } else {
            emptyMap()
        }
    }
}