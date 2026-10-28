package dev.titansys.smsgateway.mms

/**
 * MMS Part data structure (Burat-inspired)
 * Represents a single part in an MMS message (text, image, audio, video, etc.)
 *
 * @param name Filename (e.g., "text_0.txt", "image.jpg")
 * @param mimeType MIME type (e.g., "text/plain", "image/jpeg", "audio/mp3")
 * @param data Content bytes
 */
data class MmsPart(
    val name: String,
    val mimeType: String,
    val data: ByteArray
) {
    override fun equals(other: Any?): Boolean {
        if (this === other) return true
        if (javaClass != other?.javaClass) return false

        other as MmsPart

        if (name != other.name) return false
        if (mimeType != other.mimeType) return false
        if (!data.contentEquals(other.data)) return false

        return true
    }

    override fun hashCode(): Int {
        var result = name.hashCode()
        result = 31 * result + mimeType.hashCode()
        result = 31 * result + data.contentHashCode()
        return result
    }
}
