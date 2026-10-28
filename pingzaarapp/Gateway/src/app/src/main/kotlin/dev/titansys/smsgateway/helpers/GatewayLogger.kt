package dev.titansys.smsgateway.helpers

import android.content.Context
import android.util.Log
import dev.titansys.smsgateway.activities.GatewayActivity

/**
 * Unified logging system for the Gateway application
 *
 * Provides efficient logging with foreground-aware UI updates and consistent development logging.
 * Eliminates background string creation overhead while maintaining full functionality when app is visible.
 *
 * Features:
 * - Foreground state detection to prevent unnecessary UI operations
 * - Lazy string evaluation for expensive operations
 * - Unified API replacing dual Log.d + logEvent patterns
 * - Consistent message formatting across all components
 * - Always maintains development logging via Android Log
 * - String resource support for localization
 */
object GatewayLogger {

    /**
     * Application context for accessing string resources
     * Initialized in App.onCreate() before any logging occurs
     * Internal visibility allows access from files using GatewayLogger for string resources
     */
    internal lateinit var appContext: Context

    /**
     * Initialize GatewayLogger with Application Context
     * MUST be called in App.onCreate() before any logging occurs
     *
     * @param context Application context (safe to store, won't leak)
     */
    fun initialize(context: Context) {
        appContext = context.applicationContext
        Log.d("GatewayLogger", "Initialized with Application Context")
    }

    /**
     * Tracks whether the app is currently in foreground and UI updates should occur
     */
    internal var isAppInForeground = false

    /**
     * Sets the foreground state of the application
     * Should be called from GatewayActivity lifecycle methods
     *
     * @param inForeground true when app is visible to user, false when backgrounded
     */
    fun setForegroundState(inForeground: Boolean) {
        isAppInForeground = inForeground
        Log.d("GatewayLogger", "Foreground state changed: $inForeground")
    }

    /**
     * Debug level logging
     *
     * Debug logs are technical implementation details for developers only.
     * They are logged to ADB but NOT displayed in the UI terminal to avoid
     * confusing users with subscription IDs, service lifecycle events, and
     * internal state tracking information.
     *
     * @param tag Component identifier (e.g. "SMS", "FCM", "DB")
     * @param message Log message content
     * @param context Optional context information (e.g. "QueueService", "Firebase")
     */
    fun d(tag: String, message: String, context: String? = null) {
        // Always log to development console for debugging
        android.util.Log.d("Gateway-$tag", formatLogMessage(message, context))

        // Debug logs are NOT shown in UI terminal - they're technical details
        // Users don't need to see: subscription IDs, service lifecycle,
        // delay calculations, or internal state tracking
    }

    /**
     * Debug level logging with lazy evaluation
     *
     * Debug logs are technical implementation details for developers only.
     * They are logged to ADB but NOT displayed in the UI terminal.
     *
     * @param tag Component identifier
     * @param context Optional context information
     * @param messageBuilder Lambda that produces the log message
     */
    fun d(tag: String, context: String? = null, messageBuilder: () -> String) {
        // Always create message for development logging
        val message = messageBuilder()
        android.util.Log.d("Gateway-$tag", formatLogMessage(message, context))

        // Debug logs are NOT shown in UI terminal - they're technical details
    }

    /**
     * Info level logging
     * Used for user-relevant status updates and important events
     *
     * @param tag Component identifier
     * @param message Log message content
     * @param context Optional context information
     */
    fun i(tag: String, message: String, context: String? = null) {
        android.util.Log.i("Gateway-$tag", formatLogMessage(message, context))

        if (isAppInForeground) {
            GatewayActivity.getInstance()?.logEvent(formatTerminalMessage(message, context))
        }
    }

    /**
     * Info level logging with lazy evaluation
     *
     * @param tag Component identifier
     * @param context Optional context information
     * @param messageBuilder Lambda that produces the log message
     */
    fun i(tag: String, context: String? = null, messageBuilder: () -> String) {
        val message = messageBuilder()
        android.util.Log.i("Gateway-$tag", formatLogMessage(message, context))

        if (isAppInForeground) {
            GatewayActivity.getInstance()?.logEvent(formatTerminalMessage(message, context))
        }
    }

