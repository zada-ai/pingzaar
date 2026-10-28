package dev.titansys.smsgateway.services

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager as AndroidNotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.graphics.BitmapFactory
import android.os.Build
import android.os.IBinder
import android.telephony.TelephonyManager
import android.telephony.SubscriptionManager
import dev.titansys.smsgateway.helpers.GatewayLogger
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.withTimeoutOrNull
import java.util.concurrent.ConcurrentHashMap
import dev.titansys.smsgateway.R
import dev.titansys.smsgateway.activities.PermissionSetupActivity
import dev.titansys.smsgateway.activities.GatewayActivity
import dev.titansys.smsgateway.databases.ZenderDatabase
import dev.titansys.smsgateway.databases.UssdRequest as DbUssdRequest
import dev.titansys.smsgateway.helpers.RestApiClient
import dev.titansys.smsgateway.helpers.NotificationManager
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import dev.titansys.smsgateway.helpers.ServiceStateManager
import dev.titansys.smsgateway.helpers.MessageProcessingManager
import dev.titansys.smsgateway.helpers.UssdManager
import dev.titansys.smsgateway.workers.UssdResponseWorker
import androidx.work.*
import java.util.concurrent.TimeUnit

class UssdQueueService : Service() {
    companion object {
        private const val CHANNEL_ID = "ussd_sending_channel"
        private const val NOTIFICATION_ID = 3
        private const val NOTIFICATION_TITLE = "USSD Service"
        private const val TAG = "UssdQueueService"
        private const val USSD_TIMEOUT_MS = 60_000L // 60 seconds (Burat-style)
    }

    private val serviceScope = CoroutineScope(Dispatchers.IO)
    private lateinit var database: ZenderDatabase
    private lateinit var sessionManager: SessionManager
    private lateinit var deviceHelper: DeviceInfoProvider
    private lateinit var telephonyManager: TelephonyManager
    private lateinit var unifiedHelper: MessageProcessingManager
    private lateinit var enhancedNotificationHelper: NotificationManager
    // Burat-inspired: CompletableDeferred for wait/notify pattern (thread-safe)
    private val responseChannels = ConcurrentHashMap<Int, CompletableDeferred<String>>()
    private var currentUssdIndex = 0
    private var totalUssdRequests = 0
    private var completedUssdRequests = 0
    private var failedUssdRequests = 0

    override fun onCreate() {
        super.onCreate()
        GatewayLogger.d("USSD", "onCreate: UssdQueueService created", "Service")

        // Initialize components
        database = ZenderDatabase.getInstance(this)
        sessionManager = SessionManager(this)
        deviceHelper = DeviceInfoProvider(this)
        telephonyManager = getSystemService(Context.TELEPHONY_SERVICE) as TelephonyManager
        unifiedHelper = MessageProcessingManager(this)
        enhancedNotificationHelper = NotificationManager(this)

        // Create notification channel
        createNotificationChannel()

        // Mark service as running for unified state management
        ServiceStateManager.setUssdServiceRunning(this, true)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        GatewayLogger.d("USSD", "onStartCommand: Service starting with startId: $startId", "Service")

        // Create initial enhanced notification
        val initialProgress = enhancedNotificationHelper.createProgressInfo(
            current = 0,
            total = 0,
            stage = NotificationManager.Companion.ProcessingStage.PROCESSING_INDEFINITE
        )
        val initialNotification = enhancedNotificationHelper.createEnhancedNotification(
            NotificationManager.Companion.ServiceType.USSD,
            initialProgress
        )

        // Start foreground service with appropriate method based on Android version
        // FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING requires API 34+ (Android 14)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            GatewayLogger.d("USSD", "Starting foreground service with FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING (Android 14+)", "Service")
            startForeground(NOTIFICATION_ID, initialNotification, ServiceInfo.FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING)
        } else {
            GatewayLogger.d("USSD", "Starting foreground service with legacy method (Android 13 and below)", "Service")
            startForeground(NOTIFICATION_ID, initialNotification)
        }

