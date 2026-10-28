package dev.titansys.smsgateway.helpers

import android.content.Context
import android.content.SharedPreferences

class SessionManager(private val context: Context) {
    companion object {
        private const val PREF_NAME = "ZenderSession"
        private const val KEY_HASH = "hash"
        private const val KEY_DEVICE_NAME = "device_name"
        private const val KEY_RECEIVE_SMS = "receive_sms"
        private const val KEY_RANDOM_SEND = "random_send"
        private const val KEY_RANDOM_MIN = "random_min"
        private const val KEY_RANDOM_MAX = "random_max"
        private const val KEY_SMS_ENABLED = "sms_enabled"
        private const val KEY_USSD_ENABLED = "ussd_enabled"

        // The site this device is paired to. Supplied by the pairing QR rather
        // than compiled in, which is what lets one build serve every install.
        private const val KEY_SITE_URL = "site_url"

        // Firebase client configuration, fetched from the paired site. These are
        // the same four values the google-services Gradle plugin used to bake in
        // as string resources; holding them here means the app can bind to any
        // customer's Firebase project without a build-time file.
        private const val KEY_FB_APP_ID = "fb_app_id"
        private const val KEY_FB_API_KEY = "fb_api_key"
        private const val KEY_FB_PROJECT_ID = "fb_project_id"
        private const val KEY_FB_SENDER_ID = "fb_sender_id"
    }

    private val prefs: SharedPreferences = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)

    fun saveLoginSession(
        hash: String,
        deviceName: String,
        receiveSms: Int,
        randomSend: Int,
        randomMin: Int,
        randomMax: Int
    ) {
        prefs.edit().apply {
            putString(KEY_HASH, hash)
            putString(KEY_DEVICE_NAME, deviceName)
            putInt(KEY_RECEIVE_SMS, receiveSms)
            putInt(KEY_RANDOM_SEND, randomSend)
            putInt(KEY_RANDOM_MIN, randomMin)
            putInt(KEY_RANDOM_MAX, randomMax)
            apply()
        }
    }

    fun clearSession() {
        prefs.edit().clear().apply()
    }

    fun getHash(): String? = prefs.getString(KEY_HASH, null)
    fun getDeviceName(): String? = prefs.getString(KEY_DEVICE_NAME, null)
    fun getReceiveSms(): Int = prefs.getInt(KEY_RECEIVE_SMS, 0)
    fun getRandomSend(): Int = prefs.getInt(KEY_RANDOM_SEND, 0)
    fun getRandomMin(): Int = prefs.getInt(KEY_RANDOM_MIN, 0)
    fun getRandomMax(): Int = prefs.getInt(KEY_RANDOM_MAX, 0)

    fun isLoggedIn(): Boolean = getHash() != null && getSiteUrl() != null

    /**
     * The paired site's base URL, always with a trailing slash because Retrofit
     * requires one and a URL scanned from a QR frequently lacks it.
     */
    fun getSiteUrl(): String? = prefs.getString(KEY_SITE_URL, null)

    fun saveSiteUrl(url: String) {
        prefs.edit().putString(KEY_SITE_URL, if (url.endsWith("/")) url else "$url/").apply()
    }

    fun saveFirebaseConfig(appId: String, apiKey: String, projectId: String, senderId: String) {
        prefs.edit().apply {
            putString(KEY_FB_APP_ID, appId)
            putString(KEY_FB_API_KEY, apiKey)
            putString(KEY_FB_PROJECT_ID, projectId)
            putString(KEY_FB_SENDER_ID, senderId)
            apply()
        }
    }

    /** All four values, or null if any is missing -- a partial config is unusable. */
    fun getFirebaseConfig(): FirebaseConfig? {
        val appId = prefs.getString(KEY_FB_APP_ID, null) ?: return null
        val apiKey = prefs.getString(KEY_FB_API_KEY, null) ?: return null
        val projectId = prefs.getString(KEY_FB_PROJECT_ID, null) ?: return null
        val senderId = prefs.getString(KEY_FB_SENDER_ID, null) ?: return null
        return FirebaseConfig(appId, apiKey, projectId, senderId)
    }

    fun hasFirebaseConfig(): Boolean = getFirebaseConfig() != null

    data class FirebaseConfig(
        val appId: String,
        val apiKey: String,
        val projectId: String,
        val senderId: String
    )

    fun saveButtonStates(smsEnabled: Boolean, ussdEnabled: Boolean) {
        prefs.edit().apply {
            putBoolean(KEY_SMS_ENABLED, smsEnabled)
            putBoolean(KEY_USSD_ENABLED, ussdEnabled)
            apply()
        }
    }


    fun getSmsEnabled(): Boolean = prefs.getBoolean(KEY_SMS_ENABLED, true)
    fun getUssdEnabled(): Boolean = prefs.getBoolean(KEY_USSD_ENABLED, true)
} 