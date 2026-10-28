package dev.titansys.smsgateway.activities

import android.Manifest
import android.annotation.SuppressLint
import android.app.role.RoleManager
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ShortcutInfo
import android.content.pm.ShortcutManager
import android.graphics.drawable.Icon
import android.graphics.drawable.LayerDrawable
import android.os.Bundle
import android.provider.Telephony
import android.provider.Settings
import android.text.TextUtils
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.coordinatorlayout.widget.CoordinatorLayout
import androidx.core.content.ContextCompat
import dev.titansys.smsgateway.helpers.PermissionRequiredDialog
import dev.titansys.smsgateway.helpers.appLockManager
import dev.titansys.smsgateway.BuildConfig
import dev.titansys.smsgateway.R
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import dev.titansys.smsgateway.helpers.RestApiClient
import dev.titansys.smsgateway.services.DeviceDefaultRequest
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import dev.titansys.smsgateway.helpers.GatewayLogger
import org.greenrobot.eventbus.EventBus
import org.greenrobot.eventbus.Subscribe
import org.greenrobot.eventbus.ThreadMode

class PermissionSetupActivity : AppCompatActivity() {
    private val MAKE_DEFAULT_APP_REQUEST = 1

    private var storedTextColor = 0
    private var storedFontSize = 0
    private var lastSearchedText = ""
    private var bus: EventBus? = null
    private var isEventBusRegistered = false  // Track EventBus registration state

    // Modern permission launcher
    private lateinit var requestPermissionsLauncher: ActivityResultLauncher<Array<String>>

