package dev.titansys.smsgateway.sms.receivers

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.ContentValues
import android.net.Uri
import android.provider.Telephony
import android.telephony.SmsMessage
import android.telephony.SubscriptionManager
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import dev.titansys.smsgateway.activities.GatewayActivity
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.sms.IncomingSmsHandler
import dev.titansys.smsgateway.helpers.SessionManager

/**
 * High-priority BroadcastReceiver for intercepting incoming SMS messages
 * Works even when app is not the default SMS app using maximum priority (2147483647)
 * Based on enterprise SMS gateway patterns for reliable message capture
 */
class SmsReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "IncomingSmsReceiver"
    }

    private val receiverScope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    override fun onReceive(context: Context, intent: Intent) {
        try {
            // Check if SMS receiving is disabled
            val sessionManager = SessionManager(context)
            val smsEnabled = sessionManager.getSmsEnabled()
            val receiveSmsValue = sessionManager.getReceiveSms()

            if (!smsEnabled || receiveSmsValue == 2) {
                return
            }

            when (intent.action) {
                Telephony.Sms.Intents.SMS_RECEIVED_ACTION -> {
                    handleIncomingSms(context, intent)
                }
            }
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error processing SMS in IncomingSmsReceiver", "Receiver", e)
        }
    }

    private fun handleIncomingSms(context: Context, intent: Intent) {
        val bundle = intent.extras ?: return

        val pdus = try {
            @Suppress("DEPRECATION")
            bundle.get("pdus") as? Array<*>
        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Error extracting SMS PDUs", "Receiver", e)
            return
        }

        if (pdus == null || pdus.isEmpty()) {
            return
        }

        val format = bundle.getString("format")

        // Extract subscription ID for multi-SIM support
        val subscriptionId = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP_MR1) {
            intent.getIntExtra("subscription", SubscriptionManager.INVALID_SUBSCRIPTION_ID)
        } else {
            SubscriptionManager.INVALID_SUBSCRIPTION_ID
        }

        receiverScope.launch {
            try {
                val messages = mutableListOf<SmsData>()

                for (pdu in pdus) {
                    val smsMessage = try {
                        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.M) {
                            SmsMessage.createFromPdu(pdu as ByteArray, format)
                        } else {
                            @Suppress("DEPRECATION")
                            SmsMessage.createFromPdu(pdu as ByteArray)
                        }
                    } catch (e: Exception) {
                        GatewayLogger.e("SMS", "Error creating SmsMessage from PDU", "Receiver", e)
                        null
                    }

                    if (smsMessage != null) {
                        val smsData = SmsData(
                            sender = smsMessage.originatingAddress ?: "",
                            message = smsMessage.messageBody ?: "",
                            timestamp = smsMessage.timestampMillis,
                            subscriptionId = subscriptionId
                        )
                        messages.add(smsData)
                    }
                }

                if (messages.isNotEmpty()) {
                    val receivedSmsHelper = IncomingSmsHandler(context)
                    messages.forEach { smsData ->
                        val simSlot = getSimSlotFromSubscription(context, smsData.subscriptionId)

                        // Gateway functionality (existing)
                        receivedSmsHelper.storeAndSync(
                            sender = smsData.sender,
                            message = smsData.message,
                            simSlot = simSlot,
                            timestamp = smsData.timestamp
                        )

                        // Default SMS app compliance (minimal)
                        if (isDefaultSmsApp(context)) {
                            writeToSmsDatabase(context, smsData)
                        }
                    }
                }

            } catch (e: Exception) {
                GatewayLogger.e("SMS", "Error processing incoming SMS messages", "Receiver", e)
            }
        }
    }

    private fun getSimSlotFromSubscription(context: Context, subscriptionId: Int): Int {
        return try {
            if (subscriptionId == SubscriptionManager.INVALID_SUBSCRIPTION_ID) {
                return 1 // Default SIM slot (1-based for server)
            }

            // Convert subscription ID to SIM slot index (1-based for server)
            when {
                android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP_MR1 -> {
                    val subscriptionManager = context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as SubscriptionManager
                    val subscriptionInfo = subscriptionManager.getActiveSubscriptionInfo(subscriptionId)
                    (subscriptionInfo?.simSlotIndex ?: 0) + 1 // Convert to 1-based
                }
                else -> 1 // Fallback for older API levels
            }
        } catch (e: Exception) {
            GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_could_not_determine_sim_slot, subscriptionId, e.message ?: ""), "Receiver")
            1 // Default SIM slot
        }
    }

    /**
     * Check if this app is the default SMS app (for compliance)
     */
    private fun isDefaultSmsApp(context: Context): Boolean {
        return try {
            if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.KITKAT) {
                Telephony.Sms.getDefaultSmsPackage(context) == context.packageName
            } else {
                false
            }
        } catch (e: Exception) {
            GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_could_not_check_default_sms, e.message ?: ""), "Receiver")
            false
        }
    }

    /**
     * Write SMS to system SMS database (minimal compliance requirement)
     */
    private fun writeToSmsDatabase(context: Context, smsData: SmsData) {
        try {
            val contentValues = ContentValues().apply {
                put(Telephony.Sms.ADDRESS, smsData.sender)
                put(Telephony.Sms.BODY, smsData.message)
                put(Telephony.Sms.DATE, smsData.timestamp)
                put(Telephony.Sms.DATE_SENT, smsData.timestamp)
                put(Telephony.Sms.READ, 1) // Mark as read
                put(Telephony.Sms.TYPE, Telephony.Sms.MESSAGE_TYPE_INBOX)
                if (smsData.subscriptionId != SubscriptionManager.INVALID_SUBSCRIPTION_ID) {
                    put(Telephony.Sms.SUBSCRIPTION_ID, smsData.subscriptionId)
                }
            }

            context.contentResolver.insert(Telephony.Sms.CONTENT_URI, contentValues)
            GatewayLogger.d("SMS", "SMS written to system database for compliance", "Receiver")
        } catch (e: Exception) {
            GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_could_not_write_sms_db, e.message ?: ""), "Receiver")
        }
    }

    /**
     * Data class for holding SMS information
     */
    private data class SmsData(
        val sender: String,
        val message: String,
        val timestamp: Long,
        val subscriptionId: Int
    )
}