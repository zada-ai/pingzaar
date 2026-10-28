package dev.titansys.smsgateway.sms.receivers

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * Minimal SMS delivery receiver for default SMS app compliance.
 * Following Burat's pattern - empty implementation that just satisfies manifest requirement.
 */
class SmsDeliveryReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        // Validate intent action to prevent spoofing
        if (intent.action != "android.provider.Telephony.SMS_DELIVER") return

        // Empty - just satisfies Android's default SMS app manifest requirement
        // SMS_DELIVER action handling for compliance
    }
}