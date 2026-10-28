package dev.titansys.smsgateway.sms

import android.Manifest
import android.app.Activity
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Telephony
import android.telephony.SmsManager
import android.telephony.SubscriptionManager
import androidx.core.content.FileProvider
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.mms.MmsDownloader
import dev.titansys.smsgateway.mms.MmsPart
import dev.titansys.smsgateway.mms.MmsPduBuilder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.GlobalScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlin.coroutines.resume
import java.io.File
import java.util.*

class SmsSender(private val context: Context) {

    companion object {
        private const val TAG = "SmsSender"
        private const val INTENT_FILTER_SMS_SENT = "SMS_SENT"
        const val SMS_PERMISSION_REQUEST_CODE = 1001

        // Counter for unique PendingIntent request codes (prevents conflicts)
        @Volatile
        private var requestCodeCounter = 0

        @Synchronized
        private fun getNextRequestCode(): Int = ++requestCodeCounter
    }

    /**
     * Get SmsManager instance with proper API level handling
     * API 31+ (Android 12+): Use context.getSystemService(SmsManager::class.java)
     * API 26-30: Use deprecated SmsManager.getDefault() or getSmsManagerForSubscriptionId()
     */
    private fun getSmsManager(context: Context, subscriptionId: Int? = null): SmsManager {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            // Android 12+ (API 31+): Use modern API
            val baseSmsManager = context.getSystemService(SmsManager::class.java)
            if (subscriptionId != null && subscriptionId >= 0) {
                baseSmsManager.createForSubscriptionId(subscriptionId)
            } else {
                baseSmsManager
            }
        } else {
            // Android 8-11 (API 26-30): Use deprecated API
            @Suppress("DEPRECATION")
            if (subscriptionId != null && subscriptionId >= 0) {
                SmsManager.getSmsManagerForSubscriptionId(subscriptionId)
            } else {
                SmsManager.getDefault()
            }
        }
    }

    /**
     * Check if device supports SMS
     */
    fun isSmsSupported(): Boolean {
        return context.packageManager.hasSystemFeature(PackageManager.FEATURE_TELEPHONY)
    }

    /**
     * Check if app has SMS permission
     */
    fun hasSmsPermission(): Boolean {
        return context.checkSelfPermission(Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED
    }

    /**
     * Request SMS permission
     * @param activity The activity to request permission from
     * @param requestCode The request code for permission result callback
     */
    fun requestSmsPermission(activity: android.app.Activity, requestCode: Int = SMS_PERMISSION_REQUEST_CODE) {
        GatewayLogger.d("SMS", "requestSmsPermission: Requesting SMS permission with code $requestCode", "Sender")
        
        if (!hasSmsPermission()) {
            GatewayLogger.d("SMS", "requestSmsPermission: Permission not granted, requesting...", "Sender")
            activity.requestPermissions(
                arrayOf(Manifest.permission.SEND_SMS),
                requestCode
            )
        } else {
            GatewayLogger.d("SMS", "requestSmsPermission: Permission already granted", "Sender")
        }
    }

    /**
     * Check if permission should be shown rationale
     * @param activity The activity to check rationale for
     * @return True if rationale should be shown
     */
    fun shouldShowSmsPermissionRationale(activity: android.app.Activity): Boolean {
        return activity.shouldShowRequestPermissionRationale(Manifest.permission.SEND_SMS)
    }

    /**
     * Get subscription ID from slot index
     */
    private fun getSubscriptionId(context: Context, slotIndex: Int): Int {
        try {
            val subscriptionManager = context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as SubscriptionManager

            // Check for required permission before accessing subscription info
            if (context.checkSelfPermission(Manifest.permission.READ_PHONE_STATE) != PackageManager.PERMISSION_GRANTED) {
                GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_no_phone_state_permission), "Sender")
                return SubscriptionManager.getDefaultSmsSubscriptionId()
            }

            // Get all active subscription info
            val activeSubscriptions = subscriptionManager.activeSubscriptionInfoList
            
            if (activeSubscriptions != null && activeSubscriptions.isNotEmpty()) {
                // Find the subscription with the matching slot index
                for (subscriptionInfo in activeSubscriptions) {
                    if (subscriptionInfo.simSlotIndex == slotIndex) {
                        val subId = subscriptionInfo.subscriptionId
                        GatewayLogger.d("SMS", "Found subscription ID $subId for slot index $slotIndex", "Sender")
                        return subId
                    }
                }
                
                // If no matching slot index found, use the first available subscription
                GatewayLogger.d("SMS", "No subscription found for slot index $slotIndex, using first available: ${activeSubscriptions[0].subscriptionId}", "Sender")
                return activeSubscriptions[0].subscriptionId
            } else {
                GatewayLogger.d("SMS", "No active subscriptions found, using default subscription ID", "Sender")
                return slotIndex
            }
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error finding subscription ID: ${e.message}", "Sender", e)
            return slotIndex
        }
    }

    /**
     * Send SMS message
     * @param phoneNumber Recipient phone number
     * @param message Message text
     * @param slotIndex SIM slot index (0 or 1 for dual SIM)
     * @param replaceLineBreaks Whether to replace \n with actual line breaks
     * @return Result of SMS sending operation
     */
    suspend fun sendSms(
        phoneNumber: String,
        message: String,
        slotIndex: String? = null,
        replaceLineBreaks: Boolean = false
    ): SmsResult = suspendCancellableCoroutine { continuation ->
        
        if (!isSmsSupported()) {
            continuation.resume(SmsResult.Error("SMS not supported on this platform"))
            return@suspendCancellableCoroutine
        }

        if (!hasSmsPermission()) {
            continuation.resume(SmsResult.Error("SMS permission not granted"))
            return@suspendCancellableCoroutine
        }

        try {
            // Process message text
            var processedMessage = message
            if (replaceLineBreaks) {
                processedMessage = message.replace("\\n", System.getProperty("line.separator") ?: "\n")
            }

            // Get SMS manager for specific SIM if slot is provided
            val smsManager = if (slotIndex != null) {
                val subscriptionId = getSubscriptionId(context, slotIndex.toInt())
                getSmsManager(context, subscriptionId)
            } else {
                getSmsManager(context)
            }

            // Divide message into parts if needed
            val messageParts = smsManager.divideMessage(processedMessage)
            
            // Create unique intent filter action to avoid conflicts
            val intentFilterAction = "$INTENT_FILTER_SMS_SENT${UUID.randomUUID()}"
            
            // Create broadcast receiver for SMS status
            val broadcastReceiver = object : BroadcastReceiver() {
                private var anyError = false
                private var partsCount = messageParts.size
                private var errorCode = "GENERIC_FAILURE"

                override fun onReceive(context: Context?, intent: Intent?) {
                    GatewayLogger.d("SMS", "BroadcastReceiver: SMS status received with result code: $resultCode", "Sender")
                    when (resultCode) {
                        Activity.RESULT_OK -> {
                            anyError = false
                            GatewayLogger.d("SMS", "BroadcastReceiver: SMS sent successfully", "Sender")
                        }
                        SmsManager.STATUS_ON_ICC_SENT -> {
                            anyError = false
                            GatewayLogger.d("SMS", "BroadcastReceiver: SMS saved to SIM", "Sender")
                        }
                        SmsManager.RESULT_ERROR_NO_SERVICE -> {
                            anyError = true
                            errorCode = "NO_SERVICE"
                            GatewayLogger.e("SMS", "BroadcastReceiver: SMS failed - NO_SERVICE", "Sender")
                        }
                        SmsManager.RESULT_ERROR_NULL_PDU -> {
                            anyError = true
                            errorCode = "NULL_PDU"
                            GatewayLogger.e("SMS", "BroadcastReceiver: SMS failed - NULL_PDU", "Sender")
                        }
                        SmsManager.RESULT_ERROR_RADIO_OFF -> {
                            anyError = true
                            errorCode = "RADIO_OFF"
                            GatewayLogger.e("SMS", "BroadcastReceiver: SMS failed - RADIO_OFF", "Sender")
                        }
                        else -> {
                            anyError = true
                            errorCode = "GENERIC_FAILURE"
                            GatewayLogger.e("SMS", "BroadcastReceiver: SMS failed - GENERIC_FAILURE (code: $resultCode)", "Sender")
                        }
                    }

                    partsCount--
                    GatewayLogger.d("SMS", "BroadcastReceiver: Parts remaining: $partsCount", "Sender")
                    if (partsCount == 0) {
                        // Unregister receiver
                        try {
                            context?.unregisterReceiver(this)
                            GatewayLogger.d("SMS", "BroadcastReceiver: Unregistered successfully", "Sender")
                        } catch (e: Exception) {
                            GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_error_unregistering_receiver, e.message ?: ""), "Sender")
                        }

                        // Return result
                        val result = if (anyError) {
                            SmsResult.Error(errorCode)
                        } else {
                            SmsResult.Success
                        }
                        
                        if (continuation.isActive) {
                            GatewayLogger.d("SMS", "BroadcastReceiver: Resuming coroutine with result: $result", "Sender")
                            continuation.resume(result)
                        }
                    }
                }
            }

            // Register broadcast receiver - must allow system broadcasts from SmsManager
            val intentFilter = IntentFilter(intentFilterAction)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                context.registerReceiver(broadcastReceiver, intentFilter, Context.RECEIVER_EXPORTED)
            } else {
                context.registerReceiver(broadcastReceiver, intentFilter)
            }
            GatewayLogger.d("SMS", "Registered broadcast receiver for action: $intentFilterAction", "Sender")

            // Create pending intent with unique request code and proper flags
            val requestCode = getNextRequestCode()
            val sentIntent = PendingIntent.getBroadcast(
                context,
                requestCode,
                Intent(intentFilterAction),
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
            )

            // Send SMS
            try {
                if (messageParts.size > 1) {
                    // Multi-part message
                    val sentIntents = ArrayList<PendingIntent>()
                    repeat(messageParts.size) {
                        sentIntents.add(sentIntent)
                    }
                    smsManager.sendMultipartTextMessage(phoneNumber, null, messageParts, sentIntents, null)
                    GatewayLogger.d("SMS", "Sent multipart SMS using slot $slotIndex", "Sender")
                } else {
                    // Single message
                    smsManager.sendTextMessage(phoneNumber, null, processedMessage, sentIntent, null)
                    GatewayLogger.d("SMS", "Sent single SMS using slot $slotIndex", "Sender")
                }
                
                // Add timeout mechanism with SMS database verification
                GlobalScope.launch {
                    delay(10000) // 10 second timeout
                    if (continuation.isActive) {
                        GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_broadcast_timeout), "Sender")
                        try {
                            context.unregisterReceiver(broadcastReceiver)
                        } catch (e: Exception) {
                            GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_error_unregistering_on_timeout, e.message ?: ""), "Sender")
                        }
                        
                        // Verify SMS was actually sent by checking SMS database
                        val wasDelivered = verifySmsDelivery(phoneNumber, processedMessage)
                        val result = if (wasDelivered) {
                            GatewayLogger.i("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_delivery_verified), "Sender")
                            SmsResult.Success
                        } else {
                            GatewayLogger.e("SMS", "SMS delivery verification failed - message was not found in sent folder", "Sender")
                            SmsResult.Error("DELIVERY_VERIFICATION_FAILED")
                        }
                        continuation.resume(result)
                    }
                }
                
            } catch (e: Exception) {
                // Unregister receiver on error
                try {
                    context.unregisterReceiver(broadcastReceiver)
                } catch (unregisterException: Exception) {
                    GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_error_unregistering_receiver, unregisterException.message ?: ""), "Sender")
                }
                
                GatewayLogger.e("SMS", "Error sending SMS", "Sender", e)
                if (continuation.isActive) {
                    continuation.resume(SmsResult.Error("SEND_ERROR: ${e.message}"))
                }
            }

        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error in sendSms", "Sender", e)
            if (continuation.isActive) {
                continuation.resume(SmsResult.Error("GENERAL_ERROR: ${e.message}"))
            }
        }
    }

    /**
     * Send SMS to multiple recipients
     * @param phoneNumbers List of phone numbers
     * @param message Message text
     * @param slotIndex SIM slot index
     * @param replaceLineBreaks Whether to replace \n with actual line breaks
     * @return List of results for each recipient
     */
    suspend fun sendSmsToMultiple(
        phoneNumbers: List<String>,
        message: String,
        slotIndex: String? = null,
        replaceLineBreaks: Boolean = false
    ): List<Pair<String, SmsResult>> {
        val results = mutableListOf<Pair<String, SmsResult>>()
        
        for (phoneNumber in phoneNumbers) {
            val result = sendSms(phoneNumber, message, slotIndex, replaceLineBreaks)
            results.add(phoneNumber to result)
        }
        
        return results
    }

    /**
     * Verify SMS delivery by checking the SMS database
     * @param phoneNumber Recipient phone number
     * @param message Message text
     * @return True if SMS was found in sent folder
     */
    private fun verifySmsDelivery(phoneNumber: String, message: String): Boolean {
        return try {
            // Check if app has SMS read permission
            if (context.checkSelfPermission(Manifest.permission.READ_SMS) != PackageManager.PERMISSION_GRANTED) {
                GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_cannot_verify_delivery), "Sender")
                return false
            }
            
            val uri = Uri.parse("content://sms/sent")
            val cursor = context.contentResolver.query(
                uri,
                arrayOf("address", "body", "date"),
                "address = ? AND body = ?",
                arrayOf(phoneNumber, message),
                "date DESC LIMIT 1"
            )
            
            cursor?.use { c ->
                if (c.moveToFirst()) {
                    val foundAddress = c.getString(c.getColumnIndexOrThrow("address"))
                    val foundBody = c.getString(c.getColumnIndexOrThrow("body"))
                    val foundDate = c.getLong(c.getColumnIndexOrThrow("date"))
                    
                    // Check if message was sent within the last 30 seconds
                    val timeDiff = System.currentTimeMillis() - foundDate
                    val isRecent = timeDiff <= 30000 // 30 seconds
                    
                    GatewayLogger.d("SMS", "Found SMS in database - Address: $foundAddress, Recent: $isRecent, TimeDiff: ${timeDiff}ms", "Sender")
                    return isRecent && foundAddress == phoneNumber && foundBody == message
                }
            }
            false
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error verifying SMS delivery", "Sender", e)
            false
        }
    }

    /**
     * Check if running in emulator environment
     * @return True if running in emulator
     */
    private fun isEmulator(): Boolean {
        return (Build.FINGERPRINT.startsWith("generic")
                || Build.FINGERPRINT.startsWith("unknown")
                || Build.MODEL.contains("google_sdk")
                || Build.MODEL.contains("Emulator")
                || Build.MODEL.contains("Android SDK built for x86")
                || Build.MANUFACTURER.contains("Genymotion")
                || (Build.BRAND.startsWith("generic") && Build.DEVICE.startsWith("generic"))
                || "google_sdk" == Build.PRODUCT)
    }

    /**
     * Get available SIM slots information
     */
    fun getAvailableSimSlots(): List<SimSlotInfo> {
        return try {
            val subscriptionManager = context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as SubscriptionManager
            val activeSubscriptions = subscriptionManager.activeSubscriptionInfoList

            activeSubscriptions?.map { subscription ->
                SimSlotInfo(
                    slotIndex = subscription.simSlotIndex,
                    displayName = subscription.displayName?.toString() ?: "SIM ${subscription.simSlotIndex + 1}",
                    carrierName = subscription.carrierName?.toString() ?: "Unknown",
                    subscriptionId = subscription.subscriptionId
                )
            } ?: listOf(SimSlotInfo(0, "Default SIM"))
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error getting SIM slots", "Sender", e)
            listOf(SimSlotInfo(0, "Default SIM"))
        }
    }

    /**
     * Send MMS message with single attachment (Burat-inspired, simplified)
     *
     * LIMITATION: This implementation only sends the attachment, not the text content.
     * Full text + attachment support requires SMIL formatting (complex MMS PDU creation).
     * Burat's implementation has full SMIL support with MmsPart library (10,000+ lines).
     * Our simplified version focuses on attachment-only MMS for now.
     *
     * @param phoneNumber Recipient phone number
     * @param message Message text (currently not included in MMS - limitation)
     * @param attachmentUrl Full attachment URL (server provides absolute URLs)
     * @param slotIndex SIM slot index (0 or 1 for dual SIM)
     * @return Result of MMS sending operation
     */
    suspend fun sendMms(
        phoneNumber: String,
        message: String,
        attachmentUrl: String,
        slotIndex: String? = null
    ): SmsResult = suspendCancellableCoroutine { continuation ->

        if (!isSmsSupported()) {
            continuation.resume(SmsResult.Error("MMS not supported on this platform"))
            return@suspendCancellableCoroutine
        }

        if (!hasSmsPermission()) {
            continuation.resume(SmsResult.Error("MMS permission not granted"))
            return@suspendCancellableCoroutine
        }

        // Launch coroutine for async attachment download
        GlobalScope.launch(Dispatchers.IO) {
            try {
                GatewayLogger.i("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_starting_send, phoneNumber), TAG)

                // Download attachment (server provides full URLs)
                val downloader = MmsDownloader(context)
                val attachmentBytes = downloader.download(attachmentUrl)
                val mimeType = downloader.detectMimeType(attachmentUrl)

                GatewayLogger.d("MMS", "Downloaded attachment: ${attachmentBytes.size} bytes, type: $mimeType", TAG)

                // Create MMS file
                val mmsFile = createMmsFile(message, attachmentBytes, mimeType)
                val contentUri = FileProvider.getUriForFile(
                    context,
                    "${context.packageName}.provider",
                    mmsFile
                )

                GatewayLogger.d("MMS", "Created MMS file URI: $contentUri", TAG)

                // Get SmsManager for specific SIM (reuse existing logic)
                val smsManager = if (slotIndex != null) {
                    val subscriptionId = getSubscriptionId(context, slotIndex.toInt())
                    getSmsManager(context, subscriptionId)
                } else {
                    getSmsManager(context)
                }

                // Create broadcast receiver for MMS status (copy SMS pattern)
                val intentFilterAction = "MMS_SENT_${UUID.randomUUID()}"
                val broadcastReceiver = object : BroadcastReceiver() {
                    override fun onReceive(ctx: Context?, intent: Intent?) {
                        GatewayLogger.d("MMS", "MMS status received: $resultCode", TAG)

                        val success = resultCode == Activity.RESULT_OK
                        if (continuation.isActive) {
                            continuation.resume(
                                if (success) {
                                    GatewayLogger.i("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_sent_successfully), TAG)
                                    SmsResult.Success
                                } else {
                                    GatewayLogger.e("MMS", "MMS failed with code: $resultCode", TAG)
                                    SmsResult.Error("MMS_FAILED_$resultCode")
                                }
                            )
                        }

                        // Unregister receiver
                        try {
                            ctx?.unregisterReceiver(this)
                        } catch (e: Exception) {
                            GatewayLogger.w("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_error_unregistering_receiver), TAG)
                        }
                    }
                }

                // Register broadcast receiver
                val intentFilter = IntentFilter(intentFilterAction)
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                    context.registerReceiver(broadcastReceiver, intentFilter, Context.RECEIVER_EXPORTED)
                } else {
                    context.registerReceiver(broadcastReceiver, intentFilter)
                }
                GatewayLogger.d("MMS", "Registered MMS broadcast receiver", TAG)

                // Create pending intent with unique request code
                val requestCode = getNextRequestCode()
                val sentIntent = PendingIntent.getBroadcast(
                    context,
                    requestCode,
                    Intent(intentFilterAction),
                    PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
                )

                // Create config bundle (Burat's exact values)
                val configOverrides = Bundle().apply {
                    putBoolean("enableGroupMms", false)
                    putInt("maxMessageSize", 819200) // 800KB (Burat's value)
                }

                // Send MMS via Android API
                withContext(Dispatchers.Main) {
                    smsManager.sendMultimediaMessage(
                        context,
                        contentUri,
                        null, // locationUrl
                        configOverrides,
                        sentIntent
                    )
                }

                GatewayLogger.i("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_send_initiated), TAG)

                // Timeout mechanism (60 seconds for MMS)
                GlobalScope.launch {
                    delay(60000)
                    if (continuation.isActive) {
                        GatewayLogger.w("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_send_timeout), TAG)
                        try {
                            context.unregisterReceiver(broadcastReceiver)
                        } catch (e: Exception) {
                            // Ignore
                        }
                        continuation.resume(SmsResult.Error("MMS_TIMEOUT"))
                    }
                }

            } catch (e: Exception) {
                GatewayLogger.e("MMS", "MMS send error", TAG, e)
                if (continuation.isActive) {
                    continuation.resume(SmsResult.Error("MMS_EXCEPTION: ${e.message}"))
                }
            }
        }
    }

    /**
     * Send MMS with text AND attachment (Burat-style PDU builder)
     *
     * Complete implementation with SMIL support for text + attachment.
     * Uses custom PDU builder to create proper MMS structure.
     *
     * @param phoneNumber Recipient phone number
     * @param textMessage Message text content
     * @param attachmentUrl Full attachment URL (server provides absolute URLs)
     * @param slotIndex SIM slot index (0 or 1 for dual SIM)
     * @return Result of MMS sending operation
     */
    suspend fun sendMmsTextAndAttachment(
        phoneNumber: String,
        textMessage: String,
        attachmentUrl: String,
        slotIndex: String?,
        uid: Int = 0,
        mid: Int = 0
    ): SmsResult = withContext(Dispatchers.IO) {
        try {
            GatewayLogger.i("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_building), TAG)

            // 1. Download attachment
            val downloader = MmsDownloader(context)
            val attachmentBytes = downloader.download(attachmentUrl)
            val mimeType = downloader.detectMimeType(attachmentUrl)
            val filename = attachmentUrl.substringAfterLast('/').takeIf { it.isNotEmpty() }
                ?: "attachment_${System.currentTimeMillis()}"

            GatewayLogger.d("MMS", "Downloaded: $filename (${attachmentBytes.size} bytes, $mimeType)", TAG)

            // 2. Create MMS parts
            val parts = mutableListOf<MmsPart>()

            // Add text part (if not empty)
            if (textMessage.isNotEmpty()) {
                parts.add(
                    MmsPart(
                        name = "text_0.txt",
                        mimeType = "text/plain",
                        data = textMessage.toByteArray(Charsets.UTF_8)
                    )
                )
                GatewayLogger.d("MMS", "Added text part: ${textMessage.length} chars", TAG)
            }

            // Add attachment part
            parts.add(
                MmsPart(
                    name = filename,
                    mimeType = mimeType,
                    data = attachmentBytes
                )
            )
            GatewayLogger.d("MMS", "Added attachment part: $filename", TAG)

            if (parts.isEmpty()) {
                return@withContext SmsResult.Error("No content to send in MMS")
            }

            // 3. Build PDU
            val simSlotInt = slotIndex?.toIntOrNull()
            val pduBuilder = MmsPduBuilder(
                context = context,
                simSlot = simSlotInt,
                recipients = arrayOf(phoneNumber),
                parts = parts
            )
            val pduBytes = pduBuilder.buildPdu()

            GatewayLogger.i("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_built_pdu, pduBytes.size), TAG)

            // 4. Write PDU to file
            val mmsDir = File(context.cacheDir, "mms")
            mmsDir.mkdirs()
            val pduFile = File(mmsDir, "mms_${System.currentTimeMillis()}.dat")
            pduFile.writeBytes(pduBytes)

            val pduUri = FileProvider.getUriForFile(
                context,
                "${context.packageName}.provider",
                pduFile
            )

            GatewayLogger.d("MMS", "Created PDU file URI: $pduUri", TAG)

            // 5. Get SmsManager for SIM slot
            val smsManager = if (simSlotInt != null) {
                val subscriptionId = getSubscriptionId(context, simSlotInt)
                getSmsManager(context, subscriptionId)
            } else {
                getSmsManager(context)
            }

            // 6. Create config bundle (Burat's values)
            val config = Bundle().apply {
                putBoolean("enableGroupMms", false)
                putInt("maxMessageSize", 819200) // 800KB
            }

            // 7. Create sent intent with message metadata (CRITICAL: Burat always uses this)
            val sentIntent = PendingIntent.getBroadcast(
                context,
                System.currentTimeMillis().toInt(),
                Intent("${context.packageName}.MMS_SENT").apply {
                    setPackage(context.packageName)
                    // Include message metadata for status reporting
                    putExtra("EXTRA_UID", uid)
                    putExtra("EXTRA_MID", mid)
                },
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
            )

            // 8. Send MMS (asynchronous - actual status reported via MmsSentReceiver)
            withContext(Dispatchers.Main) {
                smsManager.sendMultimediaMessage(
                    context,
                    pduUri,
                    null, // location URL
                    config,
                    sentIntent  // CRITICAL: Burat always provides this
                )
            }

            // Return success to indicate MMS was queued (actual result comes via MmsSentReceiver)
            GatewayLogger.i("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_queued_for_sending), TAG)
            SmsResult.Success

        } catch (e: Exception) {
            GatewayLogger.e("MMS", "MMS send failed", TAG, e)
            SmsResult.Error(e.message ?: "Unknown error")
        }
    }

    /**
     * Create MMS file from attachment bytes (simplified version)
     *
     * NOTE: Text parameter is currently not used. This creates attachment-only MMS.
     * To include text, we would need to create proper SMIL-formatted MMS PDU,
     * which requires MmsPart structure like Burat's implementation (complex).
     *
     * @param text Message text content (not currently included - see limitation above)
     * @param attachment Attachment bytes
     * @param mimeType MIME type of attachment
     * @return File object for MMS content
     */
    private fun createMmsFile(text: String, attachment: ByteArray, mimeType: String): File {
        // Create MMS cache directory
        val mmsDir = File(context.cacheDir, "mms")
        if (!mmsDir.exists()) {
            mmsDir.mkdirs()
        }

        // Create unique MMS file
        val mmsFile = File(mmsDir, "mms_${System.currentTimeMillis()}.dat")

        // Write attachment bytes to file
        mmsFile.writeBytes(attachment)

        GatewayLogger.d("MMS", "Created MMS file: ${mmsFile.absolutePath} (${attachment.size} bytes)", TAG)
        return mmsFile
    }

    /**
     * Result of SMS sending operation
     */
    sealed class SmsResult {
        object Success : SmsResult()
        data class Error(val message: String) : SmsResult()
    }

    /**
     * Information about a SIM slot
     */
    data class SimSlotInfo(
        val slotIndex: Int,
        val displayName: String,
        val carrierName: String = "Unknown",
        val subscriptionId: Int = -1
    )
} 