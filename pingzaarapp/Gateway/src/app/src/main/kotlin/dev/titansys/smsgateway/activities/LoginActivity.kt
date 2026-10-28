package dev.titansys.smsgateway.activities

import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.firebase.messaging.FirebaseMessaging
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import kotlinx.coroutines.launch
import dev.titansys.smsgateway.R
import dev.titansys.smsgateway.databinding.ActivityLoginBinding
import dev.titansys.smsgateway.helpers.DeviceInfoProvider
import dev.titansys.smsgateway.helpers.FirebaseConfigurator
import dev.titansys.smsgateway.helpers.GatewayLogger
import dev.titansys.smsgateway.helpers.PairingPayload
import dev.titansys.smsgateway.helpers.RestApiClient
import dev.titansys.smsgateway.helpers.ScheduledTaskManager
import dev.titansys.smsgateway.helpers.SessionManager
import dev.titansys.smsgateway.BuildConfig
import dev.titansys.smsgateway.services.LoginRequest
import dev.titansys.smsgateway.services.PairRequest

/**
 * Pairing / login screen.
 *
 * Two modes, decided at build time by [BuildConfig.PREBUILT]:
 *
 * - **Prebuilt** (`-Pprebuilt=true`): scan only, and unbranded. The APK shipped
 *   with Zender belongs to no particular site, so a credential form would have
 *   nowhere to post until a QR supplies a URL, and the placeholder logo is hidden
 *   rather than shipped.
 * - **Built from source** (the default): the credential form as well, because
 *   whoever built it knows their own site. The QR path stays available alongside.
 *
 * Both paths end the same way -- a stored site URL, a device credential and, when
 * the site has it, Firebase configuration.
 */
class LoginActivity : AppCompatActivity() {

    private lateinit var binding: ActivityLoginBinding
    private lateinit var deviceHelper: DeviceInfoProvider
    private lateinit var sessionManager: SessionManager

