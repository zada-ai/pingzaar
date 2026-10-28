package dev.titansys.smsgateway.models

/**
 * Internal error codes for enhanced SMS and USSD error tracking.
 * These codes are used internally for better debugging and monitoring
 * while maintaining compatibility with the existing server API.
 */
enum class InternalErrorCode(val code: String, val description: String) {
    // Success codes
    SUCCESS("0", "Message sent successfully"),

    // Network related errors (1000-1099)
    NETWORK_ERROR("NET001", "Network connectivity issue"),
    NETWORK_TIMEOUT("NET002", "Network request timeout"),
    SERVER_ERROR("NET003", "Server communication error"),

    // SIM/Hardware related errors (1100-1199)
    SIM_NOT_READY("SIM001", "SIM card not ready"),
    SIM_ABSENT("SIM002", "No SIM card detected"),
    SIM_LOCKED("SIM003", "SIM card is locked"),
    RADIO_OFF("SIM004", "Radio/Airplane mode is on"),

    // Permission related errors (1200-1299)
    PERMISSION_DENIED("PERM001", "SMS permission not granted"),
    PHONE_PERMISSION_DENIED("PERM002", "Phone permission not granted"),

    // Message related errors (1300-1399)
    INVALID_DESTINATION("MSG001", "Invalid phone number"),
    MESSAGE_TOO_LONG("MSG002", "Message exceeds length limit"),
    EMPTY_MESSAGE("MSG003", "Message content is empty"),
    INVALID_SUBSCRIPTION("MSG004", "Invalid SIM subscription"),

    // Service related errors (1400-1499)
    SERVICE_UNAVAILABLE("SRV001", "SMS service temporarily unavailable"),
    SERVICE_NOT_SUPPORTED("SRV002", "SMS service not supported on this device"),
    QUEUE_FULL("SRV003", "Message queue is full"),

    // SMS-specific errors (1500-1599)
    SMS_SEND_FAILED("SMS001", "SMS send operation failed"),
    SMS_DELIVERY_FAILED("SMS002", "SMS delivery failed"),
    SMS_RESULT_ERROR_GENERIC_FAILURE("SMS003", "Generic SMS failure"),
    SMS_RESULT_ERROR_NO_SERVICE("SMS004", "No SMS service available"),
    SMS_RESULT_ERROR_NULL_PDU("SMS005", "Invalid SMS PDU"),
    SMS_RESULT_ERROR_RADIO_OFF("SMS006", "Radio is turned off"),

    // USSD-specific errors (1600-1699)
    USSD_REQUEST_FAILED("USSD001", "USSD request failed"),
    USSD_RESPONSE_TIMEOUT("USSD002", "USSD response timeout"),
    USSD_NOT_SUPPORTED("USSD003", "USSD not supported on this device"),
    USSD_NETWORK_ERROR("USSD004", "USSD network error"),

    // Application errors (1700-1799)
    DATABASE_ERROR("APP001", "Database operation failed"),
    CONFIGURATION_ERROR("APP002", "Invalid configuration"),
    MEMORY_ERROR("APP003", "Insufficient memory"),

    // Unknown/Generic errors (9999)
    UNKNOWN_ERROR("UNK001", "Unknown error occurred");

    companion object {
        /**
         * Map common Android SMS result codes to internal error codes
         */
        fun fromSmsResultCode(resultCode: Int): InternalErrorCode {
            return when (resultCode) {
                android.app.Activity.RESULT_OK -> SUCCESS
                android.telephony.SmsManager.RESULT_ERROR_GENERIC_FAILURE -> SMS_RESULT_ERROR_GENERIC_FAILURE
                android.telephony.SmsManager.RESULT_ERROR_NO_SERVICE -> SMS_RESULT_ERROR_NO_SERVICE
                android.telephony.SmsManager.RESULT_ERROR_NULL_PDU -> SMS_RESULT_ERROR_NULL_PDU
                android.telephony.SmsManager.RESULT_ERROR_RADIO_OFF -> SMS_RESULT_ERROR_RADIO_OFF
                else -> UNKNOWN_ERROR
            }
        }

        /**
         * Map common exceptions to internal error codes
         */
        fun fromException(exception: Throwable): InternalErrorCode {
            return when {
                exception.message?.contains("permission", true) == true -> PERMISSION_DENIED
                exception.message?.contains("network", true) == true -> NETWORK_ERROR
                exception.message?.contains("timeout", true) == true -> NETWORK_TIMEOUT
                exception.message?.contains("sim", true) == true -> SIM_NOT_READY
                exception.message?.contains("radio", true) == true -> RADIO_OFF
                exception.message?.contains("service", true) == true -> SERVICE_UNAVAILABLE
                exception is java.net.SocketTimeoutException -> NETWORK_TIMEOUT
                exception is java.net.UnknownHostException -> NETWORK_ERROR
                exception is SecurityException -> PERMISSION_DENIED
                else -> UNKNOWN_ERROR
            }
        }
    }
}