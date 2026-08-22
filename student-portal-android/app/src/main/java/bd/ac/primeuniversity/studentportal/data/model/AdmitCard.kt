package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** One admit card published for the student's dept + program. */
data class AdmitCard(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("exam_name") val examName: String = "",
    @SerializedName("semester") val semester: String? = null,
    @SerializedName("batch") val batch: String? = null,
    @SerializedName("dept_name") val deptName: String? = null,
    @SerializedName("program_name") val programName: String? = null,
    /** Whether the student may download the PDF (dues cleared / override). */
    @SerializedName("allowed") val allowed: Boolean = true,
    /** Human-readable reason when the download is blocked. */
    @SerializedName("reason") val reason: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class AdmitCardsResponse(
    @SerializedName("admit_cards") val admitCards: List<AdmitCard> = emptyList(),
) : BaseResponse()
