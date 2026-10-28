package dev.titansys.smsgateway.helpers

import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import android.view.LayoutInflater
import android.widget.ProgressBar
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.core.content.FileProvider
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.FragmentActivity
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import dev.titansys.smsgateway.services.RestApiService
import dev.titansys.smsgateway.services.UpdateResponse
import dev.titansys.smsgateway.BuildConfig
import dev.titansys.smsgateway.R
import java.io.File
import java.io.FileOutputStream
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.ResponseBody
import okio.buffer
import okio.sink
import okio.source
import java.text.DecimalFormat

class AppUpdateManager(private val context: Context) {
    // Resolved on first use, not at construction. RestApiClient.apiService throws
    // when no site URL is stored, and an eager val would raise that from this
    // class's initializer -- outside checkForUpdate()'s try/catch, so the graceful
    // "no update available" path could never run. Unreachable today, since every
    // route here is behind isLoggedIn(), but a future shortcut or notification
    // action jumping straight to the gateway screen would crash instead.
    private val apiService by lazy { RestApiClient.apiService }
    private var onPermissionGranted: (() -> Unit)? = null
    private var currentApkFile: File? = null
    private var onUpdateAvailable: ((UpdateResponse) -> Unit)? = null
    private var progressDialog: AlertDialog? = null
    private var progressBar: ProgressBar? = null
    private var progressText: TextView? = null
    private val decimalFormat = DecimalFormat("#.##")
    
    fun registerPermissionHandler(activity: FragmentActivity, onGranted: () -> Unit) {
        this.onPermissionGranted = onGranted
        
        val launcher = activity.registerForActivityResult(
            ActivityResultContracts.StartActivityForResult()
        ) { result ->
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                if (context.packageManager.canRequestPackageInstalls()) {
                    onPermissionGranted?.invoke()
                }
            }
        }
        
