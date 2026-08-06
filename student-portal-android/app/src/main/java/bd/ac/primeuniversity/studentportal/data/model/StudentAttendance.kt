package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** An offered subject the faculty member is assigned to teach. */
data class TeachSubject(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("course_code") val courseCode: String? = null,
    @SerializedName("course_name") val courseName: String? = null,
    @SerializedName("credit") val credit: String? = null,
    @SerializedName("dept_id") val deptId: Int = 0,
    @SerializedName("dept_name") val deptName: String? = null,
    @SerializedName("program_id") val programId: Int = 0,
    @SerializedName("program_name") val programName: String? = null,
    @SerializedName("batch_id") val batchId: Int = 0,
    @SerializedName("batch_name") val batchName: String? = null,
    @SerializedName("semester") val semester: String? = null,
    @SerializedName("academic_intake") val academicIntake: String? = null,
    @SerializedName("section") val section: String? = null,
    @SerializedName("shift") val shift: String? = null,
    @SerializedName("student_count") val studentCount: Int = 0,
    @SerializedName("session_count") val sessionCount: Int = 0,
)

/** The faculty profile's department – used as the default Department filter. */
data class TeachFaculty(
    @SerializedName("dept_id") val deptId: Int = 0,
    @SerializedName("dept_name") val deptName: String? = null,
)

data class TeachSubjectsResponse(
    @SerializedName("faculty") val faculty: TeachFaculty? = null,
    @SerializedName("subjects") val subjects: List<TeachSubject> = emptyList(),
) : BaseResponse()

/** A student registered on an offered subject. */
data class AttStudent(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("student_id") val studentId: String? = null,
    @SerializedName("full_name") val fullName: String? = null,
    @SerializedName("section") val section: String? = null,
)

data class SubjectStudentsResponse(
    @SerializedName("date") val date: String? = null,
    @SerializedName("students") val students: List<AttStudent> = emptyList(),
    @SerializedName("statuses") val statuses: Map<String, String> = emptyMap(),
    @SerializedName("has_session") val hasSession: Boolean = false,
) : BaseResponse()

data class SaveStudentAttendanceResponse(
    @SerializedName("message") val message: String? = null,
    @SerializedName("saved") val saved: Int = 0,
) : BaseResponse()
