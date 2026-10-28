package dev.titansys.smsgateway.services

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.os.Build
import android.provider.Settings
import androidx.core.app.NotificationCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import dev.titansys.smsgateway.R
import dev.titansys.smsgateway.activities.PermissionSetupActivity
import dev.titansys.smsgateway.activities.GatewayActivity
import dev.titansys.smsgateway.services.UssdRequest as ApiUssdRequest
import dev.titansys.smsgateway.databases.SmsMessage
import dev.titansys.smsgateway.databases.UssdRequest as DbUssdRequest
import dev.titansys.smsgateway.databases.ZenderDatabase
import dev.titansys.smsgateway.databases.Campaign
import java.util.concurrent.ConcurrentHashMap
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import dev.titansys.smsgateway.helpers.RestApiClient
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import dev.titansys.smsgateway.sms.SmsServiceManager
import dev.titansys.smsgateway.helpers.UssdManager
import dev.titansys.smsgateway.helpers.ScheduledTaskManager
import dev.titansys.smsgateway.helpers.GatewayLogger

class FirebaseService : com.google.firebase.messaging.FirebaseMessagingService() {
    companion object {
        private const val TAG = "FCM"
    }

    private lateinit var sessionManager: SessionManager
    private lateinit var database: ZenderDatabase
    private lateinit var deviceHelper: DeviceInfoProvider
    
