package dev.titansys.smsgateway.activities

import android.os.Build
import android.os.Bundle
import android.view.View
import android.content.Intent
import android.widget.TextView
import android.widget.ScrollView
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import com.google.android.material.appbar.MaterialToolbar
import com.google.android.material.button.MaterialButton
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import dev.titansys.smsgateway.R
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import dev.titansys.smsgateway.helpers.PermissionRequiredDialog
import android.graphics.Color
import android.content.res.ColorStateList
import android.widget.Toast
import dev.titansys.smsgateway.BuildConfig
import android.provider.Settings
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.activities.LoginActivity
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import dev.titansys.smsgateway.helpers.PowerManagementHelper
import dev.titansys.smsgateway.sms.SmsServiceManager
import dev.titansys.smsgateway.helpers.UssdManager
import dev.titansys.smsgateway.helpers.AppUpdateManager
import dev.titansys.smsgateway.helpers.ScheduledTaskManager
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import android.view.Menu
import android.view.MenuItem
import android.app.role.RoleManager
import android.provider.Telephony
import dev.titansys.smsgateway.helpers.GatewayLogger
import android.net.Uri
import android.content.ActivityNotFoundException
import androidx.annotation.RequiresApi
import com.google.firebase.messaging.FirebaseMessaging

class GatewayActivity : AppCompatActivity() {
    private lateinit var terminalTextView: TextView
    private lateinit var scrollView: ScrollView
    private lateinit var smsButton: MaterialButton
    private lateinit var ussdButton: MaterialButton
    private lateinit var exitButton: MaterialButton
    private lateinit var sessionManager: SessionManager
    private lateinit var deviceHelper: DeviceInfoProvider
    private lateinit var batteryOptimizationHelper: PowerManagementHelper
    private lateinit var updateHelper: AppUpdateManager
    private lateinit var batteryOptimizationLauncher: ActivityResultLauncher<Intent>
    private var isSmsEnabled = true
    private var isUssdEnabled = true

    companion object {
        private var instance: GatewayActivity? = null
        private const val TAG = "GatewayActivity"
        private const val MAX_LOG_MESSAGES = 100
        private const val MAKE_DEFAULT_APP_REQUEST = 1

        fun getInstance(): GatewayActivity? = instance
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        // Register battery optimization launcher before super.onCreate
        batteryOptimizationLauncher = registerForActivityResult(
            ActivityResultContracts.StartActivityForResult()
        ) {
            handleBatteryOptimizationResult()
        }

        super.onCreate(savedInstanceState)
        setTheme(R.style.TerminalTheme)
        setContentView(R.layout.activity_terminal)


        // Initialize helpers
        deviceHelper = DeviceInfoProvider(this)
        sessionManager = SessionManager(this)
        batteryOptimizationHelper = PowerManagementHelper(this)
        updateHelper = AppUpdateManager(this)

        // Register permission handler for updates
        updateHelper.registerPermissionHandler(this) {
            updateHelper.checkForUpdates()
        }

        // Set up update listener
        updateHelper.setOnUpdateAvailableListener { updateInfo ->
            updateHelper.showUpdateDialog(updateInfo)
        }

        // Check for updates first
        updateHelper.checkForUpdates()

        // Set up toolbar with increased height for unified appearance with status bar
        val toolbar = findViewById<MaterialToolbar>(R.id.toolbar)

        // Use WindowInsets API to get status bar height (modern approach)
        androidx.core.view.ViewCompat.setOnApplyWindowInsetsListener(toolbar) { view, insets ->
            val statusBarHeight = insets.getInsets(androidx.core.view.WindowInsetsCompat.Type.systemBars()).top

            // Increase toolbar height to accommodate status bar space
            val typedValue = android.util.TypedValue()
            theme.resolveAttribute(androidx.appcompat.R.attr.actionBarSize, typedValue, true)
            val actionBarHeight = resources.getDimensionPixelSize(typedValue.resourceId)

            val layoutParams = view.layoutParams
            layoutParams.height = actionBarHeight + statusBarHeight
            view.layoutParams = layoutParams

            // Add top padding equal to status bar height
            view.setPadding(
                view.paddingLeft,
                statusBarHeight,
                view.paddingRight,
                view.paddingBottom
            )

            insets
        }
        setSupportActionBar(toolbar)

        // Set toolbar title to device name
        updateToolbarTitle()

        terminalTextView = findViewById(R.id.terminalTextView)
        scrollView = findViewById(R.id.terminalScrollView)

        // Load saved button states
        isSmsEnabled = sessionManager.getSmsEnabled()
        isUssdEnabled = sessionManager.getUssdEnabled()

        setupControlButtons()

        val appVersion = BuildConfig.VERSION_NAME
        GatewayLogger.i("APP", GatewayLogger.appContext.getString(R.string.log_build_number, appVersion), "Activity")
        val androidId = deviceHelper.getAndroidId()
        GatewayLogger.i("APP", GatewayLogger.appContext.getString(R.string.log_device_id, androidId?.take(8) ?: "unknown"), "Activity")

        // Start services directly
        batteryOptimizationHelper.showBatteryOptimizationRequest(this, batteryOptimizationLauncher)
        SmsServiceManager.startMessageQueueService(this)
        UssdManager.startUssdService(this)
        if (isSmsEnabled) {
            ScheduledTaskManager.startPeriodicScheduling(this)
        }
        if (isUssdEnabled) {
            ScheduledTaskManager.scheduleUssdBackgroundProcessing(this)
        }

        // Schedule periodic FCM token refresh (every 7 days)
        ScheduledTaskManager.schedulePeriodicTokenRefresh(this)
    }

