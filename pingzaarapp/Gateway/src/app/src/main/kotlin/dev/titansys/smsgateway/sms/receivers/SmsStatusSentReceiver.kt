package dev.titansys.smsgateway.sms.receivers

import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.sms.SmsReportManager

class SmsStatusSentReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "SmsStatusSentReceiver"
    }

    override fun onReceive(context: Context, intent: Intent) {
        val resultCode = resultCode

        when (resultCode) {
            Activity.RESULT_OK -> {
                GatewayLogger.d("SMS", "SMS sent successfully", "StatusReceiver")
            }
            else -> {
                GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_sending_failed, resultCode), "StatusReceiver")
            }
        }
    }
}