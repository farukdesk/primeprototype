package bd.ac.primeuniversity.studentportal.data.repo

import android.content.Context
import android.net.Uri
import android.os.Build
import android.provider.OpenableColumns
import bd.ac.primeuniversity.studentportal.BuildConfig
import bd.ac.primeuniversity.studentportal.data.api.ApiService
import bd.ac.primeuniversity.studentportal.data.api.RetrofitClient
import bd.ac.primeuniversity.studentportal.data.api.StaffApiService
import bd.ac.primeuniversity.studentportal.data.local.SecureStorage
import bd.ac.primeuniversity.studentportal.data.model.AppNotificationsResponse
import bd.ac.primeuniversity.studentportal.data.model.AppVersionResponse
import bd.ac.primeuniversity.studentportal.data.model.BaseResponse
import bd.ac.primeuniversity.studentportal.data.model.CourseOffersResponse
import bd.ac.primeuniversity.studentportal.data.model.FinancesResponse
import bd.ac.primeuniversity.studentportal.data.model.LeaveApplyResponse
import bd.ac.primeuniversity.studentportal.data.model.LeaveApprovalsResponse
import bd.ac.primeuniversity.studentportal.data.model.LoginResponse
import bd.ac.primeuniversity.studentportal.data.model.MeResponse
import bd.ac.primeuniversity.studentportal.data.model.Notice
import bd.ac.primeuniversity.studentportal.data.model.NoticesResponse
import bd.ac.primeuniversity.studentportal.data.model.SimpleResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffAttendanceResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffLeavesResponse
import bd.ac.primeuniversity.studentportal.data.model.SaveStudentAttendanceResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffLoginResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffMeResponse
import bd.ac.primeuniversity.studentportal.data.model.SubjectStudentsResponse
import bd.ac.primeuniversity.studentportal.data.model.SupportTicketCommentResponse
import bd.ac.primeuniversity.studentportal.data.model.SupportTicketCreateResponse
import bd.ac.primeuniversity.studentportal.data.model.SupportTicketDetailResponse
import bd.ac.primeuniversity.studentportal.data.model.SupportTicketsResponse
import bd.ac.primeuniversity.studentportal.data.model.TeachSubjectsResponse
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
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
                // Detach this device's push registration first so the next
                // account that signs in on this phone does not keep receiving
                // this user's notifications (and vice versa).
                val fcm = storage.fcmToken
                if (!fcm.isNullOrBlank()) {
                    if (isStaff) staffApi.unregisterPushToken(fcm, storage.deviceId)
                    else api.unregisterPushToken(fcm, storage.deviceId)
                }
            } catch (_: Exception) {
                // Best-effort only.
            }
            try {
                if (isStaff) staffApi.logout() else api.logout()
            } catch (_: Exception) {
                // Best-effort; clear the local session regardless.
            }
            storage.clear()
            sessionExpired = false
        }
    }

    /** Uploads a new profile photo for the signed-in student. */
    suspend fun uploadProfilePhoto(photo: Uri): AppResult<SimpleResponse> {
        val part = buildPhotoPart(photo)
            ?: return AppResult.Error("Could not read the selected image.")
        return call { api.uploadProfilePhoto(part) }
    }

    /** Reads a content Uri and wraps it as the multipart "photo" file part. */
    private fun buildPhotoPart(uri: Uri): MultipartBody.Part? {
        val resolver = appContext.contentResolver
        return try {
            val name = resolver.query(uri, null, null, null, null)?.use { c ->
                val idx = c.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                if (c.moveToFirst() && idx >= 0) c.getString(idx) else null
            } ?: uri.lastPathSegment ?: "photo.jpg"
            val bytes = resolver.openInputStream(uri)?.use { it.readBytes() } ?: return null
            val mime = resolver.getType(uri) ?: "image/jpeg"
            MultipartBody.Part.createFormData("photo", name, bytes.toRequestBody(mime.toMediaTypeOrNull()))
        } catch (_: Exception) {
            null
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

    // ── Courses (students only) ────────────────────────────────────────────────────

    /** Course offers for the student's batch, with per-subject registration status. */
    suspend fun getCourseOffers(): AppResult<CourseOffersResponse> =
        call { api.getCourseOffers() }

    /**
     * Submits registration for ALL subjects of an offer at once.
     * The registration awaits departmental approval.
     */
    suspend fun registerAllCourses(offerId: Int): AppResult<SimpleResponse> =
        call { api.registerAllCourses(offerId = offerId) }

    // ── Admit cards (students only) ────────────────────────────────────────────────

    /** Active admit cards published for the student's dept + program. */
    suspend fun getAdmitCards(): AppResult<bd.ac.primeuniversity.studentportal.data.model.AdmitCardsResponse> =
        call { api.getAdmitCards() }

    /**
     * Downloads an admit card PDF into [target] and returns the file.
     * The server enforces the same eligibility rules as the web portal and
     * responds with a JSON error (surfaced here) when the download is blocked.
     */
    suspend fun downloadAdmitCard(cardId: Int, target: java.io.File): AppResult<java.io.File> =
        withContext(Dispatchers.IO) {
            try {
                val response = api.downloadAdmitCard(cardId)
                val body = response.body()
                when {
                    response.code() == 401 ->
                        AppResult.Error("Your session has expired. Please sign in again.", unauthorized = true)
                    response.isSuccessful && body != null -> {
                        target.parentFile?.mkdirs()
                        body.byteStream().use { input ->
                            target.outputStream().use { output -> input.copyTo(output) }
                        }
                        AppResult.Success(target)
                    }
                    else -> AppResult.Error(parseError(response))
                }
            } catch (e: IOException) {
                AppResult.Error("No internet connection. Please check your network.")
            } catch (e: Exception) {
                AppResult.Error(e.message ?: "Something went wrong. Please try again.")
            }
        }

    // ── Digital ID card (students only) ─────────────────────────────────────────────

    /**
     * The student's official ID card rendered server-side (front/back SVG)
     * from the same design the admin ID Card module prints.
     */
    suspend fun getIdCard(): AppResult<bd.ac.primeuniversity.studentportal.data.model.IdCardResponse> =
        call { api.getIdCard() }

    // ── Announcements (push notification history) ─────────────────────────────────────

    // ── IT Support tickets (students only) ──────────────────────────────────────────

    /** The signed-in student's IT support tickets (newest first). */
    suspend fun getSupportTickets(): AppResult<SupportTicketsResponse> =
        call { api.getSupportTickets() }

    /** Create a new IT support ticket. Priority/deadline follow the server-side SLA rules. */
    suspend fun createSupportTicket(
        title: String,
        description: String,
        category: String,
        attachments: List<Uri> = emptyList(),
    ): AppResult<SupportTicketCreateResponse> =
        call {
            api.createSupportTicket(
                title.asTextPart(),
                description.asTextPart(),
                category.asTextPart(),
                buildAttachmentParts(attachments),
            )
        }

    /** One ticket with its attachments and the public comment thread. */
    suspend fun getSupportTicketDetail(id: Int): AppResult<SupportTicketDetailResponse> =
        call { api.getSupportTicketDetail(id) }

    /** Post a comment (optionally with attachments) on the student's own ticket. */
    suspend fun addSupportTicketComment(
        ticketId: Int,
        comment: String,
        attachments: List<Uri> = emptyList(),
    ): AppResult<SupportTicketCommentResponse> =
        call {
            api.addSupportTicketComment(
                ticketId.toString().asTextPart(),
                comment.asTextPart(),
                buildAttachmentParts(attachments),
            )
        }

    private fun String.asTextPart(): RequestBody =
        toRequestBody("text/plain".toMediaTypeOrNull())

    /** Reads each content Uri and wraps it as a multipart "attachments[]" file part. */
    private fun buildAttachmentParts(uris: List<Uri>): List<MultipartBody.Part> {
        val resolver = appContext.contentResolver
        return uris.mapNotNull { uri ->
            try {
                val name = resolver.query(uri, null, null, null, null)?.use { c ->
                    val idx = c.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                    if (c.moveToFirst() && idx >= 0) c.getString(idx) else null
                } ?: uri.lastPathSegment ?: "attachment"
                val bytes = resolver.openInputStream(uri)?.use { it.readBytes() }
                    ?: return@mapNotNull null
                val mime = resolver.getType(uri) ?: "application/octet-stream"
                MultipartBody.Part.createFormData(
                    "attachments[]", name, bytes.toRequestBody(mime.toMediaTypeOrNull())
                )
            } catch (_: Exception) {
                null
            }
        }
    }

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

    /** Cancel one of the employee's own pending leave requests. */
    suspend fun cancelStaffLeave(id: Int): AppResult<SimpleResponse> =
        call { staffApi.cancelLeave(id = id) }

    /** Leave requests currently awaiting the signed-in approver's decision. */
    suspend fun getLeaveApprovals(): AppResult<LeaveApprovalsResponse> =
        call { staffApi.getLeaveApprovals() }

    /** Approve or reject a leave request that awaits the signed-in employee. */
    suspend fun actOnLeaveApproval(id: Int, approve: Boolean, note: String?): AppResult<SimpleResponse> =
        call {
            staffApi.actOnLeave(
                id = id,
                action = if (approve) "approve" else "reject",
                note = note?.takeIf { it.isNotBlank() },
            )
        }

    /** Change the signed-in user's account password (student or employee). */
    suspend fun changePassword(
        currentPassword: String,
        newPassword: String,
    ): AppResult<SimpleResponse> =
        call {
            if (isStaff) staffApi.changePassword(currentPassword, newPassword)
            else api.changePassword(currentPassword, newPassword)
        }

    // ── Faculty: student attendance ──────────────────────────────────────

    /** Offered subjects the signed-in faculty member is assigned to teach. */
    suspend fun getTeachingSubjects(): AppResult<TeachSubjectsResponse> =
        call { staffApi.getTeachingSubjects() }

    /** Roster + saved statuses of an assigned subject for one class date. */
    suspend fun getSubjectStudents(subjectId: Int, date: String): AppResult<SubjectStudentsResponse> =
        call { staffApi.getSubjectStudents(subjectId = subjectId, date = date) }

    /** Save (upsert) attendance for a subject and date. */
    suspend fun saveStudentAttendance(
        subjectId: Int,
        date: String,
        statuses: Map<Int, String>,
    ): AppResult<SaveStudentAttendanceResponse> =
        call { staffApi.saveStudentAttendance(subjectId, date, Gson().toJson(statuses)) }

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
        // Re-register when either the token or the installed app version changes
        // so the server always knows which version this device is running.
        val appVersion = BuildConfig.VERSION_NAME
        val registrationKey = "$fcmToken|$appVersion"
        if (registrationKey == storage.registeredFcmToken) return

        withContext(Dispatchers.IO) {
            try {
                val response = if (isStaff) {
                    staffApi.registerPushToken(fcmToken, storage.deviceId, appVersion = appVersion)
                } else {
                    api.registerPushToken(fcmToken, storage.deviceId, appVersion = appVersion)
                }
                if (response.isSuccessful && response.body()?.ok == true) {
                    storage.registeredFcmToken = registrationKey
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