    /**
     * Warning level logging
     * Used for recoverable errors and important status changes
     *
     * @param tag Component identifier
     * @param message Log message content
     * @param context Optional context information
     */
    fun w(tag: String, message: String, context: String? = null) {
        android.util.Log.w("Gateway-$tag", formatLogMessage(message, context))

        if (isAppInForeground) {
            GatewayActivity.getInstance()?.logEvent(formatTerminalMessage(message, context, "⚠️"))
        }
    }

    /**
     * Warning level logging with lazy evaluation
     *
     * @param tag Component identifier
     * @param context Optional context information
     * @param messageBuilder Lambda that produces the log message
     */
    fun w(tag: String, context: String? = null, messageBuilder: () -> String) {
        val message = messageBuilder()
        android.util.Log.w("Gateway-$tag", formatLogMessage(message, context))

        if (isAppInForeground) {
            GatewayActivity.getInstance()?.logEvent(formatTerminalMessage(message, context, "⚠️"))
        }
    }

    /**
     * Error level logging
     * Used for errors, exceptions, and critical failures
     *
     * Error messages are automatically translated to user-friendly versions for UI display
     * while preserving full technical details (error codes, stack traces) in ADB logs.
     *
     * @param tag Component identifier
     * @param message Log message content
     * @param context Optional context information
     * @param throwable Optional exception/throwable for stack trace
     */
    fun e(tag: String, message: String, context: String? = null, throwable: Throwable? = null) {
        // Always log full technical error to ADB with stack trace
        android.util.Log.e("Gateway-$tag", formatLogMessage(message, context), throwable)

        // Translate error to user-friendly version for UI terminal
        if (isAppInForeground) {
            val errorMessage = if (throwable != null) {
                "$message: ${throwable.message}"
            } else {
                message
            }
            val userFriendlyMessage = translateErrorMessage(errorMessage)
            GatewayActivity.getInstance()?.logEvent(formatTerminalMessage(userFriendlyMessage, context, "❌"))
        }
    }

    /**
     * Error level logging with lazy evaluation
     *
     * Error messages are automatically translated to user-friendly versions for UI display
     * while preserving full technical details (error codes, stack traces) in ADB logs.
     *
     * @param tag Component identifier
     * @param context Optional context information
     * @param throwable Optional exception/throwable for stack trace
     * @param messageBuilder Lambda that produces the log message
     */
    fun e(tag: String, context: String? = null, throwable: Throwable? = null, messageBuilder: () -> String) {
        val message = messageBuilder()
        // Always log full technical error to ADB with stack trace
        android.util.Log.e("Gateway-$tag", formatLogMessage(message, context), throwable)

        // Translate error to user-friendly version for UI terminal
        if (isAppInForeground) {
            val errorMessage = if (throwable != null) {
                "$message: ${throwable.message}"
            } else {
                message
            }
            val userFriendlyMessage = translateErrorMessage(errorMessage)
            GatewayActivity.getInstance()?.logEvent(formatTerminalMessage(userFriendlyMessage, context, "❌"))
        }
    }

    /**
     * Info level logging with localized string resources
     *
     * IMPORTANT: This method ensures ADB logs remain in English for developers
     * while UI terminal shows localized strings for users.
     *
     * @param tag Component identifier
     * @param messageResId String resource ID for the message
     * @param context Optional context information
     * @param formatArgs Format arguments for the string resource
     */
    fun i(tag: String, messageResId: Int, context: String? = null, vararg formatArgs: Any) {
        // ADB logging - ALWAYS in English for developers
        val englishMessage = getEnglishString(messageResId, *formatArgs)
        android.util.Log.i("Gateway-$tag", formatLogMessage(englishMessage, context))

        // UI terminal - localized for users
        if (isAppInForeground) {
            val localizedMessage = appContext.getString(messageResId, *formatArgs)
            GatewayActivity.getInstance()?.logEvent(formatTerminalMessage(localizedMessage, context))
        }
    }

