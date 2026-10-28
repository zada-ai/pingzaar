package dev.titansys.smsgateway.mms.receivers

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.sms.workers.SmsReportWorker

/**
 * MMS Sent Receiver (Burat-inspired)
 *
 * Receives callbacks when MMS is sent via sendMultimediaMessage()
 * Reports actual send status to server based on result code
 */
class MmsSentReceiver : BroadcastReceiver() {
    companion object {
        private const val TAG = "MmsSentReceiver"
    }

    override fun onReceive(context: Context, intent: Intent) {
        GatewayLogger.d("MMS", "MMS sent callback received", TAG)

        // Extract message metadata (Int values)
        val uid = intent.getIntExtra("EXTRA_UID", 0)
        val mid = intent.getIntExtra("EXTRA_MID", 0)

        // Result code indicates success/failure (Burat style)
        when (resultCode) {
            android.app.Activity.RESULT_OK -> {
                // Result code -1 = Success
                GatewayLogger.i("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_sent_with_ids, uid, mid), TAG)

                // Report success to server
                SmsReportWorker.scheduleReport(
                    context = context,
                    uid = uid,
                    mid = mid,
                    success = true,
                    errorCode = "MMS_SENT"
                )
            }
            else -> {
                // Any other result code = Failed
                GatewayLogger.e("MMS", "MMS send failed with code: $resultCode (uid=$uid, mid=$mid)", TAG, null)

                // Report failure to server
                SmsReportWorker.scheduleReport(
                    context = context,
                    uid = uid,
                    mid = mid,
                    success = false,
                    errorCode = "MMS_FAILED"
                )
            }
        }
    }
}
