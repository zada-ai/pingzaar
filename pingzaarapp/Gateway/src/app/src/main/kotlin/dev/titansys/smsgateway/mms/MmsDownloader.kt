package dev.titansys.smsgateway.mms

import android.content.Context
import android.webkit.MimeTypeMap
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import dev.titansys.smsgateway.helpers.GatewayLogger
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * Simple MMS attachment downloader
 * Inspired by Burat's HTTP download logic with Kotlin coroutines
 *
 * Handles single attachment downloads for MMS messages
 */
class MmsDownloader(private val context: Context) {

    companion object {
        private const val TAG = "MmsDownloader"
        private const val DOWNLOAD_TIMEOUT_SECONDS = 30L
        private const val MAX_ATTACHMENT_SIZE_MB = 5 // 5MB per attachment
        private const val MAX_ATTACHMENT_SIZE_BYTES = MAX_ATTACHMENT_SIZE_MB * 1024 * 1024
    }

    private val httpClient = OkHttpClient.Builder()
        .connectTimeout(DOWNLOAD_TIMEOUT_SECONDS, TimeUnit.SECONDS)
        .readTimeout(DOWNLOAD_TIMEOUT_SECONDS, TimeUnit.SECONDS)
        .build()

    /**
     * Download attachment from URL
     * Server provides full absolute URLs
     *
     * @param url URL to download from
     * @return ByteArray of downloaded content
     * @throws IOException if download fails
     */
    suspend fun download(url: String): ByteArray = withContext(Dispatchers.IO) {
        try {
            GatewayLogger.d("MMS", "Downloading attachment: $url", TAG)

            // Build HTTP GET request (Burat uses OkHttp too)
            val request = Request.Builder()
                .url(url)
                .get()
                .build()

            // Execute request
            val response = httpClient.newCall(request).execute()

            if (!response.isSuccessful) {
                throw IOException("HTTP ${response.code}: ${response.message}")
            }

            // Read response body
            val responseBody = response.body ?: throw IOException("Empty response body")
            val bytes = responseBody.bytes()

            // Size validation
            if (bytes.size > MAX_ATTACHMENT_SIZE_BYTES) {
                throw IOException("Attachment too large: ${bytes.size} bytes (max: $MAX_ATTACHMENT_SIZE_BYTES)")
            }

            GatewayLogger.i("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_downloaded, bytes.size, url), TAG)
            bytes

        } catch (e: Exception) {
            GatewayLogger.e("MMS", "Download failed: $url", TAG, e)
            throw e
        }
    }

    /**
     * Detect MIME type from URL extension (Burat-style)
     *
     * Uses Android's MimeTypeMap to detect type from file extension
     * Falls back to generic type if detection fails
     *
     * @param url URL to detect MIME type from
     * @return MIME type string (e.g., "image/jpeg", "video/mp4")
     */
    fun detectMimeType(url: String): String {
        // Extract extension from URL (Burat pattern)
        val extension = MimeTypeMap.getFileExtensionFromUrl(url)

        if (!extension.isNullOrBlank()) {
            val mimeType = MimeTypeMap.getSingleton().getMimeTypeFromExtension(extension)
            if (mimeType != null) {
                GatewayLogger.d("MMS", "Detected MIME type: $mimeType for extension: $extension", TAG)
                return mimeType
            }
        }

        // Default to generic binary type (Burat falls back to image/*)
        GatewayLogger.w("MMS", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_mms_could_not_detect_mime, url), TAG)
        return "application/octet-stream"
    }
}