    private fun setupControlButtons() {
        smsButton = findViewById(R.id.smsButton)
        ussdButton = findViewById(R.id.ussdButton)
        exitButton = findViewById(R.id.exitButton)

        // Set initial button states
        updateButtonState(smsButton, isSmsEnabled)
        updateButtonState(ussdButton, isUssdEnabled)

        smsButton.setOnClickListener { toggleSms() }
        ussdButton.setOnClickListener { toggleUssd() }
        exitButton.setOnClickListener { showExitConfirmation() }
    }

    private fun toggleSms() {
        isSmsEnabled = !isSmsEnabled
        updateButtonState(smsButton, isSmsEnabled)
        val status = getString(if (isSmsEnabled) R.string.enabled else R.string.disabled)
        showStatusToast(getString(R.string.sms_status_changed, status))
        sessionManager.saveButtonStates(isSmsEnabled, isUssdEnabled)

        if (isSmsEnabled) {
           // Start the SmsQueueService if it's enabled
           SmsServiceManager.startMessageQueueService(this)
           // Start periodic scheduling
           ScheduledTaskManager.startPeriodicScheduling(this)
           GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_sms_bg_service_activated), "Activity")
        } else {
           // Stop periodic scheduling when SMS is disabled
           ScheduledTaskManager.stopPeriodicScheduling(this)
           GatewayLogger.i("SMS", GatewayLogger.appContext.getString(R.string.log_sms_bg_service_deactivated), "Activity")
        }
    }


    private fun toggleUssd() {
        isUssdEnabled = !isUssdEnabled
        updateButtonState(ussdButton, isUssdEnabled)
        val status = getString(if (isUssdEnabled) R.string.enabled else R.string.disabled)
        showStatusToast(getString(R.string.ussd_status_changed, status))
        sessionManager.saveButtonStates(isSmsEnabled, isUssdEnabled)

        if (isUssdEnabled) {
           // Start USSD service if it's enabled
           UssdManager.startUssdService(this)
           // Schedule WorkManager background processing
           ScheduledTaskManager.scheduleUssdBackgroundProcessing(this)
        } else {
           // Cancel WorkManager background processing when disabled
           ScheduledTaskManager.cancelUssdBackgroundProcessing(this)
        }
    }

    private fun showStatusToast(message: String) {
        Toast.makeText(this, message, Toast.LENGTH_SHORT).show()
    }

    private fun showExitConfirmation() {
        MaterialAlertDialogBuilder(this)
            .setTitle(getString(R.string.logout))
            .setMessage(getString(R.string.logout_confirmation))
            .setPositiveButton(getString(R.string.confirm)) { _, _ ->
                GatewayLogger.i("AUTH", GatewayLogger.appContext.getString(R.string.log_user_session_terminated), "Activity")
                // Clear session data
                sessionManager.clearSession()
                
                // Start LoginActivity
                val intent = Intent(this, LoginActivity::class.java)
                intent.flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
                startActivity(intent)
                
                // Close this activity
                finish()
            }
            .setNegativeButton(getString(R.string.cancel), null)
            .show()
    }

    private fun updateButtonState(button: MaterialButton, isEnabled: Boolean) {
        val color = if (isEnabled) {
            ContextCompat.getColor(this, R.color.colorPrimary)
        } else {
            ContextCompat.getColor(this, android.R.color.darker_gray)
        }
        
        button.apply {
            setStrokeColorResource(if (isEnabled) R.color.colorPrimary else android.R.color.darker_gray)
            iconTint = ColorStateList.valueOf(color)
            strokeWidth = resources.getDimensionPixelSize(R.dimen.button_stroke_width)
            rippleColor = ColorStateList.valueOf(
                if (isEnabled) 
                    ContextCompat.getColor(context, R.color.colorPrimary)
                else 
                    Color.GRAY
            )
        }
    }

    fun logEvent(message: String) {
        val timestamp = SimpleDateFormat("hh:mm a", Locale.getDefault()).format(Date())
        val logMessage = "> $message ($timestamp)\n"
        
        runOnUiThread {
            try {
                // Get current text and split into lines
                val currentText = terminalTextView.text.toString()
                val lines = currentText.split("\n").filter { it.isNotEmpty() }
                
                // Add new message to the beginning
                val updatedLines = mutableListOf(logMessage.trim())
                updatedLines.addAll(lines)
                
                // Limit to MAX_LOG_MESSAGES
                val limitedLines = if (updatedLines.size > MAX_LOG_MESSAGES) {
                    updatedLines.take(MAX_LOG_MESSAGES)
                } else {
                    updatedLines
                }
                
                // Join lines back together with newlines
                val newText = limitedLines.joinToString("\n")
                
                // Set the text
                terminalTextView.text = newText
                
                // Ensure scrolling works by using a delayed post
                scrollView.postDelayed({
                    // Scroll to top
                    scrollView.fullScroll(View.FOCUS_UP)
                    
                    // Force layout update
                    scrollView.requestLayout()
                    
                    // No logging for UI updates to prevent logcat flooding
                }, 100) // Small delay to ensure text is rendered
            } catch (e: Exception) {
                // Log any errors but don't crash
                GatewayLogger.e("UI", "Error updating terminal: ${e.message}", "Activity", e)
            }
        }
    }

    override fun onResume() {
        super.onResume()
        instance = this
        // Enable efficient UI logging when app is visible
        GatewayLogger.setForegroundState(true)

        // Check FCM connection health when app is opened
        checkFcmHealth()
    }

    override fun onPause() {
        super.onPause()
        instance = null
        // Disable UI logging when app goes to background to save resources
        GatewayLogger.setForegroundState(false)
    }



    /**
     * Updates the toolbar title with the current device name from SessionManager
     */
    fun updateToolbarTitle() {
        try {
            val deviceName = sessionManager.getDeviceName() ?: "Gateway App"
            // Use post to ensure UI updates happen on the main thread
            runOnUiThread {
                supportActionBar?.title = deviceName
                GatewayLogger.d("UI", "Toolbar title updated to: $deviceName", "Activity")
            }
        } catch (e: Exception) {
            // Log the error but don't crash
            GatewayLogger.e("UI", "Error updating toolbar title: ${e.message}", "Activity", e)
        }
    }

    override fun onCreateOptionsMenu(menu: Menu): Boolean {
        menuInflater.inflate(R.menu.menu_terminal, menu)
        return true
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        return when (item.itemId) {
            R.id.action_language -> {
                showLanguageSwitcher()
                true
            }
            R.id.action_make_default -> {
                showMakeDefaultConfirmationDialog()
                true
            }
            R.id.action_device_info -> {
                showDeviceInfo()
                true
            }
            else -> super.onOptionsItemSelected(item)
        }
    }

    private fun showMakeDefaultConfirmationDialog() {
        MaterialAlertDialogBuilder(this)
            .setTitle(getString(R.string.make_default_sms_app))
            .setMessage(getString(R.string.make_default_sms_message))
            .setPositiveButton(getString(R.string.continue_button)) { _, _ ->
                askDefaultSmsApp()
            }
            .setNegativeButton(getString(R.string.cancel), null)
            .show()
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, resultData: Intent?) {
        super.onActivityResult(requestCode, resultCode, resultData)
        if (requestCode == MAKE_DEFAULT_APP_REQUEST) {
            if (resultCode == RESULT_OK) {
                showStatusToast(getString(R.string.app_set_as_default))
            } else {
                showStatusToast(getString(R.string.failed_to_set_default))
            }
        }
    }

    private fun askDefaultSmsApp() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            val roleManager = getSystemService(RoleManager::class.java)
            if (roleManager!!.isRoleAvailable(RoleManager.ROLE_SMS)) {
                if (roleManager.isRoleHeld(RoleManager.ROLE_SMS)) {
                    showStatusToast(getString(R.string.already_default_sms))
                } else {
                    val intent = roleManager.createRequestRoleIntent(RoleManager.ROLE_SMS)
                    startActivityForResult(intent, MAKE_DEFAULT_APP_REQUEST)
                }
            } else {
                showStatusToast(getString(R.string.sms_role_unavailable))
            }
        } else {
            if (Telephony.Sms.getDefaultSmsPackage(this) == packageName) {
                showStatusToast(getString(R.string.already_default_sms))
            } else {
                val intent = Intent(Telephony.Sms.Intents.ACTION_CHANGE_DEFAULT)
                intent.putExtra(Telephony.Sms.Intents.EXTRA_PACKAGE_NAME, packageName)
                startActivityForResult(intent, MAKE_DEFAULT_APP_REQUEST)
            }
        }
    }

    private fun showDeviceInfo() {
        val deviceName = sessionManager.getDeviceName() ?: "Gateway App"
        val androidId = deviceHelper.getAndroidId()
        val appVersion = BuildConfig.VERSION_NAME
        val androidVersion = android.os.Build.VERSION.RELEASE
        val deviceModel = android.os.Build.MODEL
        val manufacturer = android.os.Build.MANUFACTURER

        // SMS Configuration Info
        val smsEnabled = sessionManager.getSmsEnabled()
        val receiveSmsValue = sessionManager.getReceiveSms()
        val randomSend = sessionManager.getRandomSend()
        val randomMin = sessionManager.getRandomMin()
        val randomMax = sessionManager.getRandomMax()

        val receiveSmsStatus = when (receiveSmsValue) {
            0 -> "DISABLED"
            1 -> "ENABLED"
            2 -> "BLOCKED"
            else -> "UNKNOWN ($receiveSmsValue)"
        }

        val deviceInfo = """
            ${getString(R.string.device_manufacturer, manufacturer)}
            ${getString(R.string.device_name, deviceName)}
            ${getString(R.string.device_id, androidId)}
            ${getString(R.string.device_model, deviceModel)}
            ${getString(R.string.device_os, androidVersion)}
            ${getString(R.string.build_number, appVersion)}
        """.trimIndent()
        
        MaterialAlertDialogBuilder(this)
            .setTitle(getString(R.string.device_information))
            .setMessage(deviceInfo)
            .setPositiveButton(getString(R.string.ok), null)
            .show()
    }

    private fun showLanguageSwitcher() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            openSystemLanguageSettings()
        } else {
            showLanguageDialog()
        }
    }

    @RequiresApi(Build.VERSION_CODES.TIRAMISU)
    private fun openSystemLanguageSettings() {
        try {
            val intent = Intent(Settings.ACTION_APP_LOCALE_SETTINGS).apply {
                data = Uri.fromParts("package", packageName, null)
            }
            startActivity(intent)
        } catch (e: ActivityNotFoundException) {
            GatewayLogger.e("UI", "System language settings not available: ${e.message}", "Activity", e)
            showLanguageDialog()
        } catch (e: Exception) {
            GatewayLogger.e("UI", "Failed to open system language settings: ${e.message}", "Activity", e)
            showLanguageDialog()
        }
    }

    private fun showLanguageDialog() {
        val languages = arrayOf(
            getString(R.string.english),
            getString(R.string.japanese),
            getString(R.string.arabic),
            getString(R.string.chinese)
        )
        val languageCodes = arrayOf("en", "ja", "ar", "zh")

        // Detect current language
        val currentLocale = resources.configuration.locales[0].language
        val selectedIndex = languageCodes.indexOf(currentLocale).takeIf { it >= 0 } ?: 0

        MaterialAlertDialogBuilder(this)
            .setTitle(getString(R.string.select_language))
            .setSingleChoiceItems(languages, selectedIndex) { dialog, which ->
                if (which != selectedIndex) {
                    setAppLanguage(languageCodes[which])
                }
                dialog.dismiss()
            }
            .setNegativeButton(getString(R.string.cancel), null)
            .show()
    }

    private fun setAppLanguage(languageCode: String) {
        try {
            val locale = Locale(languageCode)
            Locale.setDefault(locale)

            val config = resources.configuration
            config.setLocale(locale)

            @Suppress("DEPRECATION")
            resources.updateConfiguration(config, resources.displayMetrics)

            // Log language change
            GatewayLogger.i("UI", R.string.log_language_changed_to, "Activity", languageCode)

            // Show toast (brief, user may not see it due to quick restart)
            Toast.makeText(this, getString(R.string.language_changed), Toast.LENGTH_SHORT).show()

            // Restart activity to apply changes throughout the app
            recreate()
        } catch (e: Exception) {
            GatewayLogger.e("UI", "Failed to change language: ${e.message}", "Activity", e)
            Toast.makeText(this, "Failed to change language", Toast.LENGTH_SHORT).show()
        }
    }

    private fun debugSmsReceivingStatus() {
        try {
            val smsEnabled = sessionManager.getSmsEnabled()
            val receiveSmsValue = sessionManager.getReceiveSms()

            // Check if both conditions would allow SMS receiving
            val wouldReceiveSms = smsEnabled && receiveSmsValue != 2
            GatewayLogger.d("SMS", "Message processing: ${if (wouldReceiveSms) "ACTIVE" else "INACTIVE"}", "Activity")

            if (!wouldReceiveSms) {
                if (!smsEnabled) {
                    GatewayLogger.w("SMS", GatewayLogger.appContext.getString(R.string.log_sms_service_disabled_locally), "Activity")
                }
                if (receiveSmsValue == 2) {
                    GatewayLogger.w("SMS", GatewayLogger.appContext.getString(R.string.log_sms_blocked_by_server), "Activity")
                }
            }

            GatewayLogger.d("SMS", "Configuration check completed", "Activity")

        } catch (e: Exception) {
            GatewayLogger.e("SMS", "Configuration check failed: ${e.message}", "Activity", e)
        }
    }

    /**
     * Handles battery optimization permission result (Burat's approach)
     * Checks if permission was granted after user interacts with dialog
     */
    private fun handleBatteryOptimizationResult() {
        val isOptimizationEnabled = batteryOptimizationHelper.isBatteryOptimizationEnabled()

        if (isOptimizationEnabled) {
            // Permission denied - log warning
            GatewayLogger.w("SYS", GatewayLogger.appContext.getString(R.string.log_battery_optimization_enabled), "Activity")
        } else {
            // Permission granted - log success
            GatewayLogger.i("SYS", GatewayLogger.appContext.getString(R.string.log_battery_optimization_disabled), "Activity")
        }
    }

    /**
     * Check FCM connection health to detect and recover from FCM connection issues
     * Verifies FCM auto-init is enabled and token can be retrieved
     */
    private fun checkFcmHealth() {
        try {
            GatewayLogger.d("FCM", "Starting FCM health check", "Health")

            // Check if FCM auto-init is enabled
            val isAutoInitEnabled = FirebaseMessaging.getInstance().isAutoInitEnabled
            if (!isAutoInitEnabled) {
                GatewayLogger.w("FCM", R.string.log_fcm_connection_issue, "Health")
                GatewayLogger.d("FCM", "FCM auto-init was disabled, re-enabling", "Health")
                // Re-enable it
                FirebaseMessaging.getInstance().isAutoInitEnabled = true
            }

            // Test token retrieval to verify FCM connectivity
            FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
                if (task.isSuccessful) {
                    val token = task.result
                    GatewayLogger.d("FCM", "FCM health check passed - token: ${token?.take(10)}...", "Health")

                    // If token exists, ensure it's synced with backend
                    if (token != null) {
                        ScheduledTaskManager.scheduleTokenSync(this, token)
                    }
                } else {
                    GatewayLogger.w("FCM", R.string.log_fcm_connection_issue, "Health")
                    GatewayLogger.d("FCM", "FCM health check failed: ${task.exception?.message}", "Health")
                }
            }
        } catch (e: Exception) {
            GatewayLogger.w("FCM", R.string.log_fcm_connection_issue, "Health")
            GatewayLogger.e("FCM", "FCM health check error: ${e.message}", "Health", e)
        }
    }
} 