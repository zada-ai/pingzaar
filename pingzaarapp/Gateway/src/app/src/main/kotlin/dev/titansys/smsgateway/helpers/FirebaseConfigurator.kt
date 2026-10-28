package dev.titansys.smsgateway.helpers

import android.content.Context
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions

/**
 * Initialises Firebase from configuration fetched at runtime, replacing the
 * `com.google.gms.google-services` Gradle plugin.
 *
 * That plugin read `google-services.json` at build time and emitted four string
 * resources -- `google_app_id`, `google_api_key`, `project_id` and
 * `gcm_defaultSenderId` -- which Firebase then auto-initialised from. Because
 * those values were baked into the APK, each customer needed their own build.
 *
 * The same four values are supplied here programmatically, so a single APK can
 * bind to any customer's Firebase project. The values are not secrets: they ship
 * inside every Android app that uses Firebase, and FCM authorises sends with the
 * service account held on the server, not with these.
 */
object FirebaseConfigurator {

    private const val TAG = "FirebaseConfig"

    /**
     * Brings Firebase up for the stored configuration, if there is one.
     *
     * Safe to call repeatedly: [FirebaseApp.getInstance] throwing is how we detect
     * "not yet initialised", and an already-initialised app is left alone. Firebase
     * cannot be re-pointed at a different project in-process, so a device that
     * re-pairs to a site with a different Firebase project needs a restart to pick
     * it up -- see [reconfigureRequired].
     *
     * @return true when Firebase is ready to issue an FCM token.
     */
    fun initialiseIfConfigured(context: Context, session: SessionManager): Boolean {
        val config = session.getFirebaseConfig()

        if (config == null) {
            GatewayLogger.w(TAG, "No Firebase client configuration stored; push is unavailable", "Helper")
            return false
        }

        if (isInitialised()) return true

        return try {
            val options = FirebaseOptions.Builder()
                .setApplicationId(config.appId)
                .setApiKey(config.apiKey)
                .setProjectId(config.projectId)
                .setGcmSenderId(config.senderId)
                .build()

            FirebaseApp.initializeApp(context, options)
            GatewayLogger.i(TAG, "Firebase initialised for project ${config.projectId}", "Helper")
            true
        } catch (e: Exception) {
            // A malformed or mismatched config must not take the whole app down at
            // startup -- the gateway still receives SMS without push.
            GatewayLogger.e(TAG, "Firebase initialisation failed: ${e.message}", "Helper")
            false
        }
    }

    fun isInitialised(): Boolean = try {
        FirebaseApp.getInstance()
        true
    } catch (e: IllegalStateException) {
        false
    }

    /**
     * True when stored configuration names a different Firebase project than the
     * one already running in this process. The caller should tell the user to
     * restart the app, because [FirebaseApp] cannot be re-pointed in place.
     */
    fun reconfigureRequired(session: SessionManager): Boolean {
        val stored = session.getFirebaseConfig() ?: return false
        if (!isInitialised()) return false

        return try {
            FirebaseApp.getInstance().options.projectId != stored.projectId
        } catch (e: Exception) {
            false
        }
    }
}
