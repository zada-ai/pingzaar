package dev.titansys.smsgateway.services

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST

data class LoginRequest(
    val email: String,
    val password: String,
    val device_unique: String,
    val device_model: String,
    val device_version: String,
    val device_manufacturer: String
)

data class ApiResponse<T>(
    val status: Int,
    val message: String,
    val data: T?
)

data class QRScanRequest(
    val hash: String,
    val device_unique: String,
    val device_model: String,
    val device_version: String,
    val device_manufacturer: String
)

data class LoginData(
    val hash: String,
    val name: String,
    val receive_sms: Int,
    val random_send: Int,
    val random_min: Int,
    val random_max: Int
)

/**
 * Exchanges a one-time pairing token for a device credential.
 *
 * Replaces the old scan flow, where the QR carried the account hash itself --
 * a durable credential, so a screenshot of that QR let anyone attach a device
 * to the account. This token is single-use and expires.
 */
data class PairRequest(
    val token: String,
    val device_unique: String,
    val device_model: String,
    val device_version: String,
    val device_manufacturer: String
)

/**
 * The Firebase client configuration the site holds, or null when the admin has
 * not uploaded google-services.json. Null means push is impossible, and the app
 * says so rather than pairing into a silently useless state.
 */
data class FirebaseConfigData(
    val app_id: String,
    val api_key: String,
    val project_id: String,
    val sender_id: String
)

data class PairData(
    val hash: String,
    val name: String,
    val receive_sms: Int,
    val random_send: Int,
    val random_min: Int,
    val random_max: Int,
    val firebase: FirebaseConfigData?
)

data class TokenRequest(
    val hash: String,
    val did: String,
    val token: String
)

data class TokenResponse(
    val status: Int,
    val message: Boolean?,
    val data: Boolean?
)

data class ReceivedRequest(
    val hash: String,
    val did: String,
    val sim: Int,
    val message_id: Long,
    val sender: String,
    val message: String,
    val attachment: String
)

data class ReceivedResponse(
    val status: Int,
    val message: Boolean?,
    val data: Boolean?
)

data class ReportRequest(
    val hash: String,
    val did: String,
    val uid: Int,
    val mid: Int,   
    val code: String?,
    val status: String
)

data class ReportResponse(
    val status: Int,
    val message: Boolean?,
    val data: Boolean?
)



data class MessageRequest(
    val id: Int,
    val uid: Int,
    val cid: Int,
    val cstatus: Int,
    val sim: Int,
    val recipient: String,
    val message: String,
    val priority: Int,
    val attachment: String? = null // Optional field for attachments
) 

data class MessageResponse(
    val status: Int,
    val message: Boolean?,
    val data: List<MessageRequest>
)

data class MessageFetchRequest(
    val hash: String,
    val did: String,
    val diff: Int = 0
)

data class UssdRequest(
    val id: Int,
    val uid: Int,
    val sim: Int,
    val code: String
)

data class UssdResponse(
    val status: Int,
    val message: Boolean?,
    val data: List<UssdRequest>
)

data class UssdFetchRequest(
    val hash: String,
    val did: String
)

data class UssdRequestResponse(
    val status: Int,
    val message: Boolean?,
    val data: Boolean?
)

data class UssdResponseRequest(
    val hash: String,
    val did: String,
    val ussd_id: Int,
    val response: String,
    val status: Int // 3 = success, 4 = failed
)

data class DeviceDefaultRequest(
    val hash: String,
    val did: String
)

data class DeviceDefaultResponse(
    val status: Int,
    val message: Boolean?,
    val data: DeviceDefaultData
)

data class DeviceDefaultData(
    val name: String,
    val receive_sms: Int,
    val random_send: Int,
    val random_min: Int,
    val random_max: Int,
    val packages: String?
)

data class UpdateResponse(
    val status: Int,
    val message: Boolean?,
    val data: UpdateData
)

data class UpdateData(
    val latest_version: String,
    val apk_url: String,
    // Null when the site has no google-services.json. Present here as well as on
    // the pairing response so a device that paired before the admin uploaded it,
    // or that signed in with credentials, picks it up without re-pairing.
    val firebase: FirebaseConfigData? = null
)

interface RestApiService {
    @POST("device")
    suspend fun getDeviceStatus(@Body request: DeviceDefaultRequest): Response<DeviceDefaultResponse>

    @GET("device/update")
    suspend fun checkForUpdates(): Response<UpdateResponse>

    @POST("device/login")
    suspend fun login(@Body request: LoginRequest): Response<ApiResponse<LoginData>>

    @POST("device/scan")
    suspend fun scanQR(@Body request: QRScanRequest): Response<ApiResponse<LoginData>>

    @POST("device/pair")
    suspend fun pair(@Body request: PairRequest): Response<ApiResponse<PairData>>

    @POST("device/token")
    suspend fun submitToken(@Body request: TokenRequest): Response<TokenResponse>

    @POST("device/received")
    suspend fun receivedMessage(@Body request: ReceivedRequest): Response<ReceivedResponse>

    @POST("device/messages")
    suspend fun getMessages(@Body request: MessageFetchRequest): Response<MessageResponse>

    @POST("device/report")
    suspend fun reportSentMessage(@Body request: ReportRequest): Response<ReportResponse>

    @POST("device/ussd")
    suspend fun getUssdRequests(@Body request: UssdFetchRequest): Response<UssdResponse>

    @POST("device/response")
    suspend fun sendUssdResponse(@Body response: UssdResponseRequest): Response<UssdRequestResponse>
}
