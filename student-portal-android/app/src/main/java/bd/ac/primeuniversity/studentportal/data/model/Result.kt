package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** One course row of a published result set. */
data class ResultEntry(
    @SerializedName("course_code") val courseCode: String? = null,
    @SerializedName("course_title") val courseTitle: String? = null,
    @SerializedName("credit") val credit: Double? = null,
    @SerializedName("letter_grade") val letterGrade: String? = null,
    @SerializedName("grade_point") val gradePoint: Double? = null,
) {
    /** "INCOM" grades are shown as "Incom" and carry no grade point. */
    val isIncomplete: Boolean
        get() = letterGrade?.trim().equals("INCOM", ignoreCase = true)
}

/** A published result set (one exam / semester) with the student's course grades. */
data class SemesterResult(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("title") val title: String = "",
    @SerializedName("semester") val semester: String? = null,
    @SerializedName("published_at") val publishedAt: String? = null,
    @SerializedName("course_count") val courseCount: Int = 0,
    /** Credit-weighted GPA; null when not computable or when [gpaIncomplete]. */
    @SerializedName("gpa") val gpa: Double? = null,
    /** True when any course is F / INCOM, so the GPA is reported as "Incom". */
    @SerializedName("gpa_incomplete") val gpaIncomplete: Boolean = false,
    @SerializedName("entries") val entries: List<ResultEntry> = emptyList(),
)

data class ResultsResponse(
    @SerializedName("student_id") val studentId: String? = null,
    @SerializedName("student_name") val studentName: String? = null,
    @SerializedName("results") val results: List<SemesterResult> = emptyList(),
) : BaseResponse()