        this.permissionLauncher = launcher
    }

    fun setOnUpdateAvailableListener(listener: (UpdateResponse) -> Unit) {
        this.onUpdateAvailable = listener
    }

    private var permissionLauncher: ActivityResultLauncher<Intent>? = null

    suspend fun checkForUpdate(): UpdateResponse? {
        return try {
            val response = apiService.checkForUpdates()
            if (response.isSuccessful) {
                response.body()?.also { storeFirebaseConfigIfNew(it) }
            } else {
                null
            }
        } catch (e: Exception) {
            null
        }
    }

    /**
     * Picks up Firebase configuration the site has started serving.
     *
     * Two cases reach here without it: a device paired before the admin uploaded
     * google-services.json, and one that signed in with credentials, since
     * device/login predates the config. Storing it on the update poll means
     * neither has to be re-paired -- push starts working after the next app
     * start, once FirebaseApp can be initialised with it.
     */
    private fun storeFirebaseConfigIfNew(response: UpdateResponse) {
        val config = response.data.firebase ?: return
        val session = SessionManager(context)

        if (session.hasFirebaseConfig()) return

        session.saveFirebaseConfig(
            appId = config.app_id,
            apiKey = config.api_key,
            projectId = config.project_id,
            senderId = config.sender_id
        )

        GatewayLogger.i(
            "Update",
            "Stored Firebase configuration from the site; push activates on next start",
            "Helper"
        )
    }

    fun isUpdateAvailable(currentVersion: String, latestVersion: String): Boolean {
        val current = currentVersion.split(".").map { it.toInt() }
        val latest = latestVersion.split(".").map { it.toInt() }
        
        for (i in 0 until minOf(current.size, latest.size)) {
            if (latest[i] > current[i]) return true
            if (latest[i] < current[i]) return false
        }
        return latest.size > current.size
    }

    fun checkForUpdates() {
        CoroutineScope(Dispatchers.Main).launch {
            try {
                val updateInfo = checkForUpdate()
                updateInfo?.let { info ->
                    val currentVersion = BuildConfig.VERSION_NAME
                    
                    if (isUpdateAvailable(currentVersion, info.data.latest_version)) {
                        onUpdateAvailable?.invoke(info)
                    }
                }
            } catch (e: Exception) {
                // Error will be handled by the activity
            }
        }
    }

    fun showUpdateDialog(updateInfo: UpdateResponse) {
        MaterialAlertDialogBuilder(context)
            .setTitle(context.getString(R.string.update_required))
            .setMessage(context.getString(R.string.update_required_message, updateInfo.data.latest_version))
            .setPositiveButton(context.getString(R.string.update)) { _, _ ->
                showProgressDialog()
                CoroutineScope(Dispatchers.Main).launch {
                    try {
                        downloadAndInstallApk(updateInfo.data.apk_url)
                    } catch (e: Exception) {
                        progressDialog?.dismiss()
                        // Error will be handled by the activity
                    }
                }
            }
            .setCancelable(false)
            .show()
    }

    private fun showProgressDialog() {
        val dialogView = LayoutInflater.from(context).inflate(R.layout.dialog_download_progress, null)
        progressBar = dialogView.findViewById(R.id.progressBar)
        progressText = dialogView.findViewById(R.id.progressText)

        progressDialog = MaterialAlertDialogBuilder(context)
            .setView(dialogView)
            .setCancelable(false)
            .show()
    }

    private fun updateProgress(bytesDownloaded: Long, totalBytes: Long) {
        val progress = (bytesDownloaded * 100 / totalBytes).toInt()
        val downloadedSize = formatFileSize(bytesDownloaded)
        val totalSize = formatFileSize(totalBytes)
        
        progressBar?.progress = progress
        progressText?.text = "$downloadedSize / $totalSize ($progress%)"
    }

    private suspend fun downloadAndInstallApk(apkUrl: String) {
        if (!checkInstallPermission()) {
            progressDialog?.dismiss()
            return
        }

        withContext(Dispatchers.IO) {
            try {
                // Create directory if it doesn't exist
                val apkDir = File(context.getExternalFilesDir(null), "latest_apk")
                if (!apkDir.exists()) {
                    apkDir.mkdirs()
                }

                // Clean up any existing APK files
                apkDir.listFiles()?.forEach { it.delete() }

                val client = OkHttpClient()
                val request = Request.Builder()
                    .url(apkUrl)
                    .build()

                val response = client.newCall(request).execute()
                val apkFile = File(apkDir, "gateway_latest.apk")
                currentApkFile = apkFile

                response.body?.let { body ->
                    val contentLength = body.contentLength()
                    var bytesDownloaded = 0L
                    
                    body.byteStream().use { input ->
                        FileOutputStream(apkFile).use { output ->
                            val sink = output.sink().buffer()
                            val source = input.source().buffer()
                            
                            while (true) {
                                val bytes = source.read(sink.buffer, 8192)
                                if (bytes == -1L) break
                                
                                bytesDownloaded = bytesDownloaded + bytes
                                withContext(Dispatchers.Main) {
                                    updateProgress(bytesDownloaded, contentLength)
                                }
                            }
                            sink.flush()
                        }
                    }
                }

                withContext(Dispatchers.Main) {
                    progressDialog?.dismiss()
                    installApk(apkFile)
                }
            } catch (e: Exception) {
                e.printStackTrace()
                // Clean up on error
                currentApkFile?.delete()
                currentApkFile = null
                withContext(Dispatchers.Main) {
                    progressDialog?.dismiss()
                }
                throw e
            }
        }
    }

    private fun checkInstallPermission(): Boolean {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            if (!context.packageManager.canRequestPackageInstalls()) {
                val intent = Intent(
                    Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                    Uri.parse("package:${context.packageName}")
                )
                permissionLauncher?.launch(intent)
                return false
            }
        }
        return true
    }

    private fun installApk(apkFile: File) {
        val intent = Intent(Intent.ACTION_VIEW).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK
            flags = Intent.FLAG_GRANT_READ_URI_PERMISSION
            
            val uri = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
                FileProvider.getUriForFile(
                    context,
                    "${context.packageName}.provider",
                    apkFile
                )
            } else {
                Uri.fromFile(apkFile)
            }
            
            setDataAndType(uri, "application/vnd.android.package-archive")
        }
        
        context.startActivity(intent)
        
        // Schedule cleanup after a short delay to ensure installation has started
        android.os.Handler(context.mainLooper).postDelayed({
            currentApkFile?.delete()
            currentApkFile = null
        }, 5000) // 5 seconds delay
    }

    companion object {
        private val decimalFormat = DecimalFormat("#.##")

        fun formatFileSize(bytes: Long): String {
            val kb = bytes / 1024.0
            val mb = kb / 1024.0
            return when {
                mb >= 1 -> "${decimalFormat.format(mb)} MB"
                else -> "${decimalFormat.format(kb)} KB"
            }
        }
    }
} 