    // Required permissions for SMS Gateway functionality
    private val requiredPermissions by lazy {
        mutableListOf<String>().apply {
            // Core SMS permissions
            add(Manifest.permission.READ_SMS)
            add(Manifest.permission.SEND_SMS)
            add(Manifest.permission.RECEIVE_SMS)
            add(Manifest.permission.RECEIVE_MMS)
            add(Manifest.permission.READ_CONTACTS)
            add(Manifest.permission.READ_PHONE_STATE)
            add(Manifest.permission.CALL_PHONE)  // Required for USSD functionality

            // Android 13+ notification permission
            if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.TIRAMISU) {
                add(Manifest.permission.POST_NOTIFICATIONS)
            }
        }.toTypedArray()
    }


    @SuppressLint("InlinedApi")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Setup modern permission launcher
        setupActivityResultLaunchers()

        // Check if user is logged in
        val sessionManager = SessionManager(this)

        if (sessionManager.isLoggedIn()) {
            // User is logged in, proceed with normal flow
            askPermissions()
        } else {
            // User is not logged in, redirect to login
            startActivity(Intent(this, LoginActivity::class.java))
            finish()
        }
    }

    override fun onPause() {
        super.onPause()
    }

    override fun onDestroy() {
        super.onDestroy()

        // Only unregister if we successfully registered
        if (isEventBusRegistered && bus != null) {
            try {
                // Double-check EventBus still has us registered
                if (bus!!.isRegistered(this)) {
                    bus!!.unregister(this)
                    GatewayLogger.d("PERM", "EventBus unregistered successfully", "Activity")
                } else {
                    // We think we're registered, but EventBus doesn't (race condition?)
                    GatewayLogger.w("PERM", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_eventbus_not_registered), "Activity")
                }
            } catch (e: Exception) {
                // Log error but don't crash
                GatewayLogger.e("PERM", "Error unregistering EventBus: ${e.message}", "Activity", e)
            } finally {
                // Always clean up state
                isEventBusRegistered = false
                bus = null
            }
        } else {
            GatewayLogger.d("PERM", "EventBus not registered (state tracked), skipping unregister", "Activity")
        }
    }

    override fun onBackPressed() {
            appLockManager.lock()
            super.onBackPressed()
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, resultData: Intent?) {
        super.onActivityResult(requestCode, resultCode, resultData)
        if (requestCode == MAKE_DEFAULT_APP_REQUEST) {
            if (resultCode == RESULT_OK) {
                askPermissions()
            } else {
                finish()
            }
        }
    }

    private fun setupActivityResultLaunchers() {
        requestPermissionsLauncher = registerForActivityResult(
            ActivityResultContracts.RequestMultiplePermissions()
        ) { permissions ->
            handlePermissionResults(permissions)
        }
    }


    // Modern batch permission request
    private fun askPermissions() {
        val missingPermissions = requiredPermissions.filter { permission ->
            ContextCompat.checkSelfPermission(this, permission) != PackageManager.PERMISSION_GRANTED
        }

        if (missingPermissions.isEmpty()) {
            // All permissions granted, proceed with app initialization
            proceedWithApp()
        } else {
            // Request missing permissions in batch
            requestPermissionsLauncher.launch(missingPermissions.toTypedArray())
        }
    }

    private fun proceedWithApp() {
        // Keep the same initialization flow as before
        initGateway()

        // Register EventBus only if not already registered
        if (!isEventBusRegistered) {
            bus = EventBus.getDefault()
            try {
                // Double-check EventBus internal state before registering
                if (!bus!!.isRegistered(this)) {
                    bus!!.register(this)
                    isEventBusRegistered = true
                    GatewayLogger.d("PERM", "EventBus registered successfully", "Activity")
                } else {
                    // EventBus thinks we're already registered (shouldn't happen, but be defensive)
                    GatewayLogger.w("PERM", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_eventbus_already_registered), "Activity")
                    isEventBusRegistered = true  // Mark as registered since EventBus has us
                }
            } catch (e: Exception) {
                // Log the actual error instead of silently ignoring
                GatewayLogger.e("PERM", "Failed to register EventBus: ${e.message}", "Activity", e)
                // Don't set isEventBusRegistered = true since registration failed
            }
        } else {
            GatewayLogger.d("PERM", "EventBus already registered (state tracked), skipping", "Activity")
        }
    }

    private fun handlePermissionResults(permissions: Map<String, Boolean>) {
        // Check critical SMS permissions first
        val smsReadGranted = permissions[Manifest.permission.READ_SMS] == true
        val smsSendGranted = permissions[Manifest.permission.SEND_SMS] == true
        val smsReceiveGranted = permissions[Manifest.permission.RECEIVE_SMS] == true

        if (smsReadGranted && smsSendGranted && smsReceiveGranted) {
            // Critical permissions granted, proceed with app
            proceedWithApp()
        } else {
            // Critical permissions denied, exit app (same behavior as before)
            finish()
        }

        // Log optional permission status (non-blocking)
        val contactsGranted = permissions[Manifest.permission.READ_CONTACTS] == true

        if (!contactsGranted) {
            // Contact permission denied - app still works but can't show contact names
            GatewayLogger.i("PERM", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_contacts_permission_denied), "Activity")
        }

        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.TIRAMISU) {
            val notificationGranted = permissions[Manifest.permission.POST_NOTIFICATIONS] == true
            if (!notificationGranted) {
                // Notification permission denied - foreground services still work
                GatewayLogger.i("PERM", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_notification_permission_denied), "Activity")
            }
        }
    }

    private fun initGateway() {
        checkDeviceStatus()

        // Start terminal activity
        startActivity(Intent(this, GatewayActivity::class.java))    
        finish()
    }

    private fun checkDeviceStatus() {
        val sessionManager = SessionManager(this)
        val hash = sessionManager.getHash()
        val deviceId = DeviceInfoProvider(this).getAndroidId()

        if (hash != null && deviceId != null) {
            CoroutineScope(Dispatchers.IO).launch {
                try {
                    val response = RestApiClient.apiService.getDeviceStatus(DeviceDefaultRequest(hash, deviceId))
                    
                    if (response.isSuccessful && response.body()?.status == 200) {
                        val deviceData = response.body()?.data
                        if (deviceData != null) {
                            // Update session with new device data
                            sessionManager.saveLoginSession(
                                hash = hash,
                                deviceName = deviceData.name,
                                receiveSms = deviceData.receive_sms,
                                randomSend = deviceData.random_send,
                                randomMin = deviceData.random_min,
                                randomMax = deviceData.random_max
                            )
                            
                            
                            GatewayLogger.d("PERM", "Device status updated successfully", "Activity")
                        }
                    } else {
                        // Handle error response
                        GatewayLogger.e("PERM", "Failed to get device status: ${response.code()}", "Activity")
                        if (response.code() == 403) {
                            // Session expired or invalid, logout user
                            withContext(Dispatchers.Main) {
                                sessionManager.clearSession()
                                startActivity(Intent(this@PermissionSetupActivity, LoginActivity::class.java))
                                finish()
                            }
                        }
                    }
                } catch (e: Exception) {
                    GatewayLogger.e("PERM", "Error checking device status: ${e.message}", "Activity", e)
                }
            }
        }
    }
}
