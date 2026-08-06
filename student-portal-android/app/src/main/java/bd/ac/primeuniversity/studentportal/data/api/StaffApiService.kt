package bd.ac.primeuniversity.studentportal.data.api

import bd.ac.primeuniversity.studentportal.data.model.AppNotificationsResponse
import bd.ac.primeuniversity.studentportal.data.model.AppVersionResponse
import bd.ac.primeuniversity.studentportal.data.model.LeaveApplyResponse
import bd.ac.primeuniversity.studentportal.data.model.LeaveApprovalsResponse
import bd.ac.primeuniversity.studentportal.data.model.NoticeDetailResponse
import bd.ac.primeuniversity.studentportal.data.model.NoticesResponse
import bd.ac.primeuniversity.studentportal.data.model.SimpleResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffAttendanceResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffLeavesResponse
import bd.ac.primeuniversity.studentportal.data.model.SaveStudentAttendanceResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffLoginResponse
import bd.ac.primeuniversity.studentportal.data.model.StaffMeResponse
import bd.ac.primeuniversity.studentportal.data.model.SubjectStudentsResponse
import bd.ac.primeuniversity.studentportal.data.model.TeachSubjectsResponse
import retrofit2.Response
import retrofit2.http.Field
import retrofit2.http.FormUrlEncoded
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Query

/**
 * Retrofit definition of the staff/employee API rooted at admin/api/.
 * Mirrors the PHP endpoints under admin/api/auth, admin/api/staff and
 * admin/api/push.
 */
interface StaffApiService {

    @FormUrlEncoded
    @POST("auth/login.php")
    suspend fun login(
        @Field("login") login: String,
        @Field("password") password: String,
        @Field("device_id") deviceId: String,
        @Field("device_name") deviceName: String,
    ): Response<StaffLoginResponse>

    @POST("auth/logout.php")
    suspend fun logout(): Response<SimpleResponse>

    @GET("staff/me.php")
    suspend fun me(): Response<StaffMeResponse>

    @GET("staff/attendance.php")
    suspend fun getAttendance(@Query("month") month: String): Response<StaffAttendanceResponse>

    @GET("staff/leaves.php")
    suspend fun getLeaves(): Response<StaffLeavesResponse>

    @FormUrlEncoded
    @POST("staff/leaves.php")
    suspend fun applyLeave(
        @Field("category") category: String,
        @Field("start_date") startDate: String,
        @Field("end_date") endDate: String,
        @Field("reason") reason: String,
        @Field("pay_type") payType: String?,
        @Field("start_time") startTime: String?,
        @Field("end_time") endTime: String?,
    ): Response<LeaveApplyResponse>

    @FormUrlEncoded
    @POST("staff/leaves.php")
    suspend fun cancelLeave(
        @Field("id") id: Int,
        @Field("action") action: String = "cancel",
    ): Response<SimpleResponse>

    // Leave requests currently awaiting the signed-in approver's decision.
    @GET("staff/leave-approvals.php")
    suspend fun getLeaveApprovals(): Response<LeaveApprovalsResponse>

    @FormUrlEncoded
    @POST("staff/leave-approvals.php")
    suspend fun actOnLeave(
        @Field("id") id: Int,
        @Field("action") action: String,
        @Field("note") note: String?,
    ): Response<SimpleResponse>

    @GET("staff/notices.php")
    suspend fun getNotices(
        @Query("type") type: String,
        @Query("page") page: Int,
        @Query("limit") limit: Int = 20,
    ): Response<NoticesResponse>

    @GET("staff/notices.php")
    suspend fun getNoticeDetail(
        @Query("id") id: Int,
        @Query("type") type: String,
    ): Response<NoticeDetailResponse>

    @GET("staff/notifications.php")
    suspend fun getAppNotifications(
        @Query("page") page: Int = 1,
        @Query("limit") limit: Int = 50,
    ): Response<AppNotificationsResponse>

    @FormUrlEncoded
    @POST("staff/change-password.php")
    suspend fun changePassword(
        @Field("current_password") currentPassword: String,
        @Field("new_password") newPassword: String,
    ): Response<SimpleResponse>

    // ── Faculty: student attendance ──

    @GET("staff/student-attendance.php")
    suspend fun getTeachingSubjects(
        @Query("action") action: String = "subjects",
    ): Response<TeachSubjectsResponse>

    @GET("staff/student-attendance.php")
    suspend fun getSubjectStudents(
        @Query("action") action: String = "students",
        @Query("subject_id") subjectId: Int,
        @Query("date") date: String,
    ): Response<SubjectStudentsResponse>

    @FormUrlEncoded
    @POST("staff/student-attendance.php")
    suspend fun saveStudentAttendance(
        @Field("subject_id") subjectId: Int,
        @Field("class_date") classDate: String,
        @Field("statuses") statusesJson: String,
    ): Response<SaveStudentAttendanceResponse>

    // Public endpoint at admin/api/app-version.php (no auth required).
    @GET("app-version.php")
    suspend fun getAppVersion(): Response<AppVersionResponse>

    @FormUrlEncoded
    @POST("push/register.php")
    suspend fun registerPushToken(
        @Field("fcm_token") fcmToken: String,
        @Field("device_id") deviceId: String,
        @Field("platform") platform: String = "android",
        @Field("app_version") appVersion: String = "",
    ): Response<SimpleResponse>
}