        // Launch coroutine to process USSD requests
        serviceScope.launch {
            GatewayLogger.d("USSD", "Launching USSD processing coroutine", "Service")
            processUssdRequests()
        }

        return START_STICKY
    }

    private fun createNotification(contentText: String = "Processing USSD requests..."): Notification {
        GatewayLogger.d("USSD", "Creating notification with text: $contentText", "Service")

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

        // Add progress if we have USSD info
        if (totalUssdRequests > 0) {
            builder.setProgress(totalUssdRequests, currentUssdIndex, false)
        } else {
            builder.setProgress(0, 0, true) // Indeterminate progress
        }

        return builder.build()
    }

    private fun createNotificationChannel() {
        GatewayLogger.d("USSD", "Creating notification channel", "Service")

        val channel = NotificationChannel(
            CHANNEL_ID,
            NOTIFICATION_TITLE,
            AndroidNotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Shows when USSD service is active"
            enableLights(false)
            enableVibration(false)
        }

        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
        notificationManager.createNotificationChannel(channel)
        GatewayLogger.d("USSD", "Notification channel created successfully", "Service")
    }

    private suspend fun processUssdRequests() {
        try {
            GatewayLogger.d("USSD", "Starting USSD request processing with priority support", "Service")
            GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_fetching_queue), "Service")

            val ussdRequests = database.ussdDao().getAllUssd()
            totalUssdRequests = ussdRequests.size
            currentUssdIndex = 0

            GatewayLogger.d("USSD", "Found ${ussdRequests.size} USSD requests to process", "Service")
            GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_found_requests, ussdRequests.size), "Service")

            // Convert to processable items and sort into priority queues
            val processableUssdRequests = ussdRequests.map { MessageProcessingManager.ProcessableUssd(it) }
            val priorityQueues = unifiedHelper.sortIntoPriorityQueues(processableUssdRequests)

            // Process priority queue first, then standard queue
            if (priorityQueues.priorityQueue.isNotEmpty()) {
                GatewayLogger.d("USSD", "Processing priority USSD queue (${priorityQueues.priorityQueue.size} requests)", "Service")
                GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_processing_priority, priorityQueues.priorityQueue.size), "Service")

                unifiedHelper.processQueue(
                    queueName = "Priority USSD",
                    queues = priorityQueues,
                    totalItems = totalUssdRequests,
                    currentIndex = currentUssdIndex,
                    processFunction = { processableItem, currentIndex, _ ->
                        currentUssdIndex = currentIndex
                        @Suppress("USELESS_CAST")
                        processIndividualUssdRequest((processableItem as MessageProcessingManager.ProcessableUssd).ussd)
                    },
                    updateNotification = { message ->
                        updateNotification(message)
                    }
                )
            }

            if (priorityQueues.standardQueue.isNotEmpty()) {
                GatewayLogger.d("USSD", "Processing standard USSD queue (${priorityQueues.standardQueue.size} requests)", "Service")
                GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_processing_standard, priorityQueues.standardQueue.size), "Service")

                unifiedHelper.processQueue(
                    queueName = "Standard USSD",
                    queues = priorityQueues,
                    totalItems = totalUssdRequests,
                    currentIndex = currentUssdIndex,
                    processFunction = { processableItem, currentIndex, _ ->
                        currentUssdIndex = currentIndex
                        @Suppress("USELESS_CAST")
                        processIndividualUssdRequest((processableItem as MessageProcessingManager.ProcessableUssd).ussd)
                    },
                    updateNotification = { message ->
                        updateNotification(message)
                    }
                )
            }

            GatewayLogger.d("USSD", "All USSD queues processed successfully", "Service")
        } catch (e: Exception) {
            GatewayLogger.e("USSD", "Error in processUssdRequests: ${e.message}", "Service", e)
            GatewayLogger.i("USSD", "Unable to process request: EM1018", "Service")
        } finally {
            GatewayLogger.d("USSD", "Stopping service", "Service")
            stopSelf()
        }
    }

    /**
     * Process an individual USSD request with all existing checks and logic
     */
    private suspend fun processIndividualUssdRequest(ussd: DbUssdRequest): Boolean {
        // Check if USSD is enabled
        if (!sessionManager.getUssdEnabled()) {
            GatewayLogger.d("USSD", "USSD is disabled, stopping service without processing USSD requests", "Service")
            GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_feature_disabled), "Service")
            stopSelf()
            return false
        }

        // Check if USSD request still exists in database
        if (!database.ussdDao().ussdExists(ussd.id)) {
            GatewayLogger.d("USSD", "USSD #${ussd.id} no longer exists in database, skipping", "Service")
            GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_request_removed, ussd.id), "Service")
            return false
        }

        try {
            // Check permission before sending USSD
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.CALL_PHONE) !=
                PackageManager.PERMISSION_GRANTED) {
                GatewayLogger.e("USSD", "Missing CALL_PHONE permission for USSD #${ussd.id}", "Service")
                GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_missing_permission, ussd.id), "Service")

                // Send failed status to server
                sendUssdResponseToServer(ussd.id, "ERROR: Missing CALL_PHONE permission", status = 4)

                // Mark as processed
                val updatedUssd = ussd.copy(isProcessed = true)
                database.ussdDao().updateUssd(updatedUssd)
                return false
            }

            // Convert subscription ID to 0-based index
            val subscriptionId = findSubscriptionIdBySlotIndex(this, ussd.simSlot - 1)
            GatewayLogger.d("USSD", "Using subscription ID: $subscriptionId (original SIM: ${ussd.simSlot})", "Service")

            // Send USSD request and get deferred reference (Burat-style)
            val responseDeferred = sendUssdRequest(ussd, subscriptionId)

            // Update notification to show waiting state with progress
            val waitingProgress = enhancedNotificationHelper.createProgressInfo(
                current = currentUssdIndex,
                total = totalUssdRequests,
                stage = NotificationManager.Companion.ProcessingStage.PROCESSING_INDEFINITE
            )
            val waitingNotification = enhancedNotificationHelper.createEnhancedNotification(
                NotificationManager.Companion.ServiceType.USSD,
                waitingProgress
            )
            val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
            notificationManager.notify(NOTIFICATION_ID, waitingNotification)

            // Burat-style wait: suspend until callback completes deferred or timeout
            GatewayLogger.d("USSD", "Waiting for USSD #${ussd.id} response (timeout: ${USSD_TIMEOUT_MS}ms)...", "Service")
            val response = withTimeoutOrNull(USSD_TIMEOUT_MS) {
                responseDeferred.await()
            }

            // Cleanup
            responseChannels.remove(ussd.id)

            if (response == null) {
                // Timeout occurred
                GatewayLogger.e("USSD", "Timeout waiting for USSD response #${ussd.id} after ${USSD_TIMEOUT_MS}ms", "Service")
                GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_request_timed_out, ussd.id), "Service")

                // Send failed status to server
                sendUssdResponseToServer(ussd.id, "ERROR: Request timed out after ${USSD_TIMEOUT_MS / 1000} seconds", status = 4)

                // Mark as processed
                val updatedUssd = ussd.copy(isProcessed = true)
                database.ussdDao().updateUssd(updatedUssd)
                return false
            }

            // Response received - check if it's an error or success
            GatewayLogger.d("USSD", "Response received for USSD #${ussd.id}: $response", "Service")

            // Determine status: 3=success, 4=failed
            val status = if (response.startsWith("ERROR:")) 4 else 3

            // Send to server with status
            sendUssdResponseToServer(ussd.id, response, status)

            // Mark as processed
            val updatedUssd = ussd.copy(isProcessed = true)
            database.ussdDao().updateUssd(updatedUssd)

            // Add delay between requests (1.5 seconds)
            if (currentUssdIndex < totalUssdRequests) {
                GatewayLogger.d("USSD", "Waiting 1.5 seconds before processing next USSD request...", "Service")
                GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_waiting_next_request), "Service")
                kotlinx.coroutines.delay(1500)
            }

            return true
        } catch (e: Exception) {
            GatewayLogger.e("USSD", "Error processing USSD #${ussd.id}: ${e.message}", "Service", e)
            GatewayLogger.i("USSD", "Unable to process request: EM1017", "Service")

            // Send failed status to server
            sendUssdResponseToServer(ussd.id, "ERROR: ${e.message}", status = 4)

            // Clean up channel in case of exception
            responseChannels.remove(ussd.id)

            // Mark as processed
            val updatedUssd = ussd.copy(isProcessed = true)
            database.ussdDao().updateUssd(updatedUssd)

            return false
        }
    }

    @Suppress("UNUSED_PARAMETER")
    private fun updateNotification(contentText: String) {
        // Update with enhanced notification showing progress (contentText not used - kept for interface compatibility)
        val progressInfo = enhancedNotificationHelper.createProgressInfo(
            current = currentUssdIndex,
            total = totalUssdRequests,
            stage = NotificationManager.Companion.ProcessingStage.PROCESSING_INDEFINITE
        )
        val notification = enhancedNotificationHelper.createEnhancedNotification(
            NotificationManager.Companion.ServiceType.USSD,
            progressInfo
        )
        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
        notificationManager.notify(NOTIFICATION_ID, notification)
    }

    /**
     * Send USSD response to server with status
     * @param ussdId USSD request ID
     * @param response Response text
     * @param status 3 = success, 4 = failed
     */
    private suspend fun sendUssdResponseToServer(ussdId: Int, response: String, status: Int) {
        try {
            // CRITICAL FIX: Queue USSD response with WorkManager instead of direct API call
            // This ensures responses are not lost when internet is unavailable
            // USSD codes are sent via cellular (works offline) but response reporting needs internet

            val hash = sessionManager.getHash()
            val did = deviceHelper.getAndroidId()

            if (hash == null || did == null) {
                GatewayLogger.e("USSD", "Missing hash or device ID for USSD response submission", "Service")
                GatewayLogger.i("USSD", "Unable to process request: EM1021", "Service")
                return
            }

            GatewayLogger.d("USSD", "Queueing USSD response to server for #$ussdId (status: $status)", "Service")

            // Build WorkManager input data
            val inputData = Data.Builder()
                .putString("hash", hash)
                .putString("did", did)
                .putInt("ussd_id", ussdId)
                .putString("response", response)
                .putInt("status", status)
                .build()

            // Set constraints - requires internet for API call
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            // Create OneTimeWorkRequest with exponential backoff
            val workRequest = OneTimeWorkRequestBuilder<UssdResponseWorker>()
                .setInputData(inputData)
                .setConstraints(constraints)
                .setBackoffCriteria(
                    BackoffPolicy.EXPONENTIAL,
                    30, // Initial backoff: 30 seconds
                    TimeUnit.SECONDS
                )
                .build()

            // Enqueue the work
            WorkManager.getInstance(this@UssdQueueService).enqueue(workRequest)

            GatewayLogger.d("USSD", "USSD response queued successfully for #$ussdId - will send when internet available", "Service")
            GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_response_sent, ussdId, status), "Service")

        } catch (e: Exception) {
            GatewayLogger.e("USSD", "Error queueing USSD response for #$ussdId: ${e.message}", "Service", e)
        }
    }

    private fun sendUssdRequest(ussd: DbUssdRequest, subscriptionId: Int): CompletableDeferred<String> {
        // Create CompletableDeferred for this request (Burat's wait/notify pattern)
        val responseDeferred = CompletableDeferred<String>()
        responseChannels[ussd.id] = responseDeferred

        try {
            GatewayLogger.d("USSD", "Sending USSD request #${ussd.id}: ${ussd.ussdCode}", "Service")
            GatewayLogger.i("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_sending_request, ussd.id, ussd.ussdCode), "Service")

            // Send USSD request (minSdk 26 = Android 8.0 = API 26, which is >= Build.VERSION_CODES.O)
            // Create a handler for the callback execution
            val mainHandler = android.os.Handler(android.os.Looper.getMainLooper())

            @Suppress("MissingPermission") // Permission checked earlier in processIndividualUssdRequest (line 256)
            telephonyManager.createForSubscriptionId(subscriptionId)
                .sendUssdRequest(ussd.ussdCode, object : TelephonyManager.UssdResponseCallback() {
                    override fun onReceiveUssdResponse(
                        telephonyManager: TelephonyManager,
                        request: String,
                        response: CharSequence
                    ) {
                        GatewayLogger.d("USSD", "USSD Response received for #${ussd.id}: $response", "Service")
                        // Complete deferred (like Burat's notify)
                        responseChannels.remove(ussd.id)?.complete(response.toString())
                    }

                    override fun onReceiveUssdResponseFailed(
                        telephonyManager: TelephonyManager,
                        request: String,
                        failureCode: Int
                    ) {
                        val errorMessage = when(failureCode) {
                            -1 -> "Generic failure"
                            -2 -> "Network not available"
                            -3 -> "Service unavailable"
                            -4 -> "Invalid request"
                            else -> "Unknown error ($failureCode)"
                        }
                        GatewayLogger.e("USSD", "USSD failure for #${ussd.id}: $errorMessage", "Service")
                        // Complete deferred with error (like Burat's notify)
                        responseChannels.remove(ussd.id)?.complete("ERROR: $errorMessage")
                    }
                }, mainHandler)
        } catch (e: Exception) {
            GatewayLogger.e("USSD", "Error sending USSD request #${ussd.id}: ${e.message}", "Service", e)
            // Complete with error to prevent timeout waiting
            responseChannels.remove(ussd.id)?.complete("ERROR: ${e.message}")
        }

        return responseDeferred
    }

    /**
     * Finds the actual subscription ID based on the slot index
     * @param context The application context
     * @param slotIndex The slot index (0 for first SIM, 1 for second SIM, etc.)
     * @return The actual subscription ID for the given slot index
     */
    private fun findSubscriptionIdBySlotIndex(context: Context, slotIndex: Int): Int {
        try {
            val subscriptionManager = context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as SubscriptionManager

            // Get all active subscription info
            @Suppress("MissingPermission") // Permission checked in processIndividualUssdRequest (line 256)
            val activeSubscriptions = subscriptionManager.activeSubscriptionInfoList
            
            if (activeSubscriptions != null && activeSubscriptions.isNotEmpty()) {
                // Find the subscription with the matching slot index
                for (subscriptionInfo in activeSubscriptions) {
                    if (subscriptionInfo.simSlotIndex == slotIndex) {
                        val subId = subscriptionInfo.subscriptionId
                        GatewayLogger.d("USSD", "Found subscription ID $subId for slot index $slotIndex", "Service")
                        return subId
                    }
                }
                
                // If no matching slot index found, use the first available subscription
                GatewayLogger.d("USSD", "No subscription found for slot index $slotIndex, using first available: ${activeSubscriptions[0].subscriptionId}", "Service")
                return activeSubscriptions[0].subscriptionId
            } else {
                GatewayLogger.d("USSD", "No active subscriptions found, using default subscription ID", "Service")
                return slotIndex
            }
        } catch (e: Exception) {
            GatewayLogger.e("USSD", "Error finding subscription ID: ${e.message}", "Service", e)
            return slotIndex
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        GatewayLogger.d("USSD", "onDestroy: Service destroyed", "Service")

        // Clean up any pending response channels
        responseChannels.forEach { (ussdId, deferred) ->
            GatewayLogger.w("USSD", GatewayLogger.appContext.getString(R.string.log_ussd_cancelling_pending, ussdId), "Service")
            deferred.cancel()
        }
        responseChannels.clear()

        // Mark service as no longer running for unified state management
        ServiceStateManager.setUssdServiceRunning(this, false)

        // Cancel enhanced notification
        if (::enhancedNotificationHelper.isInitialized) {
            enhancedNotificationHelper.cancelNotification(NotificationManager.Companion.ServiceType.USSD)
        }

        // Clear the notification when service is destroyed (fallback)
        val notificationManager = getSystemService(Context.NOTIFICATION_SERVICE) as android.app.NotificationManager
        notificationManager.cancel(NOTIFICATION_ID)
    }

    override fun onBind(intent: Intent?): IBinder? = null
}