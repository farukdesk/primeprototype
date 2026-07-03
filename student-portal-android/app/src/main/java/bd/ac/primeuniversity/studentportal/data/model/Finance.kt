package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** Aggregate fee summary for the finances screen. */
data class FinanceSummary(
    @SerializedName("total_due") val totalDue: Double = 0.0,
    @SerializedName("total_paid") val totalPaid: Double = 0.0,
    @SerializedName("outstanding") val outstanding: Double = 0.0,
    @SerializedName("semesters") val semesters: List<SemesterSummary> = emptyList(),
)

data class SemesterSummary(
    @SerializedName("label") val label: String = "",
    @SerializedName("total_due") val totalDue: Double = 0.0,
    @SerializedName("total_paid") val totalPaid: Double = 0.0,
    @SerializedName("outstanding") val outstanding: Double = 0.0,
)

data class Payment(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("voucher_number") val voucherNumber: String? = null,
    @SerializedName("date") val date: String? = null,
    @SerializedName("fee_type") val feeType: String? = null,
    @SerializedName("semester") val semester: Int? = null,
    @SerializedName("month_label") val monthLabel: String? = null,
    @SerializedName("amount") val amount: Double = 0.0,
    @SerializedName("method") val method: String = "Cash",
    @SerializedName("status") val status: String = "paid",
)
