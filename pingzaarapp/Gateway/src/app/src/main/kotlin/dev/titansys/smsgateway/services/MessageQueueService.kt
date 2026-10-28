package dev.titansys.smsgateway.services

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager as AndroidNotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.graphics.BitmapFactory
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import dev.titansys.smsgateway.R
import dev.titansys.smsgateway.activities.PermissionSetupActivity
import dev.titansys.smsgateway.activities.GatewayActivity
import dev.titansys.smsgateway.databases.SmsMessage
import dev.titansys.smsgateway.databases.ZenderDatabase
import dev.titansys.smsgateway.helpers.NotificationManager
import dev.titansys.smsgateway.sms.SmsReportManager
import dev.titansys.smsgateway.sms.SmsRetryManager
import dev.titansys.smsgateway.sms.SmsServiceManager
import dev.titansys.smsgateway.helpers.ServiceStateManager
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.sms.SmsSender
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.sms.workers.SmsReportWorker
import java.util.concurrent.atomic.AtomicBoolean

class MessageQueueService : Service() {
    companion object {
        private const val CHANNEL_ID = "message_sending_channel"
        private const val NOTIFICATION_ID = 2
        private const val NOTIFICATION_TITLE = "Message Service"
        private const val TAG = "MessageQueueService"
        private const val MAX_CHUNK_DELAY = 30000L // 30 seconds max per chunk
        private const val ACTIVITY_INTERVAL = 15000L // Ping every 15 seconds during long delays

        // Campaign tracking for sequential processing (Burat's pattern)
        private val priorityCampaigns = mutableListOf<Int>() // Priority campaign IDs (8-10)
        private val standardCampaigns = mutableListOf<Int>() // Standard campaign IDs (0-7)

        // Priority queue system for intelligent message processing
        private val priorityQueue = mutableListOf<SmsMessage>() // High priority messages (8-10)
        private val standardQueue = mutableListOf<SmsMessage>() // Standard messages (0-7)
        private val processingQueue = mutableListOf<SmsMessage>() // Currently being processed

        // Concurrent processing prevention with thread-safe atomic operation
        // AtomicBoolean ensures compareAndSet is atomic (test-and-set in one operation)
        private val isProcessing = AtomicBoolean(false)
    }

    private val serviceScope = CoroutineScope(Dispatchers.IO)
    private var wakeLock: PowerManager.WakeLock? = null
    private var currentMessageIndex = 0
    private var totalMessages = 0
    private lateinit var retryHandler: SmsRetryManager
    private lateinit var enhancedNotificationHelper: NotificationManager
    private var completedMessages = 0
    private var failedMessages = 0

    override fun onCreate() {
        super.onCreate()
        GatewayLogger.d("SMS", "SmsQueueService created", "QueueService")
        createNotificationChannel()

        // Initialize components
        retryHandler = SmsRetryManager(this)
        enhancedNotificationHelper = NotificationManager(this)

        // Mark service as running for state persistence
        ServiceStateManager.setServiceRunning(this, true)

        // Acquire partial wake lock for critical operations
        val powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager
        wakeLock = powerManager.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            "ZenderApp::SmsQueueService"
        )
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        GatewayLogger.d("SMS", "QueueService") { "Service starting with startId: $startId" }

        // Prevent concurrent processing with atomic test-and-set operation
        // compareAndSet atomically checks if value is false and sets it to true
        // Returns false if already true (another thread is processing)
        if (!isProcessing.compareAndSet(false, true)) {
            GatewayLogger.d("SMS", "Already processing messages, ignoring duplicate start", "QueueService")
            return START_STICKY
        }
        // isProcessing is now atomically set to true - no race condition possible

        // Create initial enhanced notification
        val initialProgress = enhancedNotificationHelper.createProgressInfo(
            current = 0,
            total = 0,
            stage = NotificationManager.Companion.ProcessingStage.INITIALIZING
        )
        val initialNotification = enhancedNotificationHelper.createEnhancedNotification(
            NotificationManager.Companion.ServiceType.SMS,
            initialProgress
        )

