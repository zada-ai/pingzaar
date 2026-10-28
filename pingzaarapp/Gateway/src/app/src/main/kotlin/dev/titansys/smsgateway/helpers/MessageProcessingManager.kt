package dev.titansys.smsgateway.helpers

import android.content.Context
import dev.titansys.smsgateway.activities.GatewayActivity
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.databases.SmsMessage
import dev.titansys.smsgateway.databases.UssdRequest as DbUssdRequest
import dev.titansys.smsgateway.databases.ZenderDatabase

/**
 * Unified processing helper for both SMS and USSD messages with priority support.
 * Provides common functionality for priority queue management and processing logic.
 */
class MessageProcessingManager(private val context: Context) {
    companion object {
        private const val TAG = "MessageProcessingManager"

        // Priority levels (0-10, where 10 is highest priority)
        const val PRIORITY_HIGH_MIN = 8      // High priority (8-10)
        const val PRIORITY_STANDARD_MAX = 7  // Standard priority (0-7)
    }

    /**
     * Base interface for processable items (SMS and USSD)
     */
    interface ProcessableItem {
        val id: Int
        val priority: Int
        val processed: Boolean
    }

    /**
     * Wrapper classes to make SmsMessage and UssdRequest implement ProcessableItem
     */
    data class ProcessableMessage(val message: SmsMessage) : ProcessableItem {
        override val id: Int get() = message.id
        override val priority: Int get() = message.priority
        override val processed: Boolean get() = message.isProcessed
    }

    data class ProcessableUssd(val ussd: DbUssdRequest) : ProcessableItem {
        override val id: Int get() = ussd.id
        override val priority: Int get() = ussd.priority
        override val processed: Boolean get() = ussd.isProcessed
    }

    /**
     * Priority queue system for unified processing
     */
    data class PriorityQueues<T : ProcessableItem>(
        val priorityQueue: MutableList<T> = mutableListOf(),    // High priority (8-10)
        val standardQueue: MutableList<T> = mutableListOf(),    // Standard (0-7)
        val processingQueue: MutableList<T> = mutableListOf()   // Currently processing
    ) {
        fun clear() {
            priorityQueue.clear()
            standardQueue.clear()
            processingQueue.clear()
        }

        fun getTotalCount(): Int = priorityQueue.size + standardQueue.size

        fun isEmpty(): Boolean = priorityQueue.isEmpty() && standardQueue.isEmpty()

        fun getNextItem(): T? {
            return priorityQueue.removeFirstOrNull() ?: standardQueue.removeFirstOrNull()
        }

        fun addToProcessing(item: T) {
            processingQueue.add(item)
        }

        fun removeFromProcessing(item: T) {
            processingQueue.remove(item)
        }
    }

    /**
     * Sort items into priority queues based on priority level
     */
    fun <T : ProcessableItem> sortIntoPriorityQueues(items: List<T>): PriorityQueues<T> {
        val queues = PriorityQueues<T>()

        items.forEach { item ->
            when {
                item.priority >= PRIORITY_HIGH_MIN -> queues.priorityQueue.add(item)
                else -> queues.standardQueue.add(item)
            }
        }

        GatewayLogger.d("MSG", "Sorted ${items.size} items: ${queues.priorityQueue.size} priority, ${queues.standardQueue.size} standard", "Processor")
        GatewayLogger.i("MSG", "Queued: ${queues.priorityQueue.size} priority, ${queues.standardQueue.size} standard items", "Processor")

        return queues
    }

    /**
     * Get queue statistics for monitoring
     */
    fun <T : ProcessableItem> getQueueStatistics(queues: PriorityQueues<T>): Map<String, Any> {
        return mapOf(
            "priorityQueueSize" to queues.priorityQueue.size,
            "standardQueueSize" to queues.standardQueue.size,
            "processingQueueSize" to queues.processingQueue.size,
            "totalPending" to queues.getTotalCount(),
            "isEmpty" to queues.isEmpty()
        )
    }

