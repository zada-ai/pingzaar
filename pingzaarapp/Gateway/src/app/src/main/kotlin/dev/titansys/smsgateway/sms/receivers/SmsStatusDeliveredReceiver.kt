package dev.titansys.smsgateway.sms.receivers

import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import dev.titansys.smsgateway.helpers.GatewayLogger

class SmsStatusDeliveredReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "SmsStatusDeliveredReceiver"
    }

    override fun onReceive(context: Context, intent: Intent) {
        val resultCode = resultCode

        when (resultCode) {
            Activity.RESULT_OK -> {
                GatewayLogger.d("SMS", "SMS delivered successfully", "StatusReceiver")
            }
            else -> {
                GatewayLogger.w("SMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_sms_delivery_failed, resultCode), "StatusReceiver")
            }
        }
    }
}