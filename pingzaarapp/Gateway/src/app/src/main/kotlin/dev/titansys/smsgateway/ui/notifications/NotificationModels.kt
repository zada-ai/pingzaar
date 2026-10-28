package dev.titansys.smsgateway.ui.notifications

/**
 * Data models and enums for notification system
 */

// Notification IDs
object NotificationIds {
    const val SMS_NOTIFICATION_ID = 2
    const val USSD_NOTIFICATION_ID = 3
    const val UNIFIED_NOTIFICATION_ID = 4
}

// Service types
enum class ServiceType {
    SMS, USSD, UNIFIED
}

// Processing stages
enum class ProcessingStage {
    INITIALIZING, QUEUING, PROCESSING_PRIORITY, PROCESSING_STANDARD, COMPLETING, ERROR
}

/**
 * Progress information for notification updates
 */
data class ProgressInfo(
    val current: Int = 0,
    val total: Int = 0,
    val processed: Int = 0,
    val failed: Int = 0,
    val retrying: Int = 0,
    val stage: ProcessingStage = ProcessingStage.INITIALIZING,
    val currentAction: String = "",
    val startTime: Long = System.currentTimeMillis(),
    val isCompleted: Boolean = false,
    val hasErrors: Boolean = false
)

/**
 * Queue statistics for detailed progress tracking
 */
data class QueueStats(
    val pendingItems: Int = 0,
    val processingItems: Int = 0,
    val completedItems: Int = 0,
    val failedItems: Int = 0,
    val retryingItems: Int = 0,
    val estimatedTimeRemaining: Long = 0,
    val averageProcessingTime: Long = 0,
    val successRate: Double = 0.0
)

/**
 * Performance tracking data
 */
internal data class PerformanceTracker(
    var totalProcessed: Int = 0,
    var totalFailed: Int = 0,
    var processingStartTime: Long = 0,
    var lastUpdateTime: Long = 0,
    var processingTimes: MutableList<Long> = mutableListOf(),
    var cumulativeTime: Long = 0
) {
    fun reset() {
        totalProcessed = 0
        totalFailed = 0
        processingStartTime = System.currentTimeMillis()
        lastUpdateTime = processingStartTime
        processingTimes.clear()
        cumulativeTime = 0
    }
}