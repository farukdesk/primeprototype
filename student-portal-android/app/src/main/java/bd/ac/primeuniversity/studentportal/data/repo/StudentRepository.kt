package bd.ac.primeuniversity.studentportal.data.repo

import android.content.Context
import android.os.Build
import bd.ac.primeuniversity.studentportal.data.api.ApiService
import bd.ac.primeuniversity.studentportal.data.api.RetrofitClient
import bd.ac.primeuniversity.studentportal.data.api.StaffApiService
import bd.ac.primeuniversity.studentportal.data.local.SecureStorage
import bd.ac.primeuniversity.studentportal.data.model.AppNotificationsResponse
import bd.ac.primeuniversity.studentportal.data.model.AppVersionResponse
import bd.ac.primeuniversity.studentportal.data.model.BaseResponse
import bd.ac.primeuniversity.studentportal.data.model.FinancesResponse
import bd.ac.primeuniversity.studentportal.data.model.LeaveApplyResponse
import bd.ac.primeuniversity.studentportal.data.model.LoginResponse
import bd.ac.primeuniversity.studentportal.data.model.MeResponse
import bd.ac.primeuniversity.studentportal.data.model.Notice
import bd.ac.primeuniversity.studentportal.data.model.NoticesResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffAttendanceResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffLeavesResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffLoginResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffMeResponse
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import retrofit2.Response
import java.io.IOException

/**
 * Single entry point to the student and staff APIs. Owns the [SecureStorage]
 * token + role and the in-memory session. Exposes suspend functions returning
 * [AppResult] so the UI never has to touch Retrofit directly.
 */
class StudentRepository private constructor(context: Context) {

    private val appContext = context.applicationContext
    val storage = SecureStorage(appContext)

    /** Set to true by the auth interceptor when the server rejects the token. */
    @Volatile
    var sessionExpired: Boolean = false
        private set

    private val api: ApiService = RetrofitClient.api(storage) {
        sessionExpired = true
    }

    private val staffApi: StaffApiService = RetrofitClient.staffApi(storage) {
        sessionExpired = true
    }

    private val deviceName: String
        get() = "${Build.MANUFACTURER} ${Build.MODEL}".trim()

    val hasToken: Boolean get() = !storage.token.isNullOrEmpty()

    /** Whether the signed-in account is an employee (staff view). */
    val isStaff: Boolean get() = storage.role == SecureStorage.ROLE_STAFF

    // ── Auth ──────────────────────────────────────────────────────────────────

    suspend fun login(login: String, password: String): AppResult<LoginResponse> =
        call {
            api.login(login.trim(), password, storage.deviceId, deviceName)
        }.also { result ->
            if (result is AppResult.Success) {
                result.data.token?.let {
                    storage.token = it
                    storage.role = SecureStorage.ROLE_STUDENT
                    sessionExpired = false
                }
            }
        }

    /** Employee sign-in against the staff API (admin/api/auth/login.php). */
    suspend fun staffLogin(login: String, password: String): AppResult<StaffLoginResponse> =
        call {
            staffApi.login(login.trim(), password, storage.deviceId, deviceName)
        }.also { result ->
            if (result is AppResult.Success) {
                result.data.token?.let {
                    storage.token = it
                    storage.role = SecureStorage.ROLE_STAFF
                    sessionExpired = false
                }
            }
        }

    suspend fun me(): AppResult<MeResponse> = call { api.me() }

    /** Employee profile + leave balance + today's attendance + stats. */
    suspend fun staffMe(): AppResult<StaffMeResponse> = call { staffApi.me() }

    suspend fun logout() {
        withContext(Dispatchers.IO) {
            try {
                if (isStaff) staffApi.logout() else api.logout()
            } catch (_: Exception) {
                // Best-effort; clear the local session regardless.
            }
            storage.clear()
            sessionExpired = false
        }
    }

    fun clearSession() {
        storage.clear()
        sessionExpired = false
    }

    // ── Notices (role-aware) ───────────────────────────────────────────────────────

    suspend fun getNotices(type: String, page: Int): AppResult<NoticesResponse> =
        call { if (isStaff) staffApi.getNotices(type, page) else api.getNotices(type, page) }

    suspend fun getNoticeDetail(id: Int, type: String): AppResult<Notice> {
        val res = call {
            if (isStaff) staffApi.getNoticeDetail(id, type) else api.getNoticeDetail(id, type)
        }
        return when (res) {
            is AppResult.Success -> {
                val notice = res.data.notice
                if (notice != null) AppResult.Success(notice)
                else AppResult.Error("Notice not found.")
            }
            is AppResult.Error -> res
        }
    }

