package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** Envelope fields shared by every API response. */
open class BaseResponse(
    @SerializedName("ok") val ok: Boolean = false,
    @SerializedName("error") val error: String? = null,
)

data class LoginResponse(
    @SerializedName("token") val token: String? = null,
    @SerializedName("expires_at") val expiresAt: String? = null,
    @SerializedName("user") val user: User? = null,
    @SerializedName("student") val student: Student? = null,
) : BaseResponse()

data class MeResponse(
    @SerializedName("user") val user: User? = null,
    @SerializedName("student") val student: Student? = null,
    @SerializedName("stats") val stats: Stats? = null,
) : BaseResponse()

data class NoticesResponse(
    @SerializedName("type") val type: String = "university",
    @SerializedName("dept_name") val deptName: String? = null,
    @SerializedName("notices") val notices: List<Notice> = emptyList(),
    @SerializedName("total") val total: Int = 0,
    @SerializedName("page") val page: Int = 1,
    @SerializedName("per_page") val perPage: Int = 20,
) : BaseResponse()

data class NoticeDetailResponse(
    @SerializedName("notice") val notice: Notice? = null,
) : BaseResponse()

data class AppNotificationsResponse(
    @SerializedName("notifications") val notifications: List<AppNotification> = emptyList(),
    @SerializedName("total") val total: Int = 0,
    @SerializedName("page") val page: Int = 1,
    @SerializedName("per_page") val perPage: Int = 50,
) : BaseResponse()

data class AppVersionResponse(
    @SerializedName("version_code") val versionCode: Int = 0,
    @SerializedName("version_name") val versionName: String = "",
    @SerializedName("apk_url") val apkUrl: String = "",
    @SerializedName("notes") val notes: String = "",
    @SerializedName("force") val force: Boolean = false,
) : BaseResponse()

data class FinancesResponse(
    @SerializedName("student") val student: Student? = null,
    @SerializedName("summary") val summary: FinanceSummary? = null,
    @SerializedName("schedule") val schedule: List<ScheduleSection> = emptyList(),
    @SerializedName("payments") val payments: List<Payment> = emptyList(),
    @SerializedName("message") val message: String? = null,
) : BaseResponse()

data class SimpleResponse(
    @SerializedName("message") val message: String? = null,
) : BaseResponse()