    /**
     * Warning level logging with localized string resources
     *
     * IMPORTANT: This method ensures ADB logs remain in English for developers
     * while UI terminal shows localized strings for users.
     *
     * @param tag Component identifier
     * @param messageResId String resource ID for the message
     * @param context Optional context information
     * @param formatArgs Format arguments for the string resource
     */
    fun w(tag: String, messageResId: Int, context: String? = null, vararg formatArgs: Any) {
        // ADB logging - ALWAYS in English for developers
        val englishMessage = getEnglishString(messageResId, *formatArgs)
        android.util.Log.w("Gateway-$tag", formatLogMessage(englishMessage, context))

        // UI terminal - localized for users
        if (isAppInForeground) {
            val localizedMessage = appContext.getString(messageResId, *formatArgs)
            GatewayActivity.getInstance()?.logEvent(formatTerminalMessage(localizedMessage, context, "⚠️"))
        }
    }

    /**
     * Helper function to get English string regardless of current locale
     * Used to ensure ADB logs are always in English for developers
     *
     * @param resId String resource ID
     * @param formatArgs Format arguments
     * @return English version of the string
     */
    private fun getEnglishString(resId: Int, vararg formatArgs: Any): String {
        val config = android.content.res.Configuration(appContext.resources.configuration)
        config.setLocale(java.util.Locale.ENGLISH)
        val englishContext = appContext.createConfigurationContext(config)
        return englishContext.getString(resId, *formatArgs)
    }

