package dev.titansys.smsgateway.mms

/**
 * MMS PDU Helper Classes (Burat-inspired)
 *
 * Contains data structures for building MMS PDU (Protocol Data Unit):
 * - PduHeaders: MMS message headers
 * - PduBody: Container for multipart body
 * - PduPart: Individual part in multipart body
 * - EncodedStringValue: String with character set encoding
 */

/**
 * PDU Headers
 * Stores MMS message headers with field codes and values
 */
class PduHeaders {
    private val headers = mutableMapOf<Int, Any>()

    /**
     * Set octet (single byte) header value
     * @param value Header value (0-255)
     * @param field Header field code (e.g., 0x8c for Message-Type)
     */
    fun setOctet(value: Int, field: Int) {
        headers[field] = value
    }

    /**
     * Set text string header value
     * @param value Text as byte array
     * @param field Header field code
     */
    fun setTextString(value: ByteArray, field: Int) {
        headers[field] = value
    }

    /**
     * Set encoded string value (with charset)
     * @param value EncodedStringValue with charset and text
     * @param field Header field code
     */
    fun setEncodedStringValue(value: EncodedStringValue, field: Int) {
        headers[field] = value
    }

    /**
     * Set multiple encoded string values (for recipients, etc.)
     * @param values Array of EncodedStringValue
     * @param field Header field code
     */
    fun setEncodedStringValues(values: Array<EncodedStringValue>, field: Int) {
        headers[field] = values
    }

    /**
     * Set long integer header value (for timestamps, sizes, etc.)
     * @param value Long integer value
     * @param field Header field code
     */
    fun setLongInteger(value: Long, field: Int) {
        headers[field] = value
    }

    /**
     * Get all headers
     * @return Map of header field codes to values
     */
    fun getHeaders(): Map<Int, Any> = headers
}

/**
 * PDU Body
 * Container for all parts in an MMS message
 */
class PduBody {
    val parts = mutableListOf<PduPart>()

    /**
     * Add a part to the body
     * @param part PduPart to add
     */
    fun addPart(part: PduPart) {
        parts.add(part)
    }

    /**
     * Get part count
     * @return Number of parts in body
     */
    fun getPartsCount(): Int = parts.size
}

/**
 * PDU Part
 * Represents a single part in a multipart MMS message
 */
class PduPart {
    private val headers = mutableMapOf<Int, ByteArray>()
    private var data: ByteArray? = null

    /**
     * Set Content-Type header
     * @param value MIME type as byte array (e.g., "image/jpeg")
     */
    fun setContentType(value: ByteArray) {
        headers[0x91] = value
    }

    /**
     * Set Content-Location header
     * @param value Location string (e.g., filename without extension)
     */
    fun setContentLocation(value: ByteArray) {
        headers[0x8e] = value
    }

    /**
     * Set Content-ID header
     * Automatically wraps with angle brackets <> if not already wrapped
     *
     * CRITICAL: This is required for SMIL references to work correctly.
     * SMIL uses "cid:filename" which must match this Content-ID "<filename>"
     *
     * @param value Content ID bytes (will be wrapped with <> if needed)
     */
    fun setContentId(value: ByteArray) {
        val wrapped = if (value.size > 1 &&
                         value[0] == '<'.code.toByte() &&
                         value[value.size - 1] == '>'.code.toByte()) {
            // Already wrapped with <>
            value
        } else {
            // Wrap with <>
            ByteArray(value.size + 2).apply {
                this[0] = '<'.code.toByte()
                this[value.size + 1] = '>'.code.toByte()
                System.arraycopy(value, 0, this, 1, value.size)
            }
        }
        headers[0xc0] = wrapped
    }

    /**
     * Set Charset parameter
     * @param charset Charset code (0x6a for UTF-8)
     */
    fun setCharset(charset: Int) {
        headers[0x81] = byteArrayOf(charset.toByte())
    }

    /**
     * Set part data (content bytes)
     * @param data Content bytes
     */
    fun setData(data: ByteArray) {
        this.data = data
    }

    /**
     * Get part data
     * @return Content bytes or null
     */
    fun getData(): ByteArray? = data

    /**
     * Get all part headers
     * @return Map of header field codes to byte arrays
     */
    fun getHeaders(): Map<Int, ByteArray> = headers

    /**
     * Get content location for SMIL reference
     * Returns the content location without the Content-ID wrapper
     *
     * @return Content location string (e.g., "text_0", "image")
     */
    fun getContentLocation(): String {
        // Try Content-Location first
        val contentLoc = headers[0x8e]
        if (contentLoc != null) {
            return String(contentLoc, Charsets.UTF_8)
        }

        // Fall back to Content-ID (remove <> brackets)
        val contentId = headers[0xc0]
        if (contentId != null) {
            val str = String(contentId, Charsets.UTF_8)
            return str.removeSurrounding("<", ">")
        }

        return ""
    }
}

/**
 * Encoded String Value
 * String with character set encoding (for MMS headers)
 */
class EncodedStringValue(
    val text: String,
    val characterSet: Int = 106 // UTF-8 (0x6a)
) {
    val textString: ByteArray = text.toByteArray(Charsets.UTF_8)

    /**
     * Create from phone number string
     * @param text Phone number or address
     */
    constructor(text: String) : this(text, 106)
}
