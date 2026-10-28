package dev.titansys.smsgateway.helpers

import org.json.JSONObject

/**
 * The contents of a pairing QR code.
 *
 * Zender renders `{"v":1,"u":"https://site.com/","t":"<token>"}`. The site URL
 * travels with the token because the app is no longer built per-customer and
 * has no other way to learn which site it belongs to.
 *
 * Parsing is deliberately strict. A gateway that pairs against a mistyped or
 * attacker-supplied URL sends its owner's SMS traffic somewhere else, so a
 * payload that is not exactly what Zender emits is rejected rather than guessed
 * at.
 */
data class PairingPayload(val siteUrl: String, val token: String) {

    companion object {
        /** Bumped only if the payload shape changes incompatibly. */
        const val SUPPORTED_VERSION = 1

        /**
         * @return the parsed payload, or null if the text is not a pairing QR
         *         this build understands.
         */
        fun parse(raw: String?): PairingPayload? {
            if (raw.isNullOrBlank()) return null

            val json = try {
                JSONObject(raw.trim())
            } catch (e: Exception) {
                // Older Zender releases put the bare account hash in the QR. It is
                // not JSON, so it lands here -- and it cannot be honoured anyway,
                // since it carries no site URL.
                return null
            }

            if (json.optInt("v", -1) != SUPPORTED_VERSION) return null

            val url = json.optString("u").trim()
            val token = json.optString("t").trim()
            if (url.isEmpty() || token.isEmpty()) return null

            return if (isAcceptableSiteUrl(url)) PairingPayload(normalise(url), token) else null
        }

        /**
         * Only absolute http(s) URLs are accepted.
         *
         * Anything else -- a relative path, or a scheme like `javascript:` or
         * `file:` -- is refused outright. Retrofit would reject most of them too,
         * but failing here means a bad QR is reported as a bad QR instead of
         * surfacing later as an unrelated crash.
         */
        private fun isAcceptableSiteUrl(url: String): Boolean {
            val lower = url.lowercase()
            if (!lower.startsWith("http://") && !lower.startsWith("https://")) return false

            val afterScheme = url.substringAfter("://")
            return afterScheme.isNotEmpty() && !afterScheme.startsWith("/")
        }

        /** Retrofit requires a trailing slash on a base URL; QRs often lack one. */
        private fun normalise(url: String): String = if (url.endsWith("/")) url else "$url/"
    }
}