    // ── Finances (students only) ───────────────────────────────────────────────────

    suspend fun getFinances(): AppResult<FinancesResponse> = call { api.getFinances() }

    // ── Announcements (push notification history) ─────────────────────────────────────

    /** Announcements published from the admin panel's App Notification module. */
    suspend fun getAppNotifications(page: Int = 1): AppResult<AppNotificationsResponse> =
        call {
            if (isStaff) staffApi.getAppNotifications(page) else api.getAppNotifications(page)
        }

    /** Latest published app version, for the self-hosted update prompt. */
    suspend fun getLatestAppVersion(): AppResult<AppVersionResponse> =
        call { staffApi.getAppVersion() }

    // ── Staff: attendance & leave management ─────────────────────────────────────────

    /** Day-wise attendance for a payroll month (YYYY-MM). */
    suspend fun getStaffAttendance(month: String): AppResult<StaffAttendanceResponse> =
        call { staffApi.getAttendance(month) }

    /** Leave balance + the employee's leave requests. */
    suspend fun getStaffLeaves(): AppResult<StaffLeavesResponse> =
        call { staffApi.getLeaves() }

    /** Submit a new leave request. */
    suspend fun applyStaffLeave(
        category: String,
        startDate: String,
        endDate: String,
        reason: String,
        payType: String? = null,
        startTime: String? = null,
        endTime: String? = null,
    ): AppResult<LeaveApplyResponse> =
        call { staffApi.applyLeave(category, startDate, endDate, reason, payType, startTime, endTime) }

    // ── Push notifications ────────────────────────────────────────────────────────────

    /**
     * Remembers the latest FCM token and, when someone is signed in, registers
     * it with the server so the admin panel can push notifications to this
     * device. Safe to call repeatedly; it only hits the network when needed.
     */
    suspend fun cacheAndSyncPushToken(fcmToken: String) {
        if (fcmToken.isBlank()) return
        storage.fcmToken = fcmToken
        syncPushToken()
    }

    /**
     * Registers the stored FCM token with the server if signed in and the
     * token has not already been registered. Students register against the
     * student API; employees against the staff API (api_push_tokens).
     * Best-effort: never throws.
     */
    suspend fun syncPushToken() {
        val fcmToken = storage.fcmToken
        if (fcmToken.isNullOrBlank() || !hasToken) return
        if (fcmToken == storage.registeredFcmToken) return

        withContext(Dispatchers.IO) {
            try {
                val response = if (isStaff) {
                    staffApi.registerPushToken(fcmToken, storage.deviceId)
                } else {
                    api.registerPushToken(fcmToken, storage.deviceId)
                }
                if (response.isSuccessful && response.body()?.ok == true) {
                    storage.registeredFcmToken = fcmToken
                }
            } catch (_: Exception) {
                // Best-effort; will retry on the next app start or token refresh.
            }
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────

    /**
     * Executes a Retrofit call on the IO dispatcher and normalises the outcome
     * into an [AppResult], extracting the API's `error` message when present.
     */
    private suspend fun <T : BaseResponse> call(block: suspend () -> Response<T>): AppResult<T> =
        withContext(Dispatchers.IO) {
            try {
                val response = block()
                val body = response.body()
                when {
                    response.code() == 401 ->
                        AppResult.Error("Your session has expired. Please sign in again.", unauthorized = true)
                    response.isSuccessful && body != null && body.ok ->
                        AppResult.Success(body)
                    body?.error != null ->
                        AppResult.Error(body.error!!)
                    else ->
                        AppResult.Error(parseError(response))
                }
            } catch (e: IOException) {
                AppResult.Error("No internet connection. Please check your network.")
            } catch (e: Exception) {
                AppResult.Error(e.message ?: "Something went wrong. Please try again.")
            }
        }

    private fun parseError(response: Response<*>): String {
        return try {
            val raw = response.errorBody()?.string()
            if (!raw.isNullOrBlank()) {
                val parsed = Gson().fromJson(raw, BaseResponse::class.java)
                parsed?.error ?: "Server error (${response.code()}). Please try again."
            } else {
                "Server error (${response.code()}). Please try again."
            }
        } catch (_: Exception) {
            "Server error (${response.code()}). Please try again."
        }
    }

    companion object {
        @Volatile
        private var instance: StudentRepository? = null

        fun get(context: Context): StudentRepository =
            instance ?: synchronized(this) {
                instance ?: StudentRepository(context).also { instance = it }
            }
    }
}