        // Start foreground service with appropriate method based on Android version
        // FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING requires API 34+ (Android 14)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            GatewayLogger.d("SMS", "Starting foreground service with FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING (Android 14+)", "QueueService")
            startForeground(NOTIFICATION_ID, initialNotification, ServiceInfo.FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING)
        } else {
            GatewayLogger.d("SMS", "Starting foreground service with legacy method (Android 13 and below)", "QueueService")
            startForeground(NOTIFICATION_ID, initialNotification)
        }

        // Launch coroutine to process messages
        serviceScope.launch {
            try {
                GatewayLogger.d("SMS", "Launching message processing coroutine", "QueueService")
                processMessages()
            } finally {
                // Reset processing flag when done (atomic set operation)
                isProcessing.set(false)
            }
        }

        return START_STICKY
    }

    private fun createNotification(contentText: String = "Processing messages..."): Notification {
        GatewayLogger.d("SMS", "QueueService") { "Creating notification with text: $contentText" }
        
        val notificationIntent = Intent(this, PermissionSetupActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            notificationIntent,
            PendingIntent.FLAG_IMMUTABLE
        )

        val builder = NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(NOTIFICATION_TITLE)
            .setContentText(contentText)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentIntent(pendingIntent)
            .setLargeIcon(BitmapFactory.decodeResource(resources, R.mipmap.ic_launcher))
            .setColor(getColor(R.color.colorPrimary))
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setOnlyAlertOnce(true)

        // Add progress if we have message info
        if (totalMessages > 0) {
            builder.setProgress(totalMessages, currentMessageIndex, false)
        } else {
            builder.setProgress(0, 0, true) // Indeterminate progress
        }

        return builder.build()
    }

    private fun updateNotification(contentText: String) {
        val notification = createNotification(contentText)
        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
        notificationManager.notify(NOTIFICATION_ID, notification)
    }

    private fun createNotificationChannel() {
        GatewayLogger.d("SMS", "Creating notification channel", "QueueService")
        
        // Check if notification channels are supported (Android 8.0+)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                NOTIFICATION_TITLE,
                AndroidNotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = "Shows when message service is active"
                enableLights(false)
                enableVibration(false)
                setShowBadge(false)
            }
            
            val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
            notificationManager.createNotificationChannel(channel)
            GatewayLogger.d("SMS", "Notification channel created successfully", "QueueService")
        } else {
            GatewayLogger.d("SMS", "Notification channels not supported on this Android version", "QueueService")
        }
    }

    private suspend fun smartDelay(delayMs: Long, messageId: Int) {
        if (delayMs <= MAX_CHUNK_DELAY) {
            // Short delay - just wait normally
            delay(delayMs)
            return
        }

        // Long delay - break into chunks with periodic activity
        GatewayLogger.d("SMS", "Long delay detected (${delayMs}ms), breaking into chunks", "QueueService")
        
        // Acquire wake lock for long operations
        wakeLock?.acquire(delayMs + 60000) // Extra minute for safety
        
        var remainingDelay = delayMs
        var delayStartTime = System.currentTimeMillis()
        
        try {
            while (remainingDelay > 0) {
                val chunkDelay = minOf(ACTIVITY_INTERVAL, remainingDelay)
                val remainingSeconds = remainingDelay / 1000
                
                // Update notification with countdown
                updateNotification("Waiting ${remainingSeconds}s before next message (${currentMessageIndex}/${totalMessages})")
                
                // Wait for this chunk
                delay(chunkDelay)
                remainingDelay -= chunkDelay
                
                // Periodic "keep-alive" activities during long delays
                if (remainingDelay > 0) {
                    // Do a lightweight database operation to keep the service active
                    val database = ZenderDatabase.getInstance(this@MessageQueueService)
                    database.messagesDao().messageExists(messageId)
                    
                    // Log activity to show the service is still working
                    GatewayLogger.d("SMS", "Keep-alive ping during delay - ${remainingDelay}ms remaining", "QueueService")
                    
                    // Small delay between activity pings
                    if (remainingDelay > 1000) {
                        delay(1000)
                        remainingDelay -= 1000
                    }
                }
            }
            
            val actualDelayTime = System.currentTimeMillis() - delayStartTime
            GatewayLogger.d("SMS", "Completed smart delay. Requested: ${delayMs}ms, Actual: ${actualDelayTime}ms", "QueueService")
            
        } finally {
            // Always release wake lock
            if (wakeLock?.isHeld == true) {
                wakeLock?.release()
            }
        }
    }

    private suspend fun sendMessageWithAppropriateMethod(
        context: Context,
        uid: Int,
        mid: Int,
        recipient: String,
        message: String,
        subscriptionId: Int,
        attachment: String?
    ) {
        val isDefaultApp = SmsServiceManager.isDefaultSmsApp(context)
        GatewayLogger.d("SMS", "App is default SMS app: $isDefaultApp", "QueueService")

        val smsHelper = SmsSender(context)
        val slotIndex = subscriptionId.toString()

        try {
            // Check if MMS (Burat-style: check if attachment exists)
            val isMms = !attachment.isNullOrBlank()

            val result = if (isMms) {
                // Send MMS with text + attachment (Burat-style PDU builder)
                GatewayLogger.i("MMS", GatewayLogger.appContext.getString(R.string.log_mms_sending_with_attachment), "QueueService")

                smsHelper.sendMmsTextAndAttachment(
                    phoneNumber = recipient,
                    textMessage = message,
                    attachmentUrl = attachment!!, // Safe because isMms checks isNullOrBlank
                    slotIndex = slotIndex,
                    uid = uid,
                    mid = mid
                )
            } else {
                // Send SMS (existing logic)
                GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_sms_sending_via_sender), "QueueService")

                smsHelper.sendSms(
                    phoneNumber = recipient,
                    message = message,
                    slotIndex = slotIndex,
                    replaceLineBreaks = true
                )
            }

            // Handle result
            when (result) {
                is SmsSender.SmsResult.Success -> {
                    if (isMms) {
                        // MMS: Queued successfully, actual status reported via MmsSentReceiver
                        GatewayLogger.i("MMS", GatewayLogger.appContext.getString(R.string.log_mms_queued_awaiting_confirm), "QueueService")
                        // Don't report to server yet - let MmsSentReceiver handle it
                    } else {
                        // SMS: Report success immediately
                        GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_sms_sent_successfully), "QueueService")
                        SmsReportWorker.scheduleReport(
                            context = context,
                            uid = uid,
                            mid = mid,
                            success = true,
                            errorCode = "SMS_SENT"
                        )
                    }
                }
                is SmsSender.SmsResult.Error -> {
                    GatewayLogger.e("MSG", "QueueService") { "Failed to send message: ${result.message}" }

                    // Schedule error report sync
                    SmsReportWorker.scheduleReport(
                        context = context,
                        uid = uid,
                        mid = mid,
                        success = false,
                        errorCode = if (isMms) "MMS_FAILED" else "SMS_FAILED"
                    )
                }
            }
        } catch (e: Exception) {
            GatewayLogger.e("MSG", "QueueService", e) { "Message Error: ${e.message}" }

            // Schedule exception report sync
            SmsReportWorker.scheduleReport(
                context = context,
                uid = uid,
                mid = mid,
                success = false,
                errorCode = "MESSAGE_EXCEPTION: ${e.message}"
            )
        }
    }

    private suspend fun processMessages() {
        try {
            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_sms_fetching_queue), "QueueService")

            // Update notification to show we're fetching
            enhancedNotificationHelper.updateNotification(
                NotificationManager.Companion.ServiceType.SMS,
                enhancedNotificationHelper.createProgressInfo(
                    current = 0,
                    total = 0,
                    stage = NotificationManager.Companion.ProcessingStage.INITIALIZING
                ),
                customMessage = "Fetching messages from database..."
            )

            val database = ZenderDatabase.getInstance(this)
            val messages = database.messagesDao().getAllMessages()

            totalMessages = messages.size
            currentMessageIndex = 0
            completedMessages = 0
            failedMessages = 0

            GatewayLogger.i("SMS", "QueueService") { GatewayLogger.appContext.getString(R.string.log_sms_found_messages, messages.size) }

            // Sort messages into campaign-based queues (Burat's pattern)
            sortIntoCampaignQueues(messages)

            // Update notification with queue statistics
            enhancedNotificationHelper.updateNotification(
                NotificationManager.Companion.ServiceType.SMS,
                enhancedNotificationHelper.createProgressInfo(
                    current = 0,
                    total = totalMessages,
                    stage = NotificationManager.Companion.ProcessingStage.QUEUING,
                    priorityCount = priorityQueue.size,
                    standardCount = standardQueue.size
                )
            )

            // Reset processing counters
            currentMessageIndex = 0

            // Process priority messages first, then standard messages
            if (priorityQueue.isNotEmpty()) {
                GatewayLogger.i("SMS", "QueueService") { GatewayLogger.appContext.getString(R.string.log_sms_processing_priority, priorityQueue.size) }

                // Update notification for priority processing
                enhancedNotificationHelper.updateNotification(
                    NotificationManager.Companion.ServiceType.SMS,
                    enhancedNotificationHelper.createProgressInfo(
                        current = currentMessageIndex,
                        total = totalMessages,
                        stage = NotificationManager.Companion.ProcessingStage.PROCESSING_PRIORITY,
                        priorityCount = priorityQueue.size,
                        standardCount = standardQueue.size,
                        completedCount = completedMessages,
                        failedCount = failedMessages
                    )
                )

                processQueue("Priority") { getNextMessage() }
            }

            if (standardQueue.isNotEmpty()) {
                GatewayLogger.i("SMS", "QueueService") { GatewayLogger.appContext.getString(R.string.log_sms_processing_standard, standardQueue.size) }

                // Update notification for standard processing
                enhancedNotificationHelper.updateNotification(
                    NotificationManager.Companion.ServiceType.SMS,
                    enhancedNotificationHelper.createProgressInfo(
                        current = currentMessageIndex,
                        total = totalMessages,
                        stage = NotificationManager.Companion.ProcessingStage.PROCESSING_STANDARD,
                        priorityCount = priorityQueue.size,
                        standardCount = standardQueue.size,
                        completedCount = completedMessages,
                        failedCount = failedMessages
                    )
                )

                processQueue("Standard") { getNextMessage() }
            }

            // Update notification for completion
            enhancedNotificationHelper.updateNotification(
                NotificationManager.Companion.ServiceType.SMS,
                enhancedNotificationHelper.createProgressInfo(
                    current = totalMessages,
                    total = totalMessages,
                    stage = NotificationManager.Companion.ProcessingStage.COMPLETING,
                    completedCount = completedMessages,
                    failedCount = failedMessages
                ),
                customMessage = "Processing completed successfully"
            )

            GatewayLogger.d("SMS", "All queues processed successfully", "QueueService")
        } catch (e: Exception) {
            // Internal error while processing messages
            GatewayLogger.e("SMS", "QueueService", e) { "Unable to process request: EM1007" }

            // Update notification to show error
            enhancedNotificationHelper.updateNotification(
                NotificationManager.Companion.ServiceType.SMS,
                enhancedNotificationHelper.createProgressInfo(
                    current = currentMessageIndex,
                    total = totalMessages,
                    stage = NotificationManager.Companion.ProcessingStage.ERROR,
                    completedCount = completedMessages,
                    failedCount = failedMessages + 1
                ),
                customMessage = "Error occurred during processing"
            )

            e.printStackTrace()
        } finally {
            GatewayLogger.d("SMS", "Stopping service", "QueueService")

            // Small delay to show completion notification
            kotlinx.coroutines.delay(2000)
            stopSelf()
        }
    }

    /**
     * Sort messages into campaign-based queues for sequential processing (Burat's pattern)
     * Groups messages by campaign, then sorts campaigns by priority
     * Ensures one campaign is processed fully before starting next
     */
    private fun sortIntoCampaignQueues(messages: List<SmsMessage>) {
        // Clear all queues
        priorityQueue.clear()
        standardQueue.clear()
        processingQueue.clear()
        priorityCampaigns.clear()
        standardCampaigns.clear()

        // Group messages by campaign ID
        val messagesByCampaign = messages.groupBy { it.campaignId }

        // Sort campaigns into priority lists
        messagesByCampaign.forEach { (campaignId, campaignMessages) ->
            // Get campaign priority from first message
            val campaignPriority = campaignMessages.firstOrNull()?.priority ?: 0

            // Add campaign ID to appropriate list
            if (campaignPriority in 8..10) {
                if (campaignId !in priorityCampaigns) {
                    priorityCampaigns.add(campaignId)
                }
            } else {
                if (campaignId !in standardCampaigns) {
                    standardCampaigns.add(campaignId)
                }
            }
        }

        // Load messages for priority campaigns first
        priorityCampaigns.forEach { campaignId ->
            val campaignMessages = messagesByCampaign[campaignId] ?: emptyList()
            priorityQueue.addAll(campaignMessages)
        }

        // Then load messages for standard campaigns
        standardCampaigns.forEach { campaignId ->
            val campaignMessages = messagesByCampaign[campaignId] ?: emptyList()
            standardQueue.addAll(campaignMessages)
        }

        GatewayLogger.d("SMS", "Sorted campaigns: ${priorityCampaigns.size} priority, ${standardCampaigns.size} standard", "QueueService")
        GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_campaigns_queued, priorityCampaigns.joinToString(), standardCampaigns.joinToString()), "QueueService")
    }

    /**
     * Get the next message to process from priority queues
     * Priority messages are always processed before standard messages
     */
    private fun getNextMessage(): SmsMessage? {
        return priorityQueue.removeFirstOrNull() ?: standardQueue.removeFirstOrNull()
    }

    /**
     * Process a queue of messages with campaign tracking (Burat's pattern)
     */
    private suspend fun processQueue(queueName: String, getMessage: () -> SmsMessage?) {
        var currentCampaignId: Int? = null
        var message = getMessage()

        while (message != null) {
            // Track campaign switches (Burat's pattern)
            if (currentCampaignId != null && currentCampaignId != message.campaignId) {
                GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_switching_campaign, currentCampaignId, message.campaignId), "QueueService")
            }
            currentCampaignId = message.campaignId

            currentMessageIndex++

            GatewayLogger.d("SMS", "Processing $queueName campaign #${message.campaignId} message #${message.id} (${currentMessageIndex}/${totalMessages})", "QueueService")

            // Update notification: Every 10 messages, first message, last message, or stage changes
            // This reduces notification spam while keeping user informed
            val shouldUpdate = currentMessageIndex % 10 == 0 ||  // Every 10th message
                               currentMessageIndex == 1 ||        // First message
                               currentMessageIndex == totalMessages  // Last message

            if (shouldUpdate) {
                val currentStage = if (queueName == "Priority")
                    NotificationManager.Companion.ProcessingStage.PROCESSING_PRIORITY
                else
                    NotificationManager.Companion.ProcessingStage.PROCESSING_STANDARD

                enhancedNotificationHelper.updateNotification(
                    NotificationManager.Companion.ServiceType.SMS,
                    enhancedNotificationHelper.createProgressInfo(
                        current = currentMessageIndex,
                        total = totalMessages,
                        stage = currentStage,
                        priorityCount = priorityQueue.size,
                        standardCount = standardQueue.size,
                        completedCount = completedMessages,
                        failedCount = failedMessages,
                        lastItem = "Campaign #${message.campaignId} - Message #${message.id}"
                    )
                )
            }

            // Add to processing queue for tracking
            processingQueue.add(message)

            try {
                // Process the message using existing logic
                val success = processIndividualMessage(message)

                // Update counters based on result
                if (success) {
                    completedMessages++
                } else {
                    failedMessages++
                }
            } catch (e: Exception) {
                failedMessages++
                GatewayLogger.e("SMS", "Failed to process message #${message.id}: ${e.message}", "QueueService", e)
            } finally {
                // Remove from processing queue when done
                processingQueue.remove(message)
            }

            // Get next message
            message = getMessage()
        }

        // Log campaign completion
        if (currentCampaignId != null) {
            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_completed_campaign, currentCampaignId), "QueueService")
        }
    }

    /**
     * Process an individual message with all existing checks and logic
     * @return true if message was processed successfully, false otherwise
     */
    private suspend fun processIndividualMessage(message: SmsMessage): Boolean {
        // Check if SMS is enabled
        val sessionManager = SessionManager(this)
        if (!sessionManager.getSmsEnabled()) {
            GatewayLogger.d("SMS", "SMS is disabled, stopping service without processing messages", "QueueService")
            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_sms_feature_disabled), "QueueService")
            stopSelf()
            return false
        }

        // Check if message still exists in database
        val database = ZenderDatabase.getInstance(this)
        if (!database.messagesDao().messageExists(message.id)) {
            GatewayLogger.d("SMS", "Message #${message.id} no longer exists in database, skipping", "QueueService")
            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_message_removed, message.id), "QueueService")
            return false
        }

        // Check if message is already processed
        val currentMessage = database.messagesDao().getMessageById(message.id)
        if (currentMessage?.isProcessed == true) {
            GatewayLogger.d("SMS", "Message #${message.id} is already processed, skipping", "QueueService")
            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_message_already_processed, message.id), "QueueService")
            return true // Already processed counts as success
        }

        val randomSendEnabled = sessionManager.getRandomSend() == 1
        val randomMin = sessionManager.getRandomMin()
        val randomMax = sessionManager.getRandomMax()

        GatewayLogger.d("SMS", "Random send settings: enabled=$randomSendEnabled, min=$randomMin, max=$randomMax", "QueueService")

        // Check campaign status if message has a campaign ID
        if (message.campaignId != 0) {
            GatewayLogger.d("SMS", "Message #${message.id} belongs to campaign #${message.campaignId}", "QueueService")
            val campaign = database.campaignDao().getCampaign(message.campaignId)
            if (campaign != null && !campaign.isActive) {
                GatewayLogger.d("SMS", "Skipping message #${message.id} - Campaign #${message.campaignId} is paused", "QueueService")
                return false
            }
        } else {
            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_processing_message, message.id), "QueueService")
        }

        // Convert subscription ID to 0-based index
        val subscriptionId = message.simSlot - 1
        GatewayLogger.d("SMS", "Using subscription ID: $subscriptionId (original SIM: ${message.simSlot})", "QueueService")

        // Use retry handler to process message with intelligent retry logic
        val success = retryHandler.processMessageWithRetry(message) { messageToProcess ->
            // Send message using appropriate method based on default SMS app status
            sendMessageWithAppropriateMethod(
                context = this@MessageQueueService,
                uid = messageToProcess.userId,
                mid = messageToProcess.id,
                recipient = messageToProcess.recipientNumber,
                message = messageToProcess.messageContent,
                subscriptionId = subscriptionId,
                attachment = messageToProcess.attachment // Pass attachment from message (Burat-style)
            )

            GatewayLogger.d("SMS", "Successfully processed message #${messageToProcess.id}", "QueueService")
            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_processed_message, messageToProcess.id), "QueueService")
        }

        // Apply random delay if enabled, successful, and not the last message
        if (success && randomSendEnabled && (priorityQueue.isNotEmpty() || standardQueue.isNotEmpty())) {
            val randomDelay = (randomMin..randomMax).random() * 1000L // Convert to milliseconds
            GatewayLogger.d("SMS", "Applying random delay of ${randomDelay/1000} seconds before next message", "QueueService")
            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_waiting_before_next, randomDelay/1000), "QueueService")

            // Use smart delay for long waits
            smartDelay(randomDelay, message.id)
        }

        return success
    }

    override fun onDestroy() {
        super.onDestroy()
        GatewayLogger.d("SMS", "SmsQueueService destroyed", "QueueService")

        // Mark service as no longer running for state persistence
        ServiceStateManager.setServiceRunning(this, false)

        // Release wake lock if still held
        if (wakeLock?.isHeld == true) {
            wakeLock?.release()
        }

        // Cancel enhanced notification
        if (::enhancedNotificationHelper.isInitialized) {
            enhancedNotificationHelper.cancelNotification(NotificationManager.Companion.ServiceType.SMS)
        }

        // Clear the notification when service is destroyed (fallback)
        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
        notificationManager.cancel(NOTIFICATION_ID)
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        super.onTaskRemoved(rootIntent)
        GatewayLogger.d("SMS", "onTaskRemoved: App was swiped away", "QueueService")
        // Service will continue running as foreground service
        // You can add logic here if you want to stop the service when app is swiped away
    }

    override fun onBind(intent: Intent?): IBinder? = null
}