    override fun onCreate() {
        super.onCreate()
        GatewayLogger.d("FCM", "FirebaseMessagingService created", "Firebase")
        deviceHelper = DeviceInfoProvider(this)
        sessionManager = SessionManager(this)
        database = ZenderDatabase.getInstance(this)
        GatewayLogger.d("FCM", "Service initialized with database and helpers", "Firebase")
    }

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        GatewayLogger.d("FCM", "Received new FCM token: ${token.take(10)}...", "Firebase")
        sendTokenToServer(token)
    }

    private fun sendTokenToServer(token: String) {
        GatewayLogger.d("FCM", "Sending FCM token to server", "Firebase")
        CoroutineScope(Dispatchers.IO).launch {
            try {
                val hash = sessionManager.getHash()
                val did = deviceHelper.getAndroidId()

                if (hash == null || did == null) {
                    // Missing hash or device ID
                    GatewayLogger.e("FCM", "Unable to process request: EM1002", "Firebase")
                    return@launch
                }

                // Use Burat-inspired worker scheduling instead of direct API call
                GatewayLogger.d("FCM", "Scheduling token sync worker with hash: ${hash.take(5)}... and device ID: ${did.take(5)}...", "Firebase")
                ScheduledTaskManager.scheduleTokenSync(this@FirebaseService, token)
                GatewayLogger.d("FCM", "Token sync worker scheduled successfully", "Firebase")
            } catch (e: Exception) {
                // Internal error while sending token to server
                GatewayLogger.e("FCM", "Unable to process request: EM1004", "Firebase", e)
            }
        }
    }

    override fun onMessageReceived(remoteMessage: RemoteMessage) {
        super.onMessageReceived(remoteMessage)
        GatewayLogger.d("FCM", "onMessageReceived: Message from: ${remoteMessage.from}", "Firebase")
        GatewayLogger.d("FCM", "Message data: ${remoteMessage.data}", "Firebase")
        
        if (remoteMessage.data["action"] == "message_request") {
            GatewayLogger.i("FCM", GatewayLogger.appContext.getString(R.string.log_fcm_fetching_messages), "Firebase")
            fetchAndStoreMessages()
        }

        if (remoteMessage.data["action"] == "message_delete") {
            GatewayLogger.d("FCM", "Received message_delete action", "Firebase")

            val messageId = remoteMessage.data["message_id"]?.toIntOrNull()
            if (messageId != null) {
                try {
                    GatewayLogger.i("FCM", GatewayLogger.appContext.getString(R.string.log_fcm_deleting_message, messageId), "Firebase")

                    // Delete message directly using ID in a coroutine
                    CoroutineScope(Dispatchers.IO).launch {
                        database.messagesDao().deleteMessageById(messageId)
                        GatewayLogger.d("FCM", "Successfully deleted message with ID: $messageId", "Firebase")
                        GatewayLogger.i("FCM", GatewayLogger.appContext.getString(R.string.log_fcm_deleted_message, messageId), "Firebase")
                    }
                } catch (e: Exception) {
                    GatewayLogger.e("FCM", "Error deleting message: ${e.message}", "Firebase", e)
                }
            } else {
                GatewayLogger.e("FCM", "Invalid message_id received in message_delete action", "Firebase")
            }
        }

        if (remoteMessage.data["action"] == "device_update") {
            GatewayLogger.d("FCM", "Received device_update action", "Firebase")
            
            // Extract data from the message
            val deviceName = remoteMessage.data["name"]
            val packages = remoteMessage.data["packages"]
            val receiveSms = remoteMessage.data["receive_sms"]?.toIntOrNull() ?: 0
            val randomSend = remoteMessage.data["random_send"]?.toIntOrNull() ?: 0
            val randomMin = remoteMessage.data["random_min"]?.toIntOrNull() ?: 0
            val randomMax = remoteMessage.data["random_max"]?.toIntOrNull() ?: 0
            
            GatewayLogger.d("FCM", "Device update data: name=$deviceName, packages=$packages, receive_sms=$receiveSms, random_send=$randomSend, random_min=$randomMin, random_max=$randomMax", "Firebase")
            
            // Update SessionManager with the new settings
            sessionManager.saveLoginSession(
                hash = sessionManager.getHash() ?: "",
                deviceName = deviceName ?: sessionManager.getDeviceName() ?: "",
                receiveSms = receiveSms,
                randomSend = randomSend,
                randomMin = randomMin,
                randomMax = randomMax
            )
            
            
            // Update toolbar title with new device name - safely
            try {
                // Use a slight delay to ensure UI updates don't conflict
                android.os.Handler(android.os.Looper.getMainLooper()).postDelayed({
                    GatewayActivity.getInstance()?.updateToolbarTitle()
                }, 150)
            } catch (e: Exception) {
                GatewayLogger.e("FCM", "Error updating toolbar title: ${e.message}", "Firebase", e)
            }
            
            // Log the update
            GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_device_updated, "Firebase")
            GatewayLogger.d("FCM", "Device settings updated successfully", "Firebase")
        }

        if (remoteMessage.data["action"] == "device_delete") {
            GatewayLogger.d("FCM", "Received device_delete action", "Firebase")
            GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_device_deleted, "Firebase")
            
            // Implement device deletion
            try {
                // Truncate all database tables in a coroutine
                CoroutineScope(Dispatchers.IO).launch {
                    database.messagesDao().deleteAll()
                    database.ussdDao().deleteAll()
                    database.campaignDao().deleteAll()
                    GatewayLogger.d("FCM", "Successfully truncated all database tables", "Firebase")
                    
                    // Clear session data
                    sessionManager.clearSession()
                    GatewayLogger.d("FCM", "Successfully cleared session data", "Firebase")
                    
                    // Log out by starting PermissionSetupActivity with logout flag
                    val intent = Intent(this@FirebaseService, PermissionSetupActivity::class.java)
                    intent.flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
                    intent.putExtra("logout", true)
                    startActivity(intent)
                    GatewayLogger.d("FCM", "Successfully logged out user", "Firebase")

                    GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_deleting_device, "Firebase")
                }
            } catch (e: Exception) {
                GatewayLogger.e("FCM", "Error during device deletion: ${e.message}", "Firebase", e)
            }
        }

        if (remoteMessage.data["action"] == "campaign_request") {
            val campaignId = remoteMessage.data["campaign_id"]?.toIntOrNull()
            val campaignStatus = remoteMessage.data["campaign_status"] == "start"
            GatewayLogger.d("FCM", "Received campaign_request action - ID: $campaignId, Status: $campaignStatus", "Firebase")
            handleCampaignRequest(campaignId, campaignStatus)
            GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_campaign_updated, "Firebase", remoteMessage.data["campaign_id"] ?: "")

            if (campaignStatus == true) {
                SmsServiceManager.startMessageQueueService(this@FirebaseService)
            }
        }

        if (remoteMessage.data["action"] == "campaign_delete") {
            GatewayLogger.d("FCM", "Received campaign_delete action", "Firebase")
            GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_campaign_deleted, "Firebase")
            
            val campaignId = remoteMessage.data["campaign_id"]?.toIntOrNull()
            if (campaignId != null) {
                try {
                    GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_deleting_campaign, "Firebase", campaignId)
                    
                    // Delete all messages associated with this campaign directly using SQL query in a coroutine
                    CoroutineScope(Dispatchers.IO).launch {
                        val deletedCount = database.messagesDao().deleteMessagesByCampaignId(campaignId)
                        GatewayLogger.d("FCM", "Deleted $deletedCount messages associated with campaign #$campaignId", "Firebase")
                        
                        // Delete the campaign itself
                        val campaign = database.campaignDao().getCampaign(campaignId)
                        if (campaign != null) {
                            database.campaignDao().deleteCampaign(campaign)
                            GatewayLogger.d("FCM", "Successfully deleted campaign #$campaignId", "Firebase")
                        } else {
                            GatewayLogger.d("FCM", "Campaign #$campaignId not found in database", "Firebase")
                        }

                        GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_campaign_delete_success, "Firebase", campaignId, deletedCount)
                    }
                } catch (e: Exception) {
                    GatewayLogger.e("FCM", "Error deleting campaign and messages: ${e.message}", "Firebase", e)
                    GatewayLogger.e("FCM", "Error deleting campaign: ${e.message}", "Firebase")
                }
            } else {
                GatewayLogger.e("FCM", "Invalid campaign_id received in campaign_delete action", "Firebase")
            }
        }

        
        if (remoteMessage.data["action"] == "ussd_request") {
            GatewayLogger.d("FCM", "Received ussd_request action", "Firebase")
            GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_fetching_ussd_codes, "Firebase")
            fetchAndStoreUssdRequests()
        }

        if (remoteMessage.data["action"] == "ussd_delete") {
            GatewayLogger.d("FCM", "Received ussd_delete action", "Firebase")
            
            val ussdId = remoteMessage.data["ussd_id"]?.toIntOrNull()
            if (ussdId != null) {
                try {
                    GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_deleting_ussd, "Firebase", ussdId)

                    // Delete message directly using ID in a coroutine
                    CoroutineScope(Dispatchers.IO).launch {
                        database.ussdDao().deleteUssdById(ussdId)
                        GatewayLogger.d("FCM", "Successfully deleted USSD with ID: $ussdId", "Firebase")
                        GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_ussd_deleted, "Firebase", ussdId)
                    }
                } catch (e: Exception) {
                    GatewayLogger.e("FCM", "Error deleting USSD: ${e.message}", "Firebase", e)
                }
            } else {
                GatewayLogger.e("FCM", "Invalid ussd_id received in ussd_delete action", "Firebase")
            }
        }
    }

    private fun handleCampaignRequest(campaignId: Int?, campaignStatus: Boolean?) {
        if (campaignId == null || campaignStatus == null) {
            // Missing campaign ID or status
            GatewayLogger.e("FCM", "Missing campaign ID or status - ID: $campaignId, Status: $campaignStatus", "Firebase")
            GatewayLogger.e("FCM", "Unable to process request: EM1005", "Firebase")
            return
        }

        GatewayLogger.d("FCM", "Handling campaign request - ID: $campaignId, Status: $campaignStatus", "Firebase")
        CoroutineScope(Dispatchers.IO).launch {
            try {
                val existingCampaign = database.campaignDao().getCampaign(campaignId)
                
                if (existingCampaign == null) {
                    // Create new campaign
                    GatewayLogger.d("FCM", "Creating new campaign - ID: $campaignId, Status: $campaignStatus", "Firebase")
                    val newCampaign = Campaign(
                        campaignId = campaignId,
                        isActive = campaignStatus
                    )
                    database.campaignDao().insertCampaign(newCampaign)
                } else {
                    // Update existing campaign
                    GatewayLogger.d("FCM", "Updating existing campaign - ID: $campaignId, Old Status: ${existingCampaign.isActive}, New Status: $campaignStatus", "Firebase")
                    val updatedCampaign = existingCampaign.copy(isActive = campaignStatus)
                    database.campaignDao().updateCampaign(updatedCampaign)
                }
                GatewayLogger.d("FCM", "Campaign request handled successfully", "Firebase")
            } catch (e: Exception) {
                // Internal error while handling campaign request
                GatewayLogger.e("FCM", "Error handling campaign request: ${e.message}", "Firebase", e)
                GatewayLogger.e("FCM", "Unable to process request: EM1008", "Firebase")
            }
        }
    }

    private fun fetchAndStoreMessages() {
        GatewayLogger.d("FCM", "Starting to fetch and store messages", "Firebase")
        CoroutineScope(Dispatchers.IO).launch {
            try {
                // Get device hash and ID from session
                val hash = sessionManager.getHash()
                val did = deviceHelper.getAndroidId()

                if (hash == null || did == null) {
                    // Missing hash or device ID
                    GatewayLogger.e("FCM", "Missing hash or device ID for message fetch", "Firebase")
                    GatewayLogger.e("FCM", "Unable to process request: EM1009", "Firebase")
                    return@launch
                }

                // Check current message count in database
                val currentMessageCount = database.messagesDao().getMessageCount()
                GatewayLogger.d("FCM", "Current message count in database: $currentMessageCount", "Firebase")
                
                // Calculate how many more messages we need to reach 200
                val maxMessages = 200
                val messagesNeeded = maxMessages - currentMessageCount
            
                if (messagesNeeded > 0) {
                    GatewayLogger.d("FCM", "Fetching $messagesNeeded messages to reach maximum of $maxMessages", "Firebase")
                    GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_fetching_messages_to_max, "Firebase", messagesNeeded, maxMessages)
                }

                GatewayLogger.d("FCM", "Fetching messages with hash: ${hash.take(5)}... and device ID: ${did.take(5)}...", "Firebase")
                val response = RestApiClient.apiService.getMessages(MessageFetchRequest(hash, did, messagesNeeded))
                if (response.isSuccessful) {
                    val messages = response.body()
                    if (messages != null) {
                        GatewayLogger.d("FCM", "Successfully fetched ${messages.data.size} messages from server", "Firebase")
                        GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_messages_fetched, "Firebase", messages.data.size)
                        
                        // Store messages in database
                        var storedCount = 0
                        var skippedCount = 0
                        for (messageRequest in messages.data) {
                            try {
                                // Check if message already exists
                                if (database.messagesDao().messageExists(messageRequest.id)) {
                                    GatewayLogger.d("FCM", "Skipping message ${messageRequest.id} - already exists", "Firebase")
                                    GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_message_skipped_exists, "Firebase", messageRequest.id)
                                    skippedCount++
                                    continue
                                }

                                // Convert MessageRequest to SmsMessage and store in database
                                val smsMessage = SmsMessage(
                                    id = messageRequest.id,
                                    userId = messageRequest.uid,
                                    campaignId = messageRequest.cid,
                                    simSlot = messageRequest.sim,
                                    recipientNumber = messageRequest.recipient,
                                    messageContent = messageRequest.message,
                                    priority = messageRequest.priority,
                                    isProcessed = false,
                                    messageType = if (!messageRequest.attachment.isNullOrBlank()) "MMS" else "SMS",
                                    attachment = messageRequest.attachment
                                )
                                
                                // Store in database
                                database.messagesDao().insertMessage(smsMessage)
                                storedCount++

                                GatewayLogger.d("FCM", "Stored new message #${messageRequest.id}", "Firebase")
                                
                                // Check if we've reached the maximum
                                if (storedCount + currentMessageCount.toInt() >= maxMessages) {
                                    GatewayLogger.d("FCM", "Reached maximum message limit ($maxMessages), stopping storage", "Firebase")
                                    continue
                                }
                            } catch (e: Exception) {
                                // Internal error while storing message
                                GatewayLogger.e("FCM", "Error storing message ${messageRequest.id}: ${e.message}", "Firebase", e)
                                GatewayLogger.e("FCM", "Unable to process request: EM1010", "Firebase")
                                continue
                            }
                        }
                        
                        GatewayLogger.d("FCM", "Message storage complete - Stored: $storedCount, Skipped: $skippedCount, Total: ${messages.data.size}", "Firebase")

                        // Start the SmsQueueService only after successfully storing messages
                        SmsServiceManager.startMessageQueueService(this@FirebaseService)
                    } else {
                        GatewayLogger.e("FCM", "Response body is null", "Firebase")
                    }
                } else {
                    // Failed to fetch messages
                    GatewayLogger.e("FCM", "Failed to fetch messages with code: ${response.code()}", "Firebase")
                    GatewayLogger.e("FCM", "Unable to process request: EM1011", "Firebase")
                }
            } catch (e: Exception) {
                // Internal error while fetching messages
                GatewayLogger.e("FCM", "Error fetching messages: ${e.message}", "Firebase", e)
                GatewayLogger.e("FCM", "Unable to process request: EM1012", "Firebase")
            }
        }
    }

    private fun fetchAndStoreUssdRequests() {
        GatewayLogger.d("FCM", "Starting to fetch and store USSD requests", "Firebase")
        CoroutineScope(Dispatchers.IO).launch {
            try {
                // Get device hash and ID from session
                val hash = sessionManager.getHash()
                val did = deviceHelper.getAndroidId()

                if (hash == null || did == null) {
                    // Missing hash or device ID
                    GatewayLogger.e("FCM", "Missing hash or device ID for USSD fetch", "Firebase")
                    GatewayLogger.e("FCM", "Unable to process request: EM1013", "Firebase")
                    return@launch
                }

                GatewayLogger.d("FCM", "Fetching USSD requests with hash: ${hash.take(5)}... and device ID: ${did.take(5)}...", "Firebase")
                val response = RestApiClient.apiService.getUssdRequests(UssdFetchRequest(hash, did))
                if (response.isSuccessful) {
                    val ussdResponse = response.body()
                    if (ussdResponse != null) {
                        GatewayLogger.d("FCM", "Successfully fetched ${ussdResponse.data.size} USSD requests from server", "Firebase")
                        GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_ussd_codes_fetched, "Firebase", ussdResponse.data.size)
                        
                        // Store USSD requests in database
                        var storedCount = 0
                        var skippedCount = 0
                        for (ussdRequest in ussdResponse.data) {
                            try {
                                // Check if USSD request already exists
                                if (database.ussdDao().ussdExists(ussdRequest.id)) {
                                    GatewayLogger.d("FCM", "Skipping USSD #${ussdRequest.id} - already exists", "Firebase")
                                    GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_ussd_skipped_exists, "Firebase", ussdRequest.id)
                                    skippedCount++
                                    continue
                                }

                                // Convert ApiUssdRequest to DbUssdRequest and store in database
                                val dbUssdRequest = DbUssdRequest(
                                    id = ussdRequest.id,
                                    userId = ussdRequest.uid,
                                    simSlot = ussdRequest.sim,
                                    ussdCode = ussdRequest.code,
                                    isProcessed = false
                                )

                                // Store in database
                                database.ussdDao().insertUssd(dbUssdRequest)
                                GatewayLogger.d("FCM", "Stored new USSD #${ussdRequest.id} for SIM: ${ussdRequest.sim}", "Firebase")
                                GatewayLogger.i("FCM", dev.titansys.smsgateway.R.string.log_fcm_ussd_stored_new, "Firebase", ussdRequest.id)
                                storedCount++
                            } catch (e: Exception) {
                                // Internal error while storing USSD request
                                GatewayLogger.e("FCM", "Error storing USSD ${ussdRequest.id}: ${e.message}", "Firebase", e)
                                GatewayLogger.e("FCM", "Unable to process request: EM1014", "Firebase")
                                continue
                            }
                        }
                        
                        GatewayLogger.d("FCM", "USSD storage complete - Stored: $storedCount, Skipped: $skippedCount, Total: ${ussdResponse.data.size}", "Firebase")

                        // start ussd sending service here
                        if (storedCount > 0) {
                            GatewayLogger.d("FCM", "USSD service start would be triggered here", "Firebase")

                            // Start USSD service after fetching requests
                            UssdManager.startUssdService(this@FirebaseService)
                        } else {
                            GatewayLogger.d("FCM", "No new USSD requests to process", "Firebase")
                        }
                    } else {
                        GatewayLogger.e("FCM", "USSD response body is null", "Firebase")
                    }
                } else {
                    // Failed to fetch USSD requests
                    GatewayLogger.e("FCM", "Failed to fetch USSD requests with code: ${response.code()}", "Firebase")
                    GatewayLogger.e("FCM", "Unable to process request: EM1015", "Firebase")
                }
            } catch (e: Exception) {
                // Internal error while fetching USSD requests
                GatewayLogger.e("FCM", "Error fetching USSD requests: ${e.message}", "Firebase", e)
                GatewayLogger.e("FCM", "Unable to process request: EM1016", "Firebase")
            }
        }
    }
} 