    /**
     * Translates technical error messages into user-friendly versions
     *
     * Technical details (error codes, Java exceptions, internal state) are preserved
     * in ADB logs but hidden from UI terminal to avoid user confusion.
     *
     * Translation strategy:
     * 1. Map internal error codes (EM1xxx) to localized strings
     * 2. Simplify Java exception messages
     * 3. Convert technical jargon to user-understandable language
     * 4. Preserve original message in ADB for debugging
     *
     * @param message Original technical error message
     * @return User-friendly error message suitable for UI display
     */
    private fun translateErrorMessage(message: String): String {
        return when {
            // ===== INTERNAL ERROR CODES (EM1xxx series) =====
            // Network & Authentication (EM1001-EM1005)
            message.contains("EM1001") -> appContext.getString(dev.titansys.smsgateway.R.string.error_network_connection)
            message.contains("EM1002") -> appContext.getString(dev.titansys.smsgateway.R.string.error_authentication_failed)
            message.contains("EM1003") -> appContext.getString(dev.titansys.smsgateway.R.string.error_session_expired)
            message.contains("EM1004") -> appContext.getString(dev.titansys.smsgateway.R.string.error_server_timeout)
            message.contains("EM1005") -> appContext.getString(dev.titansys.smsgateway.R.string.error_data_parsing)

            // Database & Configuration (EM1006-EM1009)
            message.contains("EM1006") -> appContext.getString(dev.titansys.smsgateway.R.string.error_database)
            message.contains("EM1007") -> appContext.getString(dev.titansys.smsgateway.R.string.error_message_queue_processing)
            message.contains("EM1008") -> appContext.getString(dev.titansys.smsgateway.R.string.error_configuration)
            message.contains("EM1009") -> appContext.getString(dev.titansys.smsgateway.R.string.error_api_request_failed)

            // Messaging (EM1010-EM1014)
            message.contains("EM1010") -> appContext.getString(dev.titansys.smsgateway.R.string.error_message_sending_failed)
            message.contains("EM1011") -> appContext.getString(dev.titansys.smsgateway.R.string.error_device_registration)
            message.contains("EM1012") -> appContext.getString(dev.titansys.smsgateway.R.string.error_permission_denied)
            message.contains("EM1013") -> appContext.getString(dev.titansys.smsgateway.R.string.error_service_unavailable)
            message.contains("EM1014") -> appContext.getString(dev.titansys.smsgateway.R.string.error_invalid_request)

            // USSD (EM1015-EM1018)
            message.contains("EM1015") -> appContext.getString(dev.titansys.smsgateway.R.string.error_ussd_request_failed)
            message.contains("EM1016") -> appContext.getString(dev.titansys.smsgateway.R.string.error_ussd_timeout)
            message.contains("EM1017") -> appContext.getString(dev.titansys.smsgateway.R.string.error_ussd_permission_required)
            message.contains("EM1018") -> appContext.getString(dev.titansys.smsgateway.R.string.error_ussd_processing)

            // Campaign & Reporting (EM1019-EM1020, EM10027)
            message.contains("EM1019") -> appContext.getString(dev.titansys.smsgateway.R.string.error_campaign_processing)
            message.contains("EM1020") -> appContext.getString(dev.titansys.smsgateway.R.string.error_firebase_messaging)
            message.contains("EM10027") -> appContext.getString(dev.titansys.smsgateway.R.string.error_report_generation)

            // Generic error code pattern (catch-all for any EM code)
            message.contains("Unable to process request: EM") -> appContext.getString(dev.titansys.smsgateway.R.string.error_processing_occurred)
            message.matches(Regex(".*EM\\d+.*")) -> appContext.getString(dev.titansys.smsgateway.R.string.error_processing_occurred)

            // ===== TECHNICAL SUBSCRIPTION/SIM ERRORS =====
            message.contains("subscription ID") -> appContext.getString(dev.titansys.smsgateway.R.string.error_sim_configuration)
            message.contains("Subscription") && message.contains("not found") -> appContext.getString(dev.titansys.smsgateway.R.string.error_sim_not_detected)
            message.contains("READ_PHONE_STATE permission") -> appContext.getString(dev.titansys.smsgateway.R.string.error_phone_state_permission)
            message.contains("CALL_PHONE permission") -> appContext.getString(dev.titansys.smsgateway.R.string.error_call_permission_ussd)
            message.contains("SEND_SMS permission") -> appContext.getString(dev.titansys.smsgateway.R.string.error_sms_permission)
            message.contains("RECEIVE_SMS permission") -> appContext.getString(dev.titansys.smsgateway.R.string.error_sms_receive_permission)

            // ===== BROADCAST RECEIVER ERRORS =====
            // SMS-specific error codes from Android SmsManager
            message.contains("BroadcastReceiver: SMS failed - NO_SERVICE") -> appContext.getString(dev.titansys.smsgateway.R.string.error_no_cellular_service)
            message.contains("BroadcastReceiver: SMS failed - NULL_PDU") -> appContext.getString(dev.titansys.smsgateway.R.string.error_message_format)
            message.contains("BroadcastReceiver: SMS failed - RADIO_OFF") -> appContext.getString(dev.titansys.smsgateway.R.string.error_cellular_radio_off)
            message.contains("BroadcastReceiver: SMS failed - GENERIC_FAILURE") -> appContext.getString(dev.titansys.smsgateway.R.string.error_sms_sending_failed)
            message.contains("BroadcastReceiver: SMS failed - NO_DEFAULT_SMS_APP") -> appContext.getString(dev.titansys.smsgateway.R.string.error_no_default_sms_app)
            message.contains("BroadcastReceiver: SMS failed - RESULT_ERROR_RADIO_OFF") -> appContext.getString(dev.titansys.smsgateway.R.string.error_airplane_mode_enabled)

            // MMS-specific errors
            message.contains("BroadcastReceiver: MMS failed") -> appContext.getString(dev.titansys.smsgateway.R.string.error_mms_sending_failed)

            // ===== DATABASE ERRORS =====
            message.contains("database") && message.contains("lock", ignoreCase = true) -> appContext.getString(dev.titansys.smsgateway.R.string.error_database_busy)
            message.contains("database", ignoreCase = true) || message.contains("Database") -> appContext.getString(dev.titansys.smsgateway.R.string.error_data_storage)
            message.contains("SQLite") -> appContext.getString(dev.titansys.smsgateway.R.string.error_data_storage)
            message.contains("Cannot open database") -> appContext.getString(dev.titansys.smsgateway.R.string.error_database_initialization)

            // ===== NETWORK ERRORS =====
            // Java exception types
            message.contains("IOException") -> appContext.getString(dev.titansys.smsgateway.R.string.error_network_connection)
            message.contains("SocketTimeoutException") -> appContext.getString(dev.titansys.smsgateway.R.string.error_connection_timeout)
            message.contains("UnknownHostException") -> appContext.getString(dev.titansys.smsgateway.R.string.error_server_unreachable)
            message.contains("ConnectException") -> appContext.getString(dev.titansys.smsgateway.R.string.error_unable_to_connect)
            message.contains("SSLException") -> appContext.getString(dev.titansys.smsgateway.R.string.error_secure_connection)
            message.contains("NetworkOnMainThreadException") -> appContext.getString(dev.titansys.smsgateway.R.string.error_network_configuration)

            // ===== API/HTTP ERRORS =====
            message.contains("HTTP 401") -> appContext.getString(dev.titansys.smsgateway.R.string.error_authentication_failed)
            message.contains("HTTP 403") -> appContext.getString(dev.titansys.smsgateway.R.string.error_http_forbidden)
            message.contains("HTTP 404") -> appContext.getString(dev.titansys.smsgateway.R.string.error_http_not_found)
            message.contains("HTTP 500") -> appContext.getString(dev.titansys.smsgateway.R.string.error_http_server_error)
            message.contains("HTTP 503") -> appContext.getString(dev.titansys.smsgateway.R.string.error_http_service_unavailable)

            // ===== JAVA EXCEPTION CLEANUP =====
            // Extract just the meaningful part, hide exception class names
            message.contains("Exception:") -> {
                val parts = message.split("Exception:", limit = 2)
                if (parts.size > 1) {
                    parts[1].trim().takeIf { it.isNotBlank() } ?: appContext.getString(dev.titansys.smsgateway.R.string.error_an_error_occurred)
                } else {
                    appContext.getString(dev.titansys.smsgateway.R.string.error_an_error_occurred)
                }
            }

            message.contains("Error:") -> {
                val parts = message.split("Error:", limit = 2)
                if (parts.size > 1) {
                    parts[1].trim().takeIf { it.isNotBlank() } ?: appContext.getString(dev.titansys.smsgateway.R.string.error_an_error_occurred)
                } else {
                    appContext.getString(dev.titansys.smsgateway.R.string.error_an_error_occurred)
                }
            }

            // ===== FIREBASE/FCM ERRORS =====
            message.contains("FCM token") && message.contains("null") -> appContext.getString(dev.titansys.smsgateway.R.string.error_firebase_configuration)
            message.contains("Firebase") && message.contains("not initialized") -> appContext.getString(dev.titansys.smsgateway.R.string.error_firebase_initialization)

            // ===== USSD SPECIFIC ERRORS =====
            message.contains("CompletableDeferred") -> appContext.getString(dev.titansys.smsgateway.R.string.error_ussd_timeout)
            message.contains("USSD code") && message.contains("invalid") -> appContext.getString(dev.titansys.smsgateway.R.string.error_ussd_invalid_code)

            // ===== DEFAULT =====
            // If message is already user-friendly (no technical jargon), pass through
            else -> message
        }
    }

    /**
     * Formats log message for development console (Android Log)
     *
     * @param message Base message content
     * @param context Optional context to include
     * @return Formatted message for development logging
     */
    internal fun formatLogMessage(message: String, context: String?): String {
        return if (context != null) {
            "[$context] $message"
        } else {
            message
        }
    }

    /**
     * Formats log message for terminal UI display
     * Includes optional emoji prefix for visual categorization
     *
     * @param message Base message content
     * @param context Optional context to include
     * @param emoji Optional emoji prefix for message type
     * @return Formatted message for terminal display
     */
    internal fun formatTerminalMessage(message: String, context: String?, emoji: String? = null): String {
        val prefix = emoji ?: ""
        val contextStr = context?.let { " [$it]" } ?: ""
        return "$prefix$contextStr $message".trim()
    }

    /**
     * Get current foreground state for debugging purposes
     *
     * @return true if app is currently in foreground
     */
    fun isInForeground(): Boolean = isAppInForeground
}