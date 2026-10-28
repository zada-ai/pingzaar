package dev.titansys.smsgateway.helpers

import android.content.Context
import android.os.Build
import android.provider.Settings
import dev.titansys.smsgateway.helpers.GatewayLogger

class DeviceInfoProvider(private val context: Context) {
    companion object {
        private const val TAG = "DeviceInfoProvider"
        private const val PROBLEMATIC_ANDROID_ID = "9774d56d682e549c"
    }

    fun getAndroidId(): String? {
        val androidId = Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
        
        return if (androidId == null || androidId == PROBLEMATIC_ANDROID_ID) {
            GatewayLogger.e("DEV", "Invalid ANDROID_ID: $androidId", "DeviceHelper")
            null
        } else {
            androidId
        }
    }

    fun getDeviceModel(): String {
        return Build.MODEL
    }

    fun getDeviceVersion(): String {
        return Build.VERSION.RELEASE
    }

    fun getDeviceManufacturer(): String {
        return Build.MANUFACTURER
    }
} 