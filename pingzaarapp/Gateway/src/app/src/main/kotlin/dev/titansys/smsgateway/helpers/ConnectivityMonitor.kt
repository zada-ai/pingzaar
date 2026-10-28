package dev.titansys.smsgateway.helpers

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest

/**
 * Smart network connectivity monitor for pause/resume functionality
 * Monitors both internet (for API operations) and cellular (for SMS/USSD)
 *
 * Key features:
 * - Real-time network state monitoring
 * - Separates internet connectivity from cellular availability
 * - Callbacks for automatic pause/resume of operations
 * - Thread-safe implementation
 */
class ConnectivityMonitor(private val context: Context) {

    companion object {
        private const val TAG = "ConnectivityMonitor"
    }

    private val connectivityManager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
    private var networkCallback: ConnectivityManager.NetworkCallback? = null

    @Volatile
    private var isInternetAvailable = false

    /**
     * Check if device has internet connectivity (for API operations)
     * @return true if internet is available and validated, false otherwise
     */
    fun isInternetAvailable(): Boolean = isInternetAvailable

    /**
     * Check if device has cellular network (for SMS/MMS/USSD)
     * Note: Cellular network can exist without internet (e.g., no data plan)
     * @return true if cellular transport is available, false otherwise
     */
    fun isCellularAvailable(): Boolean {
        val network = connectivityManager.activeNetwork ?: return false
        val capabilities = connectivityManager.getNetworkCapabilities(network) ?: return false
        return capabilities.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR)
    }

    /**
     * Register network state change callback
     * Automatically handles pause/resume of operations based on internet availability
     *
     * @param onInternetAvailable Callback when internet becomes available (resume API operations)
     * @param onInternetLost Callback when internet is lost (pause API operations)
     */
    fun registerNetworkCallback(
        onInternetAvailable: () -> Unit,
        onInternetLost: () -> Unit
    ) {
        val networkRequest = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .addCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
            .build()

        networkCallback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                isInternetAvailable = true
                GatewayLogger.i("NET", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_network_internet_available), "Monitor")
                onInternetAvailable()
            }

            override fun onLost(network: Network) {
                isInternetAvailable = false
                GatewayLogger.i("NET", GatewayLogger.appContext.getString(dev.titansys.smsgateway.R.string.log_network_internet_lost), "Monitor")
                onInternetLost()
            }

            override fun onCapabilitiesChanged(network: Network, capabilities: NetworkCapabilities) {
                val hasInternet = capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
                                 capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)

                if (hasInternet != isInternetAvailable) {
                    isInternetAvailable = hasInternet
                    if (hasInternet) {
                        GatewayLogger.d("NET", "Internet capabilities restored", "Monitor")
                        onInternetAvailable()
                    } else {
                        GatewayLogger.d("NET", "Internet capabilities lost", "Monitor")
                        onInternetLost()
                    }
                }
            }
        }

        try {
            connectivityManager.registerNetworkCallback(networkRequest, networkCallback!!)

            // Initialize current state
            isInternetAvailable = checkCurrentInternetState()

            GatewayLogger.d("NET", "Network callback registered - current state: internet=${isInternetAvailable}, cellular=${isCellularAvailable()}", "Monitor")
        } catch (e: Exception) {
            GatewayLogger.e("NET", "Error registering network callback: ${e.message}", "Monitor", e)
        }
    }

    /**
     * Check current internet state synchronously
     * Used for initialization and manual state checks
     * @return true if internet is currently available and validated
     */
    private fun checkCurrentInternetState(): Boolean {
        return try {
            val network = connectivityManager.activeNetwork ?: return false
            val capabilities = connectivityManager.getNetworkCapabilities(network) ?: return false
            capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
                   capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
        } catch (e: Exception) {
            GatewayLogger.e("NET", "Error checking internet state: ${e.message}", "Monitor", e)
            false
        }
    }

    /**
     * Unregister network callback
     * Should be called when the monitor is no longer needed (e.g., app termination)
     */
    fun unregisterNetworkCallback() {
        networkCallback?.let {
            try {
                connectivityManager.unregisterNetworkCallback(it)
                GatewayLogger.d("NET", "Network callback unregistered", "Monitor")
            } catch (e: Exception) {
                GatewayLogger.e("NET", "Error unregistering network callback: ${e.message}", "Monitor", e)
            }
        }
        networkCallback = null
        isInternetAvailable = false
    }
}
