package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** A university or department notice. */
data class Notice(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("type") val type: String = "university",
    @SerializedName("title") val title: String = "",
    @SerializedName("content") val content: String? = null,
    @SerializedName("content_type") val contentType: String = "text",
    @SerializedName("date") val date: String = "",
    @SerializedName("dept_name") val deptName: String? = null,
    @SerializedName("attachment_url") val attachmentUrl: String? = null,
    @SerializedName("attachment_name") val attachmentName: String? = null,
    @SerializedName("attachment_size_kb") val attachmentSizeKb: Int? = null,
) {
    val isDepartment: Boolean get() = type == "department"
    val hasAttachment: Boolean get() = !attachmentUrl.isNullOrEmpty()

    /** Content with HTML tags stripped, for list previews. */
    val plainContent: String
        get() = content?.replace(Regex("<[^>]*>"), "")?.trim().orEmpty()
}
