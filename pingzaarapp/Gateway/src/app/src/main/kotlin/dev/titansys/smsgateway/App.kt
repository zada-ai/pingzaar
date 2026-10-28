package dev.titansys.smsgateway

import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import android.app.Application
import dev.titansys.smsgateway.helpers.RestApiClient
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.helpers.ConnectivityMonitor
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.helpers.ScheduledTaskManager
import dev.titansys.smsgateway.helpers.FirebaseConfigurator
import dev.titansys.smsgateway.services.TokenRequest

class App : Application() {
    // Removed isAppLockFeatureAvailable - app lock functionality not needed for Zender Gateway

    private lateinit var sessionManager: SessionManager
    private lateinit var deviceHelper: DeviceInfoProvider
    lateinit var connectivityMonitor: ConnectivityMonitor

    override fun onCreate() {
        super.onCreate()

        // Initialize GatewayLogger first for localization support
        GatewayLogger.initialize(this)

        sessionManager = SessionManager(this)
        deviceHelper = DeviceInfoProvider(this)

        // Point the API client at the paired site before anything can use it.
        // The URL is stored on the device rather than compiled in, so an
        // unpaired install simply has no client until a QR is scanned.
        sessionManager.getSiteUrl()?.let { RestApiClient.configure(it) }

        // Initialize network connectivity monitor for smart pause/resume
        connectivityMonitor = ConnectivityMonitor(this)
        connectivityMonitor.registerNetworkCallback(
            onInternetAvailable = {
                // WorkManager will automatically retry queued API operations when network returns
                GatewayLogger.i("APP", getString(R.string.log_network_restored), "App")
            },
            onInternetLost = {
                // API operations will queue until internet is restored
                GatewayLogger.i("APP", getString(R.string.log_network_lost), "App")
            }
        )

        // Firebase comes up from configuration the paired site supplied, not from
        // a google-services.json baked in at build time. A device paired to a site
        // with no client configuration has no push -- it is told so on screen
        // rather than appearing healthy and silently never sending.
        if (sessionManager.isLoggedIn()) {
            if (FirebaseConfigurator.initialiseIfConfigured(this, sessionManager)) {
                FirebaseMessaging.getInstance().apply {
                    token.addOnCompleteListener { task ->
                        if (task.isSuccessful) {
                            sendTokenToServer(task.result)
                        } else {
                            GatewayLogger.e("APP", "FCM token request failed: ${task.exception?.message}", "App")
                        }
                    }
                }
            } else {
                GatewayLogger.w("APP", "Paired but no Firebase configuration; push disabled", "App")
            }
        }
    }

    override fun onTerminate() {
        super.onTerminate()
        // Cleanup network callback (note: onTerminate is rarely called in production)
        if (::connectivityMonitor.isInitialized) {
            connectivityMonitor.unregisterNetworkCallback()
        }
    }

    private fun sendTokenToServer(token: String) {
        CoroutineScope(Dispatchers.IO).launch {
            try {
                val hash = sessionManager.getHash()
                val did = deviceHelper.getAndroidId()

                if (hash == null || did == null) {
                    GatewayLogger.d("APP", "Cannot sync FCM token - user not logged in", "App")
                    return@launch
                }

                val response = RestApiClient.apiService.submitToken(TokenRequest(hash, did, token))

                if (response.isSuccessful) {
                    GatewayLogger.d("APP", "FCM token synced successfully on app init", "App")
                } else {
                    // Retry via WorkManager instead of giving up
                    GatewayLogger.w("APP", "FCM token sync failed (${response.code()}), scheduling retry via WorkManager", "App")
                    ScheduledTaskManager.scheduleTokenSync(this@App, token)
                }
            } catch (e: Exception) {
                // Don't silently ignore - schedule retry via WorkManager!
                GatewayLogger.e("APP", "FCM token sync error: ${e.message}, scheduling retry", "App", e)
                GatewayLogger.w("APP", R.string.log_fcm_token_sync_scheduled, "App")
                ScheduledTaskManager.scheduleTokenSync(this@App, token)
            }
        }
    }
}

