package bd.ac.primeuniversity.studentportal.data.model

import com.google.gson.annotations.SerializedName

/** Admin-panel user account (an employee) returned by the staff API. */
data class StaffUser(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("full_name") val fullName: String? = null,
    @SerializedName("username") val username: String? = null,
    @SerializedName("email") val email: String? = null,
    @SerializedName("group") val group: String? = null,
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

/** Employee profile details (staff_profiles). */
data class EmployeeInfo(
    @SerializedName("employee_type") val employeeType: String? = null,
    @SerializedName("employee_type_label") val employeeTypeLabel: String? = null,
    @SerializedName("employee_id") val employeeId: String? = null,
    @SerializedName("designation") val designation: String? = null,
    @SerializedName("department") val department: String? = null,
    @SerializedName("phone") val phone: String? = null,
    @SerializedName("blood_group") val bloodGroup: String? = null,
    @SerializedName("job_type") val jobType: String? = null,
    @SerializedName("joining_date") val joiningDate: String? = null,
    @SerializedName("employee_status") val employeeStatus: String? = null,
    @SerializedName("father_name") val fatherName: String? = null,
    @SerializedName("mother_name") val motherName: String? = null,
    @SerializedName("gender") val gender: String? = null,
    @SerializedName("religion") val religion: String? = null,
    @SerializedName("national_id") val nationalId: String? = null,
    @SerializedName("date_of_birth") val dateOfBirth: String? = null,
    @SerializedName("nationality") val nationality: String? = null,
    @SerializedName("birth_place") val birthPlace: String? = null,
) {
    /** Whether this employee is a Faculty (educational) member. */
    val isFaculty: Boolean get() = employeeType == "educational"
}

/** Extended academic profile returned only for Faculty employees. */
data class FacultyInfo(
    @SerializedName("designation") val designation: String? = null,
    @SerializedName("academic_department") val academicDepartment: String? = null,
    @SerializedName("official_email") val officialEmail: String? = null,
    @SerializedName("office") val office: String? = null,
    @SerializedName("office_hours") val officeHours: String? = null,
    @SerializedName("qualification") val qualification: String? = null,
    @SerializedName("research_interest") val researchInterest: String? = null,
)

/** Yearly Casual / Sick leave balance. */
data class LeaveBalance(
    @SerializedName("year") val year: Int = 0,
    @SerializedName("casual_total") val casualTotal: Double = 0.0,
    @SerializedName("casual_used") val casualUsed: Double = 0.0,
    @SerializedName("casual_remaining") val casualRemaining: Double = 0.0,
    @SerializedName("sick_total") val sickTotal: Double = 0.0,
    @SerializedName("sick_used") val sickUsed: Double = 0.0,
    @SerializedName("sick_remaining") val sickRemaining: Double = 0.0,
)

/** Today's clock in/out snapshot. */
data class TodayAttendance(
    @SerializedName("date") val date: String? = null,
    @SerializedName("in_time") val inTime: String? = null,
    @SerializedName("out_time") val outTime: String? = null,
)

/** Staff dashboard stats. */
data class StaffStats(
    @SerializedName("notices_university") val noticesUniversity: Int = 0,
    @SerializedName("pending_leaves") val pendingLeaves: Int = 0,
)

data class StaffLoginResponse(
    @SerializedName("token") val token: String? = null,
    @SerializedName("expires_at") val expiresAt: String? = null,
    @SerializedName("user") val user: StaffUser? = null,
) : BaseResponse()

data class StaffMeResponse(
    @SerializedName("user") val user: StaffUser? = null,
    @SerializedName("employee") val employee: EmployeeInfo? = null,
    @SerializedName("faculty") val faculty: FacultyInfo? = null,
    @SerializedName("leave_balance") val leaveBalance: LeaveBalance? = null,
    @SerializedName("today") val today: TodayAttendance? = null,
    @SerializedName("stats") val stats: StaffStats? = null,
) : BaseResponse()

/** One day on the attendance statement. */
data class AttendanceDay(
    @SerializedName("date") val date: String = "",
    @SerializedName("weekday") val weekday: String = "",
    @SerializedName("in_time") val inTime: String? = null,
    @SerializedName("out_time") val outTime: String? = null,
    @SerializedName("worked") val worked: String? = null,
    @SerializedName("status") val status: String = "",
    @SerializedName("status_label") val statusLabel: String = "",
    @SerializedName("holiday") val holiday: String? = null,
)

data class AttendanceSummary(
    @SerializedName("working_days") val workingDays: Int = 0,
    @SerializedName("present") val present: Int = 0,
    @SerializedName("late_in") val lateIn: Int = 0,
    @SerializedName("early_out") val earlyOut: Int = 0,
    @SerializedName("late_and_early") val lateAndEarly: Int = 0,
    @SerializedName("incomplete") val incomplete: Int = 0,
    @SerializedName("short_hours") val shortHours: Int = 0,
    @SerializedName("absent") val absent: Int = 0,
    @SerializedName("leave") val leave: Int = 0,
    @SerializedName("holiday") val holiday: Int = 0,
    @SerializedName("weekly_off") val weeklyOff: Int = 0,
    @SerializedName("upcoming") val upcoming: Int = 0,
    @SerializedName("late_early_days") val lateEarlyDays: Int = 0,
    @SerializedName("late_penalty_days") val latePenaltyDays: Int = 0,
)

data class StaffAttendanceResponse(
    @SerializedName("month") val month: String? = null,
    @SerializedName("from") val from: String? = null,
    @SerializedName("to") val to: String? = null,
    @SerializedName("label") val label: String? = null,
    @SerializedName("summary") val summary: AttendanceSummary? = null,
    @SerializedName("days") val days: List<AttendanceDay> = emptyList(),
) : BaseResponse()

/** One step of a leave request's approval flow. */
data class LeaveApproval(
    @SerializedName("step_order") val stepOrder: Int = 0,
    @SerializedName("label") val label: String? = null,
    @SerializedName("group_name") val groupName: String? = null,
    @SerializedName("status") val status: String = "pending",
    @SerializedName("approver_name") val approverName: String? = null,
    @SerializedName("acted_at") val actedAt: String? = null,
    @SerializedName("note") val note: String? = null,
)

/** A leave request submitted by the employee. */
data class LeaveRequest(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("category") val category: String = "",
    @SerializedName("category_label") val categoryLabel: String? = null,
    @SerializedName("pay_type") val payType: String? = null,
    @SerializedName("start_date") val startDate: String = "",
    @SerializedName("end_date") val endDate: String = "",
    @SerializedName("start_time") val startTime: String? = null,
    @SerializedName("end_time") val endTime: String? = null,
    @SerializedName("days") val days: Double = 0.0,
    @SerializedName("reason") val reason: String? = null,
    @SerializedName("status") val status: String = "pending",
    @SerializedName("current_step") val currentStep: Int = 1,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("approvals") val approvals: List<LeaveApproval> = emptyList(),
)

data class StaffLeavesResponse(
    @SerializedName("balance") val balance: LeaveBalance? = null,
    @SerializedName("categories") val categories: List<String> = emptyList(),
    @SerializedName("requests") val requests: List<LeaveRequest> = emptyList(),
) : BaseResponse()

data class LeaveApplyResponse(
    @SerializedName("message") val message: String? = null,
    @SerializedName("request_id") val requestId: Int? = null,
) : BaseResponse()