    private val scanQrCodeLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            when (result.resultCode) {
                QRScannerActivity.RESULT_SUCCESS -> {
                    val payload = PairingPayload.parse(
                        result.data?.getStringExtra(QRScannerActivity.EXTRA_RESULT)
                    )

                    if (payload == null) {
                        // Covers a QR from an unrelated app, and the bare-hash QR
                        // that older Zender releases rendered -- that one carries no
                        // site URL, so a build with none compiled in cannot use it.
                        GatewayLogger.e("Pair", "Scanned code is not a v1 pairing payload", "Activity")
                        Toast.makeText(this, getString(R.string.pair_invalid_qr), Toast.LENGTH_LONG).show()
                    } else {
                        confirmThenPair(payload)
                    }
                }

                QRScannerActivity.RESULT_CANCELLED ->
                    Toast.makeText(this, getString(R.string.qr_scan_cancelled), Toast.LENGTH_SHORT).show()

                QRScannerActivity.RESULT_ERROR ->
                    Toast.makeText(this, getString(R.string.qr_scan_error), Toast.LENGTH_SHORT).show()
            }
        }

    companion object {
        private const val MIN_SDK_VERSION = 26 // Android 8.0 (Oreo)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setTheme(R.style.LoginTheme)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        deviceHelper = DeviceInfoProvider(this)
        sessionManager = SessionManager(this)

        if (sessionManager.isLoggedIn()) {
            startPermissionSetupActivity()
            return
        }

        binding.qrLoginButton.setOnClickListener {
            scanQrCodeLauncher.launch(Intent(this, QRScannerActivity::class.java))
        }

        if (BuildConfig.PREBUILT) {
            binding.credentialGroup.visibility = View.GONE
            // The bundled logo is a "BRAND" placeholder. Shipping it would look
            // unfinished, and shipping real branding would be wrong for a product
            // customers resell under their own name. A self-build supplies its own
            // drawable/logo.png and this shows again.
            binding.logoImage.visibility = View.GONE
        } else {
            // The pairing explanation is for the prebuilt, where the QR is the
            // whole screen. Here the logo and the sign-in form are present too,
            // and keeping it pushed the content past a phone viewport.
            binding.pairIntroGroup.visibility = View.GONE

            // A source build is made for one specific Zender, whose address is
            // compiled in and required by the build. Bind the client to it now so
            // both sign-in and QR pairing use it, and neither can be pointed
            // somewhere else at runtime.
            RestApiClient.configure(BuildConfig.DEFAULT_SITE_URL)
            binding.loginButton.setOnClickListener { attemptCredentialLogin() }
        }

        checkAndroidVersion()
    }

    private fun checkAndroidVersion() {
        if (Build.VERSION.SDK_INT < MIN_SDK_VERSION) {
            GatewayLogger.w(
                "Pair",
                GatewayLogger.appContext.getString(
                    R.string.log_android_version_check,
                    Build.VERSION.RELEASE, Build.VERSION.SDK_INT, MIN_SDK_VERSION
                ),
                "Activity"
            )
            MaterialAlertDialogBuilder(this)
                .setTitle(getString(R.string.unsupported_device))
                .setMessage(getString(R.string.unsupported_device_message, Build.VERSION.RELEASE))
                .setCancelable(false)
                .setPositiveButton(getString(R.string.exit)) { _, _ -> finish() }
                .show()
        }
    }

    private fun showLoading(show: Boolean) {
        binding.loginProgressBar.visibility = if (show) View.VISIBLE else View.GONE
        binding.qrLoginButton.isEnabled = !show

        if (!BuildConfig.PREBUILT) {
            binding.loginButton.isEnabled = !show
            binding.emailInput.isEnabled = !show
            binding.passwordInput.isEnabled = !show
        }
    }

    /**
     * Shows which site the QR points at, and pairs only if the user agrees.
     *
     * A QR is trivially forged, and this app was made universal precisely so it
     * takes its server from whatever it scans. Without this step, a swapped
     * sticker or a "rescan to fix your gateway" image is enough to repoint a
     * working gateway at attacker infrastructure, which then receives the
     * plaintext of every SMS the phone handles -- frequently one-time codes.
     * Naming the host makes that redirection visible rather than silent.
     *
     * http:// is allowed rather than refused: plenty of installs are internal or
     * have no certificate, and a gateway that cannot pair is worse than one that
     * warns. But the warning is explicit, because on http the traffic is also
     * readable by anyone on the same network.
     */
    private fun confirmThenPair(payload: PairingPayload) {
        // Only the prebuilt takes its address from a QR. A source build is tied to
        // the Zender compiled into it, so a code from another install is refused
        // rather than silently redirecting the gateway.
        if (!BuildConfig.PREBUILT) {
            val own = BuildConfig.DEFAULT_SITE_URL.let { if (it.endsWith("/")) it else "$it/" }
            if (!payload.siteUrl.equals(own, ignoreCase = true)) {
                GatewayLogger.e("Pair", "QR names ${payload.siteUrl}, this build serves $own", "Activity")
                Toast.makeText(this, getString(R.string.pair_wrong_server), Toast.LENGTH_LONG).show()
                return
            }
            performPairing(payload)
            return
        }

        // Derived with the same parser that will open the connection.
        //
        // java.net.URI disagrees with OkHttp on non-ASCII hosts: for a Cyrillic
        // homoglyph domain it returns a null host without throwing, so the old
        // fallback displayed the raw URL -- which renders identically to the
        // legitimate ASCII domain -- while OkHttp quietly connected to the
        // punycode form. That made this dialog, the only safety check the
        // prebuilt has, spoofable by anyone who can get a QR in front of someone.
        //
        // HttpUrl also returns the punycode form, so a homoglyph domain now shows
        // as xn--... and reads as obviously wrong instead of invisible.
        val parsed = payload.siteUrl.toHttpUrlOrNull()
        if (parsed == null) {
            GatewayLogger.e("Pair", "Scanned URL is not a usable HTTP URL", "Activity")
            Toast.makeText(this, getString(R.string.pair_invalid_qr), Toast.LENGTH_LONG).show()
            return
        }
        val host = parsed.host

        val insecure = payload.siteUrl.startsWith("http://", ignoreCase = true)
        val message = getString(R.string.pair_confirm_message, host) +
            if (insecure) getString(R.string.pair_confirm_insecure) else ""

        MaterialAlertDialogBuilder(this)
            .setTitle(getString(R.string.pair_confirm_title))
            .setMessage(message)
            .setNegativeButton(android.R.string.cancel, null)
            .setPositiveButton(getString(R.string.pair_confirm_continue)) { _, _ ->
                performPairing(payload)
            }
            .show()
    }

    /**
     * Exchanges the scanned token for a device credential.
     *
     * The site URL is applied to the API client first, because until that happens
     * there is no client at all -- this build has no compiled-in base URL. It is
     * only persisted once the site answers successfully, so a mistyped or hostile
     * QR leaves nothing behind on the device.
     */
    private fun performPairing(payload: PairingPayload) {
        val androidId = deviceHelper.getAndroidId() ?: run {
            Toast.makeText(this, getString(R.string.unable_to_get_device_id), Toast.LENGTH_SHORT).show()
            return
        }

        showLoading(true)
        RestApiClient.configure(payload.siteUrl)

        lifecycleScope.launch {
            try {
                val response = RestApiClient.apiService.pair(
                    PairRequest(
                        token = payload.token,
                        device_unique = androidId,
                        device_model = deviceHelper.getDeviceModel(),
                        device_version = deviceHelper.getDeviceVersion(),
                        device_manufacturer = deviceHelper.getDeviceManufacturer()
                    )
                )

                val body = response.body()

                if (!response.isSuccessful || body == null || body.status != 200) {
                    GatewayLogger.e(
                        "Pair",
                        "Pairing rejected: http=${response.code()} status=${body?.status} msg=${body?.message}",
                        "Activity"
                    )
                    Toast.makeText(
                        this@LoginActivity,
                        body?.message?.takeIf { it.isNotBlank() } ?: getString(R.string.pair_failed),
                        Toast.LENGTH_LONG
                    ).show()
                    showLoading(false)
                    return@launch
                }

                val data = body.data
                if (data == null || data.hash.isEmpty() || data.name.isEmpty()) {
                    GatewayLogger.e("Pair", "Pairing succeeded but returned no usable device record", "Activity")
                    Toast.makeText(this@LoginActivity, getString(R.string.pair_failed), Toast.LENGTH_LONG).show()
                    showLoading(false)
                    return@launch
                }

                // Only now is the site trusted enough to remember.
                sessionManager.saveSiteUrl(payload.siteUrl)
                sessionManager.saveLoginSession(
                    hash = data.hash,
                    deviceName = data.name,
                    receiveSms = data.receive_sms,
                    randomSend = data.random_send,
                    randomMin = data.random_min,
                    randomMax = data.random_max
                )

                val firebase = data.firebase
                if (firebase == null) {
                    // Paired, but the site cannot notify this device of anything.
                    // Say so plainly rather than proceeding to a gateway screen that
                    // looks healthy and silently never sends.
                    showLoading(false)
                    MaterialAlertDialogBuilder(this@LoginActivity)
                        .setTitle(getString(R.string.pair_no_push_title))
                        .setMessage(getString(R.string.pair_no_push_message))
                        .setCancelable(false)
                        .setPositiveButton(getString(R.string.exit)) { _, _ -> finish() }
                        .show()
                    return@launch
                }

                sessionManager.saveFirebaseConfig(
                    appId = firebase.app_id,
                    apiKey = firebase.api_key,
                    projectId = firebase.project_id,
                    senderId = firebase.sender_id
                )

                if (FirebaseConfigurator.initialiseIfConfigured(this@LoginActivity, sessionManager)) {
                    FirebaseMessaging.getInstance().token.addOnCompleteListener { task ->
                        if (task.isSuccessful) {
                            ScheduledTaskManager.scheduleTokenSync(this@LoginActivity, task.result)
                        } else {
                            GatewayLogger.e("Pair", "FCM token request failed: ${task.exception?.message}", "Activity")
                        }
                    }
                }

                startPermissionSetupActivity()
            } catch (e: Exception) {
                GatewayLogger.e("Pair", "Pairing failed against ${payload.siteUrl}: ${e.message}", "Activity", e)
                Toast.makeText(
                    this@LoginActivity,
                    getString(R.string.pair_unreachable, payload.siteUrl),
                    Toast.LENGTH_LONG
                ).show()
                showLoading(false)
            }
        }
    }

    /**
     * Credential sign-in, for builds made from source.
     *
     * The site URL is asked for here rather than compiled in, so one source tree
     * still serves anyone who builds it. Whoever builds for a single known install
     * can set app-config-defaultSiteUrl in libs.versions.toml to prefill it.
     */
    private fun attemptCredentialLogin() {
        val email = binding.emailInput.text.toString().trim()
        val password = binding.passwordInput.text.toString()

        if (email.isEmpty() || password.isEmpty()) {
            Toast.makeText(this, getString(R.string.please_enter_credentials), Toast.LENGTH_SHORT).show()
            return
        }

        val androidId = deviceHelper.getAndroidId() ?: run {
            Toast.makeText(this, getString(R.string.unable_to_get_device_id), Toast.LENGTH_SHORT).show()
            return
        }

        // Compiled in; the build refuses to produce an app without it.
        val normalised = BuildConfig.DEFAULT_SITE_URL.let { if (it.endsWith("/")) it else "$it/" }

        showLoading(true)
        RestApiClient.configure(normalised)

        lifecycleScope.launch {
            try {
                val response = RestApiClient.apiService.login(
                    LoginRequest(
                        email = email,
                        password = password,
                        device_unique = androidId,
                        device_model = deviceHelper.getDeviceModel(),
                        device_version = deviceHelper.getDeviceVersion(),
                        device_manufacturer = deviceHelper.getDeviceManufacturer()
                    )
                )

                val body = response.body()

                if (!response.isSuccessful || body == null || body.status != 200 || body.data == null) {
                    Toast.makeText(
                        this@LoginActivity,
                        body?.message?.takeIf { it.isNotBlank() } ?: getString(R.string.unable_to_login_em2004),
                        Toast.LENGTH_LONG
                    ).show()
                    showLoading(false)
                    return@launch
                }

                val data = body.data
                sessionManager.saveSiteUrl(normalised)
                sessionManager.saveLoginSession(
                    hash = data.hash,
                    deviceName = data.name,
                    receiveSms = data.receive_sms,
                    randomSend = data.random_send,
                    randomMin = data.random_min,
                    randomMax = data.random_max
                )

                // The credential endpoint predates runtime Firebase config and does
                // not return it, so push stays off until the next device/update poll
                // supplies it. Receiving SMS works either way.
                startPermissionSetupActivity()
            } catch (e: Exception) {
                GatewayLogger.e("Login", "Sign-in failed against $normalised: ${e.message}", "Activity", e)
                Toast.makeText(
                    this@LoginActivity,
                    getString(R.string.pair_unreachable, normalised),
                    Toast.LENGTH_LONG
                ).show()
                showLoading(false)
            }
        }
    }

    private fun startPermissionSetupActivity() {
        startActivity(Intent(this, PermissionSetupActivity::class.java))
        finish()
    }
}
