package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** Response of admin/api/student/course-offers.php. */
data class CourseOffersResponse(
    @SerializedName("offers") val offers: List<CourseOffer> = emptyList(),
    @SerializedName("message") val message: String? = null,
    /** True when registration is blocked because dues exceed 1,000 BDT. */
    @SerializedName("dues_blocked") val duesBlocked: Boolean = false,
    @SerializedName("dues_amount") val duesAmount: Double? = null,
    @SerializedName("dues_message") val duesMessage: String? = null,
) : BaseResponse()

/** A course offer targeted at the student's batch (one per semester/intake). */
data class CourseOffer(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("semester") val semester: String? = null,
    @SerializedName("academic_intake") val academicIntake: String? = null,
    @SerializedName("registration_open") val registrationOpen: Boolean = false,
    @SerializedName("dept_name") val deptName: String? = null,
    @SerializedName("program_name") val programName: String? = null,
    @SerializedName("batch_name") val batchName: String? = null,
    @SerializedName("subjects") val subjects: List<OfferSubject> = emptyList(),
    @SerializedName("registered_count") val registeredCount: Int = 0,
    @SerializedName("pending_count") val pendingCount: Int = 0,
    @SerializedName("total_subjects") val totalSubjects: Int = 0,
)

/** A subject inside a course offer, with the student's registration status. */
data class OfferSubject(
    @SerializedName("offer_subject_id") val offerSubjectId: Int = 0,
    @SerializedName("course_code") val courseCode: String? = null,
    @SerializedName("course_name") val courseName: String? = null,
    @SerializedName("credit") val credit: String? = null,
    @SerializedName("registered") val registered: Boolean = false,
    /** "pending" while awaiting departmental approval, "approved" after; null when not registered. */
    @SerializedName("approval_status") val approvalStatus: String? = null,
    @SerializedName("teachers") val teachers: List<SubjectTeacher> = emptyList(),
)

data class SubjectTeacher(
    @SerializedName("name") val name: String? = null,
    @SerializedName("designation") val designation: String? = null,
)
