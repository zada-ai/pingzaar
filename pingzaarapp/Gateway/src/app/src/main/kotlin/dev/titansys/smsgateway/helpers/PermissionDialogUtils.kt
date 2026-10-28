package dev.titansys.smsgateway.helpers

import android.content.Context
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity

/**
 * Permission and dialog utility functions to replace commons library dependencies
 */

/**
 * Simple replacement for PermissionRequiredDialog
 * Replacement for ph.titansystems.commons.dialogs.PermissionRequiredDialog
 */
object PermissionRequiredDialog {
    fun show(
        context: Context,
        title: String = context.getString(dev.titansys.smsgateway.R.string.permission_required),
        message: String = context.getString(dev.titansys.smsgateway.R.string.permission_required_message),
        onPositive: () -> Unit = {},
        onNegative: () -> Unit = {}
    ) {
        AlertDialog.Builder(context)
            .setTitle(title)
            .setMessage(message)
            .setPositiveButton(context.getString(dev.titansys.smsgateway.R.string.grant)) { _, _ -> onPositive() }
            .setNegativeButton(context.getString(dev.titansys.smsgateway.R.string.cancel)) { _, _ -> onNegative() }
            .setCancelable(false)
            .show()
    }
}

/**
 * Simple app lock manager replacement
 * Replacement for commons appLockManager functionality
 */
object AppLockManager {
    /**
     * No-op lock function
     * Original function was used for app security which is not needed for Zender Gateway
     */
    fun lock() {
        // No-op replacement for commons appLockManager.lock()
        // Original function was used for app locking/security which we don't need
    }
}

/**
 * Extension property to provide app lock manager access on activities
 * Replacement for commons appLockManager extension
 */
val AppCompatActivity.appLockManager: AppLockManager
    get() = AppLockManager