    /**
     * Process a queue with proper progress tracking and error handling
     */
    suspend fun <T : ProcessableItem> processQueue(
        queueName: String,
        queues: PriorityQueues<T>,
        totalItems: Int,
        currentIndex: Int,
        processFunction: suspend (T, Int, Int) -> Boolean,
        updateNotification: (String) -> Unit
    ): Int {
        var processedCount = 0
        var currentItemIndex = currentIndex

        var item = queues.getNextItem()
        while (item != null) {
            currentItemIndex++
            processedCount++

            GatewayLogger.d("MSG", "Processing $queueName item #${item.id} (${currentItemIndex}/${totalItems})", "Processor")
            updateNotification("Processing $queueName item ${currentItemIndex}/${totalItems}")

            // Add to processing queue for tracking
            queues.addToProcessing(item)

            try {
                // Process the item using the provided function
                val success = processFunction(item, currentItemIndex, totalItems)

                if (success) {
                    GatewayLogger.d("MSG", "$queueName item #${item.id} processed successfully", "Processor")
                } else {
                    GatewayLogger.w("MSG", "$queueName item #${item.id} processing failed", "Processor")
                }
            } catch (e: Exception) {
                GatewayLogger.e("MSG", "Error processing $queueName item #${item.id}: ${e.message}", "Processor", e)
            } finally {
                // Remove from processing queue when done
                queues.removeFromProcessing(item)
            }

            // Get next item
            item = queues.getNextItem()
        }

        return processedCount
    }

    /**
     * Enhanced service state management for unified processing
     */
    fun updateUnifiedServiceState(smsRunning: Boolean, ussdRunning: Boolean) {
        ServiceStateManager.setServiceRunning(context, smsRunning)
        ServiceStateManager.setUssdServiceRunning(context, ussdRunning)

        GatewayLogger.d("MSG", "Updated unified service state - SMS: $smsRunning, USSD: $ussdRunning", "Processor")

        val statusMessageResId = when {
            smsRunning && ussdRunning -> dev.titansys.smsgateway.R.string.log_services_both_running
            smsRunning -> dev.titansys.smsgateway.R.string.log_services_sms_running
            ussdRunning -> dev.titansys.smsgateway.R.string.log_services_ussd_running
            else -> dev.titansys.smsgateway.R.string.log_services_all_stopped
        }

        GatewayLogger.i("MSG", statusMessageResId, "Processor")
    }

    /**
     * Check if any unified processing is currently active
     */
    fun isAnyServiceRunning(): Boolean {
        val smsRunning = ServiceStateManager.isServiceRunning(context)
        val ussdRunning = ServiceStateManager.isUssdServiceRunning(context)
        return smsRunning || ussdRunning
    }

    /**
     * Get combined queue counts from database for monitoring
     */
    suspend fun getCombinedQueueCounts(): Map<String, Int> {
        return try {
            val database = ZenderDatabase.getInstance(context)
            val messageCount = database.messagesDao().getMessageCount()
            val ussdCount = database.ussdDao().getAllUssd().size

            mapOf(
                "messages" to messageCount,
                "ussd" to ussdCount,
                "total" to (messageCount + ussdCount)
            )
        } catch (e: Exception) {
            GatewayLogger.e("MSG", "Error getting combined queue counts: ${e.message}", "Processor", e)
            mapOf("messages" to 0, "ussd" to 0, "total" to 0)
        }
    }

    /**
     * Priority-based comparison for mixed SMS/USSD processing
     * Returns true if item1 should be processed before item2
     */
    fun <T : ProcessableItem> shouldProcessFirst(item1: T, item2: T): Boolean {
        // Higher priority number means higher priority
        if (item1.priority != item2.priority) {
            return item1.priority > item2.priority
        }

        // If same priority, process by ID (FIFO)
        return item1.id < item2.id
    }

    /**
     * Create mixed priority queue from both SMS and USSD items
     * This enables true unified processing where USSD and SMS can be interleaved by priority
     */
    suspend fun createMixedPriorityQueue(): PriorityQueues<ProcessableItem> {
        val database = ZenderDatabase.getInstance(context)

        // Get all unprocessed items
        val messages = database.messagesDao().getAllMessages().map { ProcessableMessage(it) }
        val ussdRequests = database.ussdDao().getAllUssd().map { ProcessableUssd(it) }

        // Combine and sort by priority
        val allItems = (messages + ussdRequests).sortedWith { item1, item2 ->
            when {
                item1.priority != item2.priority -> item2.priority.compareTo(item1.priority) // Higher priority first
                else -> item1.id.compareTo(item2.id) // FIFO for same priority
            }
        }

        GatewayLogger.d("MSG", "Created mixed priority queue: ${messages.size} messages, ${ussdRequests.size} USSD, ${allItems.size} total", "Processor")
        return sortIntoPriorityQueues(allItems)
    }
}