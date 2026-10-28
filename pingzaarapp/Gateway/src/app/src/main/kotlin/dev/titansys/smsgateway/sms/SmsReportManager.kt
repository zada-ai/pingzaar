package dev.titansys.smsgateway.sms

import android.content.Context
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.services.ReportRequest
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.helpers.ScheduledTaskManager
import dev.titansys.smsgateway.models.InternalErrorCode
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

class SmsReportManager {
    private val TAG = "SmsReportManager"
    private val USELOG = false
    private val scope = CoroutineScope(Dispatchers.IO)

    private fun log(message: String) {
        if (USELOG) {
            GatewayLogger.d("RPT", message, "ReportManager")
        }
    }

    suspend fun sendReport(
        context: Context,
        uid: Int,
        mid: Int,
        success: Boolean = true,
        errorCode: String? = null
    ) {
        try {
            log("Preparing to send delivery report - UID: $uid, MID: $mid, Success: $success")
            
            // Get hash and device ID from session
            val sessionManager = SessionManager(context)
            val deviceHelper = DeviceInfoProvider(context)
            val hash = sessionManager.getHash()
            val deviceId = deviceHelper.getAndroidId()

            if (hash == null || deviceId == null) {
                log("Missing hash or device ID for delivery report")
                GatewayLogger.e("RPT", "Unable to process request: EM10027", "ReportManager")
                return
            }

            // Use Burat-inspired worker scheduling instead of direct API call
            log("Scheduling delivery report worker for background sync")
            ScheduledTaskManager.scheduleMessageStatusSync(
                context = context,
                uid = uid,
                mid = mid,
                status = if (success) "success" else "failed",
                errorCode = errorCode
            )

            log("Delivery report scheduled for background processing - UID: $uid, MID: $mid")
            GatewayLogger.i("RPT", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_delivery_report_scheduled, mid), "ReportManager")
        } catch (e: Exception) {
            log("Error sending delivery report: ${e.message}")
            GatewayLogger.e("RPT", "Unable to process request: EM10029", "ReportManager")
        }
    }

    /**
     * Enhanced error reporting with InternalErrorCode support
     * Maintains API compatibility while providing better internal error tracking
     */
    suspend fun sendReport(
        context: Context,
        uid: Int,
        mid: Int,
        success: Boolean = true,
        errorCode: InternalErrorCode = InternalErrorCode.SUCCESS
    ) {
        // Call the original method with enhanced error code
        sendReport(context, uid, mid, success, errorCode.code)

        // Additional internal logging for enhanced error tracking
        if (!success) {
            log("Enhanced error report - Code: ${errorCode.code}, Description: ${errorCode.description}")
        }
    }

    /**
     * Report error from exception with automatic error code mapping
     */
    suspend fun sendReportFromException(
        context: Context,
        uid: Int,
        mid: Int,
        exception: Throwable
    ) {
        val errorCode = InternalErrorCode.fromException(exception)
        sendReport(context, uid, mid, false, errorCode)

        log("Exception mapped to error code: ${errorCode.code} - ${exception.message}")
    }

    /**
     * Report SMS result with automatic result code mapping
     */
    suspend fun sendReportFromSmsResult(
        context: Context,
        uid: Int,
        mid: Int,
        resultCode: Int
    ) {
        val errorCode = InternalErrorCode.fromSmsResultCode(resultCode)
        val success = resultCode == android.app.Activity.RESULT_OK

        sendReport(context, uid, mid, success, errorCode)

        log("SMS result code $resultCode mapped to: ${errorCode.code}")
    }

    /**
     * Enhanced error mapping for common SMS/USSD scenarios
     */
    fun mapErrorFromMessage(errorMessage: String?): InternalErrorCode {
        if (errorMessage.isNullOrEmpty()) {
            return InternalErrorCode.UNKNOWN_ERROR
        }

        return when {
            errorMessage.contains("permission", true) -> InternalErrorCode.PERMISSION_DENIED
            errorMessage.contains("network", true) -> InternalErrorCode.NETWORK_ERROR
            errorMessage.contains("timeout", true) -> InternalErrorCode.NETWORK_TIMEOUT
            errorMessage.contains("sim", true) -> InternalErrorCode.SIM_NOT_READY
            errorMessage.contains("radio", true) -> InternalErrorCode.RADIO_OFF
            errorMessage.contains("service", true) -> InternalErrorCode.SERVICE_UNAVAILABLE
            errorMessage.contains("invalid", true) && errorMessage.contains("number", true) -> InternalErrorCode.INVALID_DESTINATION
            errorMessage.contains("queue", true) -> InternalErrorCode.QUEUE_FULL
            errorMessage.contains("ussd", true) -> InternalErrorCode.USSD_REQUEST_FAILED
            else -> InternalErrorCode.UNKNOWN_ERROR
        }
    }

    /**
     * Convenience method for reporting with automatic error detection
     */
    suspend fun sendReportWithAutoDetection(
        context: Context,
        uid: Int,
        mid: Int,
        success: Boolean,
        errorMessage: String? = null,
        exception: Throwable? = null
    ) {
        val errorCode = when {
            success -> InternalErrorCode.SUCCESS
            exception != null -> InternalErrorCode.fromException(exception)
            errorMessage != null -> mapErrorFromMessage(errorMessage)
            else -> InternalErrorCode.UNKNOWN_ERROR
        }

        sendReport(context, uid, mid, success, errorCode)
    }
} 