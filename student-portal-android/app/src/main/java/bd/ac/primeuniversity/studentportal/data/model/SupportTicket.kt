package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** An IT support ticket created by the signed-in student. */
data class SupportTicket(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("ticket_number") val ticketNumber: String = "",
    @SerializedName("title") val title: String = "",
    @SerializedName("description") val description: String = "",
    @SerializedName("category") val category: String = "",
    @SerializedName("priority") val priority: String = "",
    @SerializedName("status") val status: String = "",
    @SerializedName("deadline") val deadline: String? = null,
    @SerializedName("date") val date: String = "",
)

/** A file attached to a ticket or to one of its comments. */
data class TicketAttachment(
    @SerializedName("name") val name: String = "",
    @SerializedName("url") val url: String = "",
    @SerializedName("size") val size: Long = 0,
)

/** A public reply on a ticket (from the student or the IT team). */
data class TicketComment(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("author") val author: String = "",
    @SerializedName("is_own") val isOwn: Boolean = false,
    @SerializedName("comment") val comment: String = "",
    @SerializedName("date") val date: String = "",
    @SerializedName("attachments") val attachments: List<TicketAttachment> = emptyList(),
)

data class SupportTicketsResponse(
    @SerializedName("tickets") val tickets: List<SupportTicket> = emptyList(),
    @SerializedName("total") val total: Int = 0,
    @SerializedName("page") val page: Int = 1,
    @SerializedName("per_page") val perPage: Int = 50,
) : BaseResponse()

data class SupportTicketCreateResponse(
    @SerializedName("message") val message: String? = null,
    @SerializedName("ticket") val ticket: SupportTicket? = null,
) : BaseResponse()

data class SupportTicketDetailResponse(
    @SerializedName("ticket") val ticket: SupportTicket? = null,
    @SerializedName("attachments") val attachments: List<TicketAttachment> = emptyList(),
    @SerializedName("comments") val comments: List<TicketComment> = emptyList(),
) : BaseResponse()

data class SupportTicketCommentResponse(
    @SerializedName("message") val message: String? = null,
    @SerializedName("comment") val comment: TicketComment? = null,
) : BaseResponse()
