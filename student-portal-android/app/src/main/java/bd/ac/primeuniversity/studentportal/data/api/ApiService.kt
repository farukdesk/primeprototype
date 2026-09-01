package bd.ac.primeuniversity.studentportal.data.api

import bd.ac.primeuniversity.studentportal.data.model.AdmitCardsResponse
import bd.ac.primeuniversity.studentportal.data.model.AppNotificationsResponse
import bd.ac.primeuniversity.studentportal.data.model.CourseOffersResponse
import bd.ac.primeuniversity.studentportal.data.model.FinancesResponse
import bd.ac.primeuniversity.studentportal.data.model.IdCardResponse
import bd.ac.primeuniversity.studentportal.data.model.LoginResponse
import bd.ac.primeuniversity.studentportal.data.model.MeResponse
import bd.ac.primeuniversity.studentportal.data.model.NoticeDetailResponse
import bd.ac.primeuniversity.studentportal.data.model.NoticesResponse
import bd.ac.primeuniversity.studentportal.data.model.SimpleResponse
import bd.ac.primeuniversity.studentportal.data.model.SupportTicketCommentResponse
import bd.ac.primeuniversity.studentportal.data.model.SupportTicketCreateResponse
import bd.ac.primeuniversity.studentportal.data.model.SupportTicketDetailResponse
import bd.ac.primeuniversity.studentportal.data.model.SupportTicketsResponse
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Field
import retrofit2.http.FormUrlEncoded
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Query
import retrofit2.http.Streaming

/**
 * Retrofit definition of the Prime University student portal API.
 * Mirrors the PHP endpoints under admin/api/student/.
 */
interface ApiService {

    @FormUrlEncoded
    @POST("auth/login.php")
    suspend fun login(
        @Field("login") login: String,
        @Field("password") password: String,
        @Field("device_id") deviceId: String,
        @Field("device_name") deviceName: String,
    ): Response<LoginResponse>

    @POST("auth/logout.php")
    suspend fun logout(): Response<SimpleResponse>

    @GET("auth/me.php")
    suspend fun me(): Response<MeResponse>

    @FormUrlEncoded
    @POST("change-password.php")
    suspend fun changePassword(
        @Field("current_password") currentPassword: String,
        @Field("new_password") newPassword: String,
    ): Response<SimpleResponse>

    @GET("notices.php")
    suspend fun getNotices(
        @Query("type") type: String,
        @Query("page") page: Int,
        @Query("limit") limit: Int = 20,
    ): Response<NoticesResponse>

    @GET("notices.php")
    suspend fun getNoticeDetail(
        @Query("id") id: Int,
        @Query("type") type: String,
    ): Response<NoticeDetailResponse>

    @GET("finances.php")
    suspend fun getFinances(): Response<FinancesResponse>

    @GET("course-offers.php")
    suspend fun getCourseOffers(): Response<CourseOffersResponse>

    @GET("admit-cards.php")
    suspend fun getAdmitCards(): Response<AdmitCardsResponse>

    /** Streams the admit card PDF; save the body to a file and open it. */
    @Streaming
    @GET("admit-card-download.php")
    suspend fun downloadAdmitCard(@Query("card") cardId: Int): Response<ResponseBody>

    /** The student's generated ID card (front/back SVG), when one exists. */
    @GET("id-card.php")
    suspend fun getIdCard(): Response<IdCardResponse>

    /**
     * Registers the student for ALL subjects of a course offer at once.
     * The registration is created as pending and approved by the department.
     */
    @FormUrlEncoded
    @POST("course-register.php")
    suspend fun registerAllCourses(
        @Field("offer_id") offerId: Int,
        @Field("action") action: String = "register_all",
    ): Response<SimpleResponse>

    @GET("notifications.php")
    suspend fun getAppNotifications(
        @Query("page") page: Int = 1,
        @Query("limit") limit: Int = 50,
    ): Response<AppNotificationsResponse>

    @GET("support-tickets.php")
    suspend fun getSupportTickets(
        @Query("page") page: Int = 1,
        @Query("limit") limit: Int = 50,
    ): Response<SupportTicketsResponse>

    /** One ticket with its attachments and public comments. */
    @GET("support-ticket-detail.php")
    suspend fun getSupportTicketDetail(@Query("id") id: Int): Response<SupportTicketDetailResponse>

    /** Multipart so the student can attach files to the new ticket. */
    @Multipart
    @POST("support-ticket-create.php")
    suspend fun createSupportTicket(
        @Part("title") title: RequestBody,
        @Part("description") description: RequestBody,
        @Part("category") category: RequestBody,
        @Part attachments: List<MultipartBody.Part>,
    ): Response<SupportTicketCreateResponse>

    /** Adds a comment (optionally with attachments) to the student's own ticket. */
    @Multipart
    @POST("support-ticket-comment.php")
    suspend fun addSupportTicketComment(
        @Part("ticket_id") ticketId: RequestBody,
        @Part("comment") comment: RequestBody,
        @Part attachments: List<MultipartBody.Part>,
    ): Response<SupportTicketCommentResponse>

    @FormUrlEncoded
    @POST("push/register.php")
    suspend fun registerPushToken(
        @Field("fcm_token") fcmToken: String,
        @Field("device_id") deviceId: String,
        @Field("platform") platform: String = "android",
        @Field("app_version") appVersion: String = "",
    ): Response<SimpleResponse>

    /** Removes this device's push registration (called on logout). */
    @FormUrlEncoded
    @POST("push/unregister.php")
    suspend fun unregisterPushToken(
        @Field("fcm_token") fcmToken: String,
        @Field("device_id") deviceId: String,
    ): Response<SimpleResponse>

    /** Uploads / replaces the student's profile photo. */
    @Multipart
    @POST("profile-photo.php")
    suspend fun uploadProfilePhoto(
        @Part photo: MultipartBody.Part,
    ): Response<SimpleResponse>
}
