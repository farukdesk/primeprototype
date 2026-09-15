package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** One course row of a published semester result. */
data class ResultEntry(
    @SerializedName("course_code") val courseCode: String? = null,
    @SerializedName("course_title") val courseTitle: String? = null,
    @SerializedName("credit") val credit: Double? = null,
    @SerializedName("letter_grade") val letterGrade: String? = null,
    @SerializedName("grade_point") val gradePoint: Double? = null,
    @SerializedName("remarks") val remarks: String? = null,
) {
    /** "Incom" grades carry no grade point. */
    val isIncomplete: Boolean
        get() = letterGrade?.trim().equals("INCOM", ignoreCase = true)
}

/** One published semester (term) with the student's course grades, GPA and running CGPA. */
data class SemesterResult(
    @SerializedName("id") val id: Int = 0,
    /** Term label, e.g. "Spring 2026". */
    @SerializedName("title") val title: String = "",
    /** Exam label shown under the title, e.g. "Final Examination 2026" (may be blank). */
    @SerializedName("semester") val semester: String? = null,
    @SerializedName("exam") val exam: String? = null,
    @SerializedName("published_at") val publishedAt: String? = null,
    @SerializedName("course_count") val courseCount: Int = 0,
    @SerializedName("credits") val credits: Double? = null,
    /** Credit-weighted GPA of completed courses; null when withheld ([gpaIncomplete]) or not computable. */
    @SerializedName("gpa") val gpa: Double? = null,
    /** True when the semester contains an F or Incom, so the GPA is withheld. */
    @SerializedName("gpa_incomplete") val gpaIncomplete: Boolean = false,
    /** Short reason when the GPA is withheld, e.g. "F grade", "Incom", "F grade / Incom". */
    @SerializedName("gpa_status") val gpaStatus: String? = null,
    /** Running CGPA up to and including this semester. */
    @SerializedName("cgpa") val cgpa: Double? = null,
    @SerializedName("entries") val entries: List<ResultEntry> = emptyList(),
)

data class ResultsResponse(
    @SerializedName("student_id") val studentId: String? = null,
    @SerializedName("student_name") val studentName: String? = null,
    /** Overall CGPA (the published Final CGPA when [cgpaIsFinal]). */
    @SerializedName("cgpa") val cgpa: Double? = null,
    @SerializedName("cgpa_is_final") val cgpaIsFinal: Boolean = false,
    @SerializedName("credits_counted") val creditsCounted: Double? = null,
    @SerializedName("results") val results: List<SemesterResult> = emptyList(),
) : BaseResponse()
