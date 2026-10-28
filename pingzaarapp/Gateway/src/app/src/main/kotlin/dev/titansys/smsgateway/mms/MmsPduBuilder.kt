package dev.titansys.smsgateway.mms

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.telephony.SubscriptionManager
import android.telephony.TelephonyManager
import androidx.core.content.ContextCompat
import dev.titansys.smsgateway.helpers.GatewayLogger

/**
 * MMS PDU Builder (Burat-inspired)
 *
 * Builds complete MMS PDU (Protocol Data Unit) with text + attachments support.
 * Generates proper SMIL document for multipart MMS messages.
 *
 * Based on Burat's production-tested implementation with full WAP binary encoding.
 *
 * @param context Android context
 * @param simSlot SIM slot index (null for default)
 * @param recipients Array of recipient phone numbers
 * @param parts List of MMS parts (text, images, audio, video)
 */
class MmsPduBuilder(
    private val context: Context,
    private val simSlot: Int?,
    private val recipients: Array<String>,
    private val parts: List<MmsPart>
) {
    companion object {
        private const val TAG = "MmsPduBuilder"

        // MMS Header field codes (WAP specification)
        const val HEADER_MESSAGE_TYPE = 0x8c
        const val HEADER_TRANSACTION_ID = 0x98
        const val HEADER_MMS_VERSION = 0x8d
        const val HEADER_CONTENT_TYPE = 0x84
        const val HEADER_FROM = 0x89
        const val HEADER_TO = 0x97
        const val HEADER_DATE = 0x85
        const val HEADER_MESSAGE_SIZE = 0x8e
        const val HEADER_MESSAGE_CLASS = 0x8a
        const val HEADER_EXPIRY = 0x88
        const val HEADER_PRIORITY = 0x8f
        const val HEADER_DELIVERY_REPORT = 0x86
        const val HEADER_READ_REPORT = 0x90

        // Part header field codes
        const val PART_CONTENT_TYPE = 0x91
        const val PART_CONTENT_LOCATION = 0x8e
        const val PART_CONTENT_ID = 0xc0
        const val PART_CHARSET = 0x81

        // Constants (Burat's values)
        const val MESSAGE_TYPE_SEND_REQ = 0x80
        const val MMS_VERSION_1_2 = 0x12
        const val CHARSET_UTF8 = 0x6a
        const val PRIORITY_NORMAL = 0x81
        const val EXPIRY_7_DAYS = 604800L // 7 days in seconds
    }

    /**
     * Build complete MMS PDU
     *
     * Steps:
     * 1. Create headers with transaction ID, version, content-type, etc.
     * 2. Build multipart body from MmsParts
     * 3. Generate SMIL XML document
     * 4. Insert SMIL as first part (index 0)
     * 5. Encode to WAP binary PDU
     *
     * @return Complete PDU as byte array
     */
    fun buildPdu(): ByteArray {
        GatewayLogger.d("MMS", "Building PDU with ${parts.size} parts for ${recipients.size} recipients", TAG)

        // 1. Create headers
        val headers = PduHeaders()
        setMmsHeaders(headers)

        // 2. Build multipart body
        val body = PduBody()
        addPartsToBody(body)

        // 3. Generate SMIL
        val smilBytes = generateSmil()
        GatewayLogger.d("MMS", "Generated SMIL: ${smilBytes.size} bytes", TAG)

        // 4. Create SMIL part and insert as first part (CRITICAL)
        val smilPart = createSmilPart(smilBytes)
        body.parts.add(0, smilPart)

        // 5. Update message size in headers
        val totalSize = body.parts.sumOf { (it.getData()?.size ?: 0) }
        headers.setLongInteger(totalSize.toLong(), HEADER_MESSAGE_SIZE)

        // 6. Encode to PDU
        val composer = PduComposer(headers, body)
        val pduBytes = composer.compose()

        GatewayLogger.i("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_built_pdu_total, pduBytes.size), TAG)
        return pduBytes
    }

    /**
     * Set all MMS headers (Burat-style)
     */
    private fun setMmsHeaders(headers: PduHeaders) {
        // Message type: m-send-req (0x80)
        headers.setOctet(MESSAGE_TYPE_SEND_REQ, HEADER_MESSAGE_TYPE)

        // Transaction ID: "T" + hex timestamp (unique identifier)
        val transactionId = "T${System.currentTimeMillis().toString(16)}"
        headers.setTextString(transactionId.toByteArray(), HEADER_TRANSACTION_ID)
        GatewayLogger.d("MMS", "Transaction ID: $transactionId", TAG)

        // MMS version 1.2 (0x12)
        headers.setOctet(MMS_VERSION_1_2, HEADER_MMS_VERSION)

        // Content type: multipart/related (required for SMIL)
        headers.setTextString(
            "application/vnd.wap.multipart.related".toByteArray(),
            HEADER_CONTENT_TYPE
        )

        // From: phone number (if available)
        val phoneNumber = getPhoneNumber(context, simSlot)
        if (phoneNumber != null) {
            GatewayLogger.d("MMS", "From: $phoneNumber", TAG)
            headers.setEncodedStringValue(
                EncodedStringValue(phoneNumber),
                HEADER_FROM
            )
        }

        // To: recipients
        val encodedRecipients = recipients.map { EncodedStringValue(it) }.toTypedArray()
        headers.setEncodedStringValues(encodedRecipients, HEADER_TO)
        GatewayLogger.d("MMS", "To: ${recipients.joinToString()}", TAG)

        // Date: current timestamp in seconds
        val date = System.currentTimeMillis() / 1000
        headers.setLongInteger(date, HEADER_DATE)

        // Message class: personal
        headers.setTextString("personal".toByteArray(), HEADER_MESSAGE_CLASS)

        // Expiry: 7 days (Burat's value)
        headers.setLongInteger(EXPIRY_7_DAYS, HEADER_EXPIRY)

        // Priority: normal (0x81)
        headers.setOctet(PRIORITY_NORMAL, HEADER_PRIORITY)

        // Delivery report: yes
        headers.setOctet(PRIORITY_NORMAL, HEADER_DELIVERY_REPORT)

        // Read report: yes
        headers.setOctet(PRIORITY_NORMAL, HEADER_READ_REPORT)
    }

    /**
     * Add all MMS parts to body
     */
    private fun addPartsToBody(body: PduBody) {
        for ((index, mmsPart) in parts.withIndex()) {
            val part = PduPart()

            // Content-Type: MIME type
            part.setContentType(mmsPart.mimeType.toByteArray())

            // Content-Location: filename without extension
            val nameWithoutExt = mmsPart.name.substringBeforeLast('.', mmsPart.name)
            part.setContentLocation(nameWithoutExt.toByteArray())

            // Content-ID: <filename> (auto-wraps with <>)
            part.setContentId(nameWithoutExt.toByteArray())

            // Charset: UTF-8 for text parts
            if (mmsPart.mimeType == "text/plain") {
                part.setCharset(CHARSET_UTF8)
            }

            // Data: content bytes
            part.setData(mmsPart.data)

            body.addPart(part)

            GatewayLogger.d("MMS", "Added part ${index + 1}: ${mmsPart.mimeType}, ${mmsPart.data.size} bytes", TAG)
        }
    }

    /**
     * Generate SMIL XML document (CRITICAL for text + attachment display)
     *
     * SMIL (Synchronized Multimedia Integration Language) tells MMS clients
     * how to display text and media together in a "slide show" format.
     *
     * Format:
     * <smil xmlns="http://www.w3.org/2001/SMIL20/Language">
     *   <head><layout/></head>
     *   <body>
     *     <par>
     *       <text src="cid:text_0"/>
     *       <img src="cid:image"/>
     *     </par>
     *   </body>
     * </smil>
     *
     * CRITICAL RULES:
     * 1. Content-IDs in SMIL (cid:filename) MUST match PduPart Content-IDs (<filename>)
     * 2. Use <par> (parallel) to show text and media together
     * 3. Create new <par> when both text and media exist (Burat's logic)
     * 4. Must use UTF-8 encoding
     *
     * @return SMIL XML as UTF-8 byte array
     */
    private fun generateSmil(): ByteArray {
        val smil = buildString {
            append("<smil xmlns=\"http://www.w3.org/2001/SMIL20/Language\">")
            append("<head><layout/></head>")
            append("<body>")

            var currentPar = StringBuilder("<par>")
            var hasText = false
            var hasMedia = false

            for (part in parts) {
                // Get content location (filename without extension)
                val contentLocation = "cid:${part.name.substringBeforeLast('.', part.name)}"

                // Create appropriate SMIL element based on MIME type
                val element = when {
                    // Text content
                    part.mimeType == "text/plain" || part.mimeType == "text/html" -> {
                        hasText = true
                        "<text src=\"$contentLocation\"/>"
                    }

                    // Image content
                    part.mimeType.startsWith("image/") -> {
                        hasMedia = true
                        "<img src=\"$contentLocation\"/>"
                    }

                    // Video content
                    part.mimeType.startsWith("video/") -> {
                        hasMedia = true
                        "<video src=\"$contentLocation\"/>"
                    }

                    // Audio content
                    part.mimeType.startsWith("audio/") -> {
                        hasMedia = true
                        "<audio src=\"$contentLocation\"/>"
                    }

                    // vCard content
                    part.mimeType == "text/x-vCard" -> {
                        hasMedia = true
                        "<ref src=\"$contentLocation\"/>"
                    }

                    else -> {
                        GatewayLogger.w("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_unknown_mime_type, part.mimeType), TAG)
                        ""
                    }
                }

                currentPar.append(element)

                // Burat's logic: Create new <par> if we have both text and media
                // This ensures text and media display together in same "slide"
                if (hasText && hasMedia) {
                    currentPar.append("</par>")
                    append(currentPar.toString())
                    currentPar = StringBuilder("<par>")
                    hasText = false
                    hasMedia = false
                }
            }

            // Close last par
            currentPar.append("</par>")
            append(currentPar.toString())

            append("</body>")
            append("</smil>")
        }

        GatewayLogger.d("MMS", "SMIL XML: $smil", TAG)
        return smil.toByteArray(Charsets.UTF_8)
    }

    /**
     * Create SMIL PDU part
     * SMIL must be the first part (index 0) in the MMS body
     *
     * @param smilBytes SMIL XML bytes
     * @return PduPart containing SMIL
     */
    private fun createSmilPart(smilBytes: ByteArray): PduPart {
        val part = PduPart()
        part.setContentType("application/smil".toByteArray())
        part.setContentLocation("smil.xml".toByteArray())
        part.setContentId("smil".toByteArray())
        part.setData(smilBytes)
        return part
    }

    /**
     * Get phone number from SIM slot
     *
     * @param context Android context
     * @param simSlot SIM slot index
     * @return Phone number or null
     */
    private fun getPhoneNumber(context: Context, simSlot: Int?): String? {
        return try {
            val telephonyManager = context.getSystemService(Context.TELEPHONY_SERVICE) as TelephonyManager

            if (simSlot != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP_MR1) {
                // Multi-SIM support
                if (ContextCompat.checkSelfPermission(
                        context,
                        Manifest.permission.READ_PHONE_NUMBERS
                    ) == PackageManager.PERMISSION_GRANTED
                ) {
                    val subscriptionManager = context.getSystemService(Context.TELEPHONY_SUBSCRIPTION_SERVICE) as SubscriptionManager
                    val subscriptionInfoList = subscriptionManager.activeSubscriptionInfoList
                    if (subscriptionInfoList != null && simSlot < subscriptionInfoList.size) {
                        val subscriptionInfo = subscriptionInfoList[simSlot]
                        val subId = subscriptionInfo.subscriptionId
                        telephonyManager.createForSubscriptionId(subId).line1Number
                    } else {
                        null
                    }
                } else {
                    GatewayLogger.w("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_no_read_phone_numbers), TAG)
                    null
                }
            } else {
                // Single SIM or fallback
                telephonyManager.line1Number
            }
        } catch (e: Exception) {
            GatewayLogger.e("MMS", "Failed to get phone number", TAG, e)
            null
        }
    }
}
