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
