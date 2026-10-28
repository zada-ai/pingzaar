package dev.titansys.smsgateway.helpers

import com.google.gson.GsonBuilder
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import dev.titansys.smsgateway.services.RestApiService
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Retrofit client bound to whichever Zender site this device is paired to.
 *
 * The base URL used to be a compile-time constant, which is why every install
 * needed its own build of the app. It now comes from the pairing QR and is
 * stored on the device, so one APK serves any site.
 *
 * [configure] must run before [apiService] is touched. The Application class
 * does that on startup for an already-paired device, and the pairing flow does
 * it the moment a QR is scanned.
 */
object RestApiClient {

    /**
     * Logs headers, not bodies. The previous BODY level wrote every request and
     * response in full to logcat -- on a gateway that is the plaintext of every
     * SMS plus the device credential, readable by anything holding READ_LOGS and
     * by anyone with adb.
     */
    private val okHttpClient = OkHttpClient.Builder()
        .addInterceptor(HttpLoggingInterceptor().apply {
            level = HttpLoggingInterceptor.Level.HEADERS
        })
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    private val gson = GsonBuilder().setLenient().create()

    @Volatile private var baseUrl: String? = null
    @Volatile private var service: RestApiService? = null

    /**
     * Points the client at a site. Rebuilding only when the URL actually changes
     * keeps the connection pool warm across the many calls that follow.
     */
    @Synchronized
    fun configure(url: String) {
        val normalised = if (url.endsWith("/")) url else "$url/"
        if (normalised == baseUrl && service != null) return

        baseUrl = normalised
        service = Retrofit.Builder()
            .baseUrl(normalised)
            .client(okHttpClient)
            .addConverterFactory(GsonConverterFactory.create(gson))
            .build()
            .create(RestApiService::class.java)

        GatewayLogger.d("API", "Client bound to $normalised", "RestApiClient")
    }

    /** True once [configure] has been called with a usable URL. */
    val isConfigured: Boolean
        get() = service != null

    /**
     * Throws rather than returning null. Every call site assumed a non-null
     * service back when the URL was a constant, so a null here would surface as
     * an unrelated NPE deep inside a worker. An unpaired device reaching this is
     * a bug, and the message says which one.
     */
    val apiService: RestApiService
        get() = service ?: throw IllegalStateException(
            "RestApiClient used before configure(). This device is not paired to a site."
        )
}
