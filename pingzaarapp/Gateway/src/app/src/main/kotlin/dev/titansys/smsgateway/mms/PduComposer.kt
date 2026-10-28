package dev.titansys.smsgateway.mms

import java.io.ByteArrayOutputStream

/**
 * PDU Composer (Burat-inspired WAP binary encoding)
 *
 * Encodes MMS PDU (Protocol Data Unit) into WAP binary format.
 * Implements WAP Binary XML encoding for MMS messages.
 *
 * @param headers PDU headers to encode
 * @param body PDU body with parts to encode
 */
class PduComposer(
    private val headers: PduHeaders,
    private val body: PduBody
) {
    companion object {
        private const val TAG = "PduComposer"
    }

    /**
     * Compose PDU into byte array
     * @return Complete PDU as byte array ready for sendMultimediaMessage()
     */
    fun compose(): ByteArray {
        val stream = ByteArrayOutputStream()

        // Write headers
        writeHeaders(stream)

        // Write body
        writeBody(stream)

        return stream.toByteArray()
    }

    /**
     * Write all MMS headers to stream (WAP binary format)
     */
    private fun writeHeaders(stream: ByteArrayOutputStream) {
        for ((field, value) in headers.getHeaders()) {
            stream.write(field)
            when (value) {
                is Int -> stream.write(value)
                is Long -> writeLongInteger(stream, value)
                is ByteArray -> writeTextString(stream, value)
                is EncodedStringValue -> writeEncodedStringValue(stream, value)
                is Array<*> -> {
                    for (item in value) {
                        if (item is EncodedStringValue) {
                            writeEncodedStringValue(stream, item)
                        }
                    }
                }
            }
        }
    }

    /**
     * Write multipart body to stream
     * Format:
     * - Parts count (uintvar)
     * - For each part:
     *   - Header length (uintvar)
     *   - Data length (uintvar)
     *   - Headers bytes
     *   - Data bytes
     */
    private fun writeBody(stream: ByteArrayOutputStream) {
        val partsList = body.parts

        // Write parts count
        writeUintvar(stream, partsList.size)

        // Write each part
        for (part in partsList) {
            writePartHeaders(stream, part)
            val data = part.getData()
            if (data != null) {
                stream.write(data)
            }
        }
    }

    /**
     * Write part headers
     * @param stream Output stream
     * @param part PDU part to write
     */
    private fun writePartHeaders(stream: ByteArrayOutputStream, part: PduPart) {
        val headerStream = ByteArrayOutputStream()

        // Write all part headers
        for ((field, value) in part.getHeaders()) {
            headerStream.write(field)
            writeTextString(headerStream, value)
        }

        val headerBytes = headerStream.toByteArray()
        val dataSize = part.getData()?.size ?: 0

        // Write header length (uintvar)
        writeUintvar(stream, headerBytes.size)

        // Write data length (uintvar)
        writeUintvar(stream, dataSize)

        // Write header bytes
        stream.write(headerBytes)
    }

    /**
     * Write long integer in WAP binary format
     * Format: [length byte] [value bytes, big-endian]
     *
     * Example: 604800 (0x93a80) → [3] [0x09] [0x3a] [0x80]
     *
     * @param stream Output stream
     * @param value Long integer value
     */
    private fun writeLongInteger(stream: ByteArrayOutputStream, value: Long) {
        // Calculate number of bytes needed (excluding leading zeros)
        var v = value
        var size = 0
        while (v != 0L) {
            size++
            v = v ushr 8
        }

        // Handle zero case
        if (size == 0) {
            size = 1
        }

        // Write length byte
        stream.write(size)

        // Write value bytes (big-endian)
        for (i in size - 1 downTo 0) {
            stream.write(((value shr (i * 8)) and 0xFF).toInt())
        }
    }

    /**
     * Write null-terminated text string
     * Format: [text bytes] [0x00]
     *
     * @param stream Output stream
     * @param value Text as byte array
     */
    private fun writeTextString(stream: ByteArrayOutputStream, value: ByteArray) {
        stream.write(value)
        stream.write(0) // Null terminator
    }

    /**
     * Write encoded string value (with charset)
     * Format: [charset code] [text bytes] [0x00]
     *
     * @param stream Output stream
     * @param value EncodedStringValue with charset and text
     */
    private fun writeEncodedStringValue(stream: ByteArrayOutputStream, value: EncodedStringValue) {
        stream.write(value.characterSet)
        writeTextString(stream, value.textString)
    }

    /**
     * Write unsigned integer variable length (uintvar) in WAP format
     *
     * Variable-length encoding where:
     * - Each byte has 7 bits of data + 1 continuation bit
     * - Continuation bit (MSB) = 1 means more bytes follow
     * - Continuation bit (MSB) = 0 means last byte
     *
     * Examples:
     * - 1 → [0x01]
     * - 127 → [0x7F]
     * - 128 → [0x81, 0x00]
     * - 200 → [0x81, 0x48]
     *
     * @param stream Output stream
     * @param value Integer value to encode
     */
    private fun writeUintvar(stream: ByteArrayOutputStream, value: Int) {
        var v = value
        val bytes = mutableListOf<Byte>()

        // Build bytes from least significant to most significant
        do {
            // Take lowest 7 bits
            val byte = (v and 0x7F).toByte()

            // Shift right by 7 bits
            v = v ushr 7

            // Add continuation bit (0x80) if not the last byte
            if (bytes.isNotEmpty()) {
                bytes.add(0, (byte.toInt() or 0x80).toByte())
            } else {
                bytes.add(0, byte)
            }
        } while (v != 0)

        // Write all bytes
        bytes.forEach { stream.write(it.toInt()) }
    }
}
