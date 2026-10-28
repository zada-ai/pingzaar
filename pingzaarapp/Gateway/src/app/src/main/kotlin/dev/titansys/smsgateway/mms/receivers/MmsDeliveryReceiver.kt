package dev.titansys.smsgateway.mms.receivers

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * Minimal MMS delivery receiver for default SMS app compliance.
 * Following Burat's pattern - empty implementation that just satisfies manifest requirement.
 */
class MmsDeliveryReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        // Validate intent action to prevent spoofing
        if (intent.action != "android.provider.Telephony.WAP_PUSH_DELIVER") return

        // Empty - just satisfies Android's default SMS app manifest requirement
        // WAP_PUSH_DELIVER action handling for compliance
    }
}