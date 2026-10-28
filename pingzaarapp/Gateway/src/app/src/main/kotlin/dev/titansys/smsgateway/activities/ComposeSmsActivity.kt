package dev.titansys.smsgateway.activities

import android.app.Activity
import android.os.Bundle

/**
 * Minimal SMS compose activity for default SMS app compliance.
 * Following Burat's pattern - immediately finishes to satisfy manifest requirement.
 */
class ComposeSmsActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Immediately close - just satisfies Android's default SMS app manifest requirement
        // Handles SEND/SENDTO intents for sms/smsto/mms/mmsto schemes
        finish()
    }
}