package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** User account returned by the student API. */
data class User(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("full_name") val fullName: String? = null,
    @SerializedName("username") val username: String? = null,
    @SerializedName("email") val email: String? = null,
)

/** Student profile returned by login / me endpoints. */
data class Student(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("student_id") val studentId: String? = null,
    @SerializedName("full_name") val fullName: String? = null,
    @SerializedName("photo_url") val photoUrl: String? = null,
    @SerializedName("phone") val phone: String? = null,
    @SerializedName("email") val email: String? = null,
    @SerializedName("status") val status: String? = null,
    @SerializedName("dept_name") val deptName: String? = null,
    @SerializedName("dept_code") val deptCode: String? = null,
    @SerializedName("program_name") val programName: String? = null,
    @SerializedName("program_type") val programType: String? = null,
    @SerializedName("batch_name") val batchName: String? = null,
) {
    /** Two-letter initials derived from the full name for the avatar. */
    val initials: String
        get() {
            val parts = (fullName ?: "").trim().split(Regex("\\s+")).filter { it.isNotEmpty() }
            return when {
                parts.isEmpty() -> "?"
                parts.size == 1 -> parts[0].take(1).uppercase()
                else -> (parts.first().take(1) + parts.last().take(1)).uppercase()
            }
        }
}

/** Dashboard summary stats from /auth/me.php. */
data class Stats(
    @SerializedName("notices_university") val noticesUniversity: Int = 0,
    @SerializedName("notices_department") val noticesDepartment: Int = 0,
    @SerializedName("outstanding_balance") val outstandingBalance: Double? = null,
)
