package dev.titansys.smsgateway.services

import android.app.Service
import android.content.Intent
import dev.titansys.smsgateway.helpers.GatewayLogger

class SmsHeadlessService : Service() {
    override fun onBind(intent: Intent?) = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        GatewayLogger.d("SVC", "Headless SMS service for default SMS app compliance", "HeadlessService")
        return START_NOT_STICKY
    }
}

