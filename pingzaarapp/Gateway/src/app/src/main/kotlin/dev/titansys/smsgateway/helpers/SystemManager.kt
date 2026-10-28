package dev.titansys.smsgateway.helpers

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import androidx.activity.result.ActivityResultLauncher
import androidx.appcompat.app.AppCompatActivity
import dev.titansys.smsgateway.helpers.GatewayLogger
import com.google.android.material.dialog.MaterialAlertDialogBuilder

/**
 * System management utilities for power management and service state persistence
 * Consolidated from PowerManagementHelper.kt and ServiceStateManager.kt
 */

// ====== Power Management ======

class PowerManagementHelper(private val context: Context) {

    /**
     * Checks if battery optimization is enabled for this app
     * @return true if battery optimization is enabled, false otherwise
     */
    fun isBatteryOptimizationEnabled(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) {
            return false
        }
        val powerManager = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        return !powerManager.isIgnoringBatteryOptimizations(context.packageName)
    }

    /**
     * Shows a dialog to request battery optimization permission
     * Uses ActivityResultLauncher for reliable native dialog (Burat's approach)
     */
    fun showBatteryOptimizationRequest(
        activity: AppCompatActivity,
        launcher: ActivityResultLauncher<Intent>
    ) {
        if (isBatteryOptimizationEnabled()) {
            MaterialAlertDialogBuilder(activity)
                .setTitle(context.getString(dev.titansys.smsgateway.R.string.battery_exclusion_required))
                .setMessage(context.getString(dev.titansys.smsgateway.R.string.battery_exclusion_message))
                .setPositiveButton(context.getString(dev.titansys.smsgateway.R.string.configure)) { _, _ ->
                    launchBatteryOptimizationRequest(activity, launcher)
                }
                .setCancelable(false)
                .show()
        }
    }

    /**
     * Launches battery optimization request using ActivityResultLauncher
     * This ensures native dialog shows every time (Burat's approach)
     */
    private fun launchBatteryOptimizationRequest(
        activity: AppCompatActivity,
        launcher: ActivityResultLauncher<Intent>
    ) {
        try {
            val intent = Intent().apply {
                action = Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS
                data = Uri.parse("package:${activity.packageName}")
            }
            launcher.launch(intent)
            GatewayLogger.d("SYS", "Launched battery optimization request", "PowerMgmt")
        } catch (e: Exception) {
            GatewayLogger.e("SYS", "Error launching battery optimization: ${e.message}", "PowerMgmt", e)
            showErrorDialog(activity, "Could not request battery optimization permission.")
        }
    }

    /**
     * Shows an error dialog with the given message
     */
    private fun showErrorDialog(activity: AppCompatActivity, message: String) {
        MaterialAlertDialogBuilder(activity)
            .setTitle(context.getString(dev.titansys.smsgateway.R.string.error))
            .setMessage(message)
            .setPositiveButton(context.getString(dev.titansys.smsgateway.R.string.ok), null)
            .show()
    }
}

// ====== Service State Management ======

/**
 * Manages persistent service state across app restarts and device reboots.
 * This ensures services can recover their previous running state after interruptions.
 */
object ServiceStateManager {
    private const val TAG = "ServiceStateManager"
    private const val PREF_NAME = "PREF_SETTINGS"
    private const val KEY_SERVICE_RUNNING = "PREF_SERVICE_RUNNING"
    private const val KEY_USSD_SERVICE_RUNNING = "PREF_USSD_SERVICE_RUNNING"

    /**
     * Set whether the SMS service is currently running
     * @param context Application context
     * @param running True if service is running, false otherwise
     */
    fun setServiceRunning(context: Context, running: Boolean) {
        try {
            context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
                .edit()
                .putBoolean(KEY_SERVICE_RUNNING, running)
                .apply()
            GatewayLogger.d("SYS", "SMS service state set to: $running", "Manager")
        } catch (e: Exception) {
            GatewayLogger.e("SYS", "Error setting service state: ${e.message}", "Manager", e)
        }
    }

    /**
     * Check if the SMS service was running before app/device restart
     * @param context Application context
     * @return True if service was running, false otherwise
     */
    fun isServiceRunning(context: Context): Boolean {
        return try {
            val isRunning = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
                .getBoolean(KEY_SERVICE_RUNNING, false)
            GatewayLogger.d("SYS", "SMS service state is: $isRunning", "Manager")
            isRunning
        } catch (e: Exception) {
            GatewayLogger.e("SYS", "Error reading service state: ${e.message}", "Manager", e)
            false
        }
    }

    /**
     * Set whether the USSD service is currently running
     * @param context Application context
     * @param running True if service is running, false otherwise
     */
    fun setUssdServiceRunning(context: Context, running: Boolean) {
        try {
            context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
                .edit()
                .putBoolean(KEY_USSD_SERVICE_RUNNING, running)
                .apply()
            GatewayLogger.d("SYS", "USSD service state set to: $running", "Manager")
        } catch (e: Exception) {
            GatewayLogger.e("SYS", "Error setting USSD service state: ${e.message}", "Manager", e)
        }
    }

    /**
     * Check if the USSD service was running before app/device restart
     * @param context Application context
     * @return True if service was running, false otherwise
     */
    fun isUssdServiceRunning(context: Context): Boolean {
        return try {
            val isRunning = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
                .getBoolean(KEY_USSD_SERVICE_RUNNING, false)
            GatewayLogger.d("SYS", "USSD service state is: $isRunning", "Manager")
            isRunning
        } catch (e: Exception) {
            GatewayLogger.e("SYS", "Error reading USSD service state: ${e.message}", "Manager", e)
            false
        }
    }
}