package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** An announcement pushed from the admin panel's App Notification module. */
data class AppNotification(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("title") val title: String = "",
    @SerializedName("body") val body: String = "",
    @SerializedName("url") val url: String? = null,
    @SerializedName("date") val date: String = "",
) {
    /** Whether the announcement carries an external link to open. */
    val hasLink: Boolean
        get() = url != null && (url.startsWith("http://") || url.startsWith("https://"))
}
