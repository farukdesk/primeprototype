package bd.ac.primeuniversity.studentportal.ui.dashboard

import androidx.annotation.DrawableRes
import androidx.annotation.StringRes
import bd.ac.primeuniversity.studentportal.R

/**
 * Every action reachable from the dashboard launcher. Each feature carries its
 * own label, icon and an accent colour used to tint the icon chip.
 */
enum class Feature(
    @StringRes val titleRes: Int,
    @DrawableRes val iconRes: Int,
    @androidx.annotation.ColorRes val colorRes: Int,
    @StringRes val subtitleRes: Int = 0,
    /** Optional solid pastel background for the icon chip; 0 falls back to a translucent accent. */
    @androidx.annotation.ColorRes val containerRes: Int = 0,
) {
    // Academic
    REGISTERED_COURSES(R.string.feat_registered_courses, R.drawable.ic_school, R.color.cat_academic),
    COURSE_REGISTRATION(R.string.feat_course_registration, R.drawable.ic_registration, R.color.cat_academic),
    COURSE_MODULES(R.string.feat_course_modules, R.drawable.ic_modules, R.color.cat_academic),

    // Examination
    RESULTS(R.string.feat_results, R.drawable.ic_results, R.color.cat_exam, R.string.feat_results_desc),
    EXAM_SCHEDULE(R.string.feat_exam_schedule, R.drawable.ic_calendar, R.color.cat_exam),
    SEAT_PLAN(R.string.feat_seat_plan, R.drawable.ic_seat, R.color.cat_exam),
    ADMIT_CARD(R.string.feat_admit_card, R.drawable.ic_receipt, R.color.cat_exam),
    TRANSCRIPT(R.string.feat_transcript, R.drawable.ic_description, R.color.cat_exam),

    // Campus
    NOTICES(R.string.feat_notices, R.drawable.ic_notifications, R.color.cat_campus),
    ANNOUNCEMENTS(R.string.feat_announcements, R.drawable.ic_notifications, R.color.icon_announcements, R.string.feat_announcements_desc, R.color.tint_announcements),
    ACADEMIC_CALENDAR(R.string.feat_academic_calendar, R.drawable.ic_calendar, R.color.cat_campus),
    EVENTS(R.string.feat_events, R.drawable.ic_event, R.color.pink),
    HOLIDAY_LIST(R.string.feat_holiday_list, R.drawable.ic_today, R.color.cat_campus),
    CLASS_SCHEDULE(R.string.feat_class_schedule, R.drawable.ic_schedule, R.color.cat_campus),
    OVERALL_ATTENDANCE(R.string.feat_overall_attendance, R.drawable.ic_check_circle, R.color.teal),
    DAILY_ATTENDANCE(R.string.feat_daily_attendance, R.drawable.ic_today, R.color.teal),

    // Profile
    STUDENT_PROFILE(R.string.feat_student_profile, R.drawable.ic_person, R.color.cat_profile),
    ID_CARD(R.string.feat_id_card, R.drawable.ic_qr_code, R.color.cat_profile),
    EMERGENCY_CONTACT(R.string.feat_emergency_contact, R.drawable.ic_contacts, R.color.cat_profile),

    // Settings
    PASSWORD_CHANGE(R.string.feat_password_change, R.drawable.ic_lock, R.color.cat_settings),
    SETTINGS(R.string.feat_settings, R.drawable.ic_settings, R.color.cat_settings),
    THEME(R.string.feat_theme, R.drawable.ic_dark_mode, R.color.purple),

    // Finances – one entry; the Finances tab shows dues, paid totals and history.
    MY_FINANCES(R.string.feat_my_finances, R.drawable.ic_wallet, R.color.cat_finance, R.string.feat_my_finances_desc),

    // Staff / Employee (Administrative + Faculty)
    MY_ATTENDANCE(R.string.feat_my_attendance, R.drawable.ic_check_circle, R.color.icon_attendance, R.string.feat_my_attendance_desc, R.color.tint_attendance),
    LEAVE_MANAGEMENT(R.string.feat_leave_management, R.drawable.ic_event, R.color.icon_leave, R.string.feat_leave_management_desc, R.color.tint_leave),
    LEAVE_APPROVALS(R.string.feat_leave_approvals, R.drawable.ic_check_circle, R.color.icon_approvals, R.string.feat_leave_approvals_desc, R.color.tint_approvals),
    STAFF_NOTICES(R.string.feat_notices, R.drawable.ic_notifications, R.color.icon_notices, containerRes = R.color.tint_notices),
    STAFF_PROFILE(R.string.feat_my_profile, R.drawable.ic_person, R.color.icon_profile, containerRes = R.color.tint_profile),
}

/** A single row in the dashboard list: either a section header or a menu item. */
sealed interface MenuRow {
    data class Header(@StringRes val titleRes: Int) : MenuRow
    data class Item(val feature: Feature) : MenuRow
}

/** Builds the full ordered launcher menu grouped into sections. */
fun buildDashboardMenu(): List<MenuRow> = buildList {
    add(MenuRow.Header(R.string.section_academic))
    add(MenuRow.Item(Feature.REGISTERED_COURSES))
    add(MenuRow.Item(Feature.COURSE_REGISTRATION))
    add(MenuRow.Item(Feature.COURSE_MODULES))

    add(MenuRow.Header(R.string.section_examination))
    add(MenuRow.Item(Feature.RESULTS))
    add(MenuRow.Item(Feature.EXAM_SCHEDULE))
    add(MenuRow.Item(Feature.SEAT_PLAN))
    add(MenuRow.Item(Feature.ADMIT_CARD))
    add(MenuRow.Item(Feature.TRANSCRIPT))

    add(MenuRow.Header(R.string.section_campus))
    add(MenuRow.Item(Feature.NOTICES))
    add(MenuRow.Item(Feature.ANNOUNCEMENTS))
    add(MenuRow.Item(Feature.ACADEMIC_CALENDAR))
    add(MenuRow.Item(Feature.EVENTS))
    add(MenuRow.Item(Feature.HOLIDAY_LIST))
    add(MenuRow.Item(Feature.CLASS_SCHEDULE))
    add(MenuRow.Item(Feature.OVERALL_ATTENDANCE))
    add(MenuRow.Item(Feature.DAILY_ATTENDANCE))

    add(MenuRow.Header(R.string.section_profile))
    add(MenuRow.Item(Feature.STUDENT_PROFILE))
    add(MenuRow.Item(Feature.ID_CARD))
    add(MenuRow.Item(Feature.EMERGENCY_CONTACT))

    add(MenuRow.Header(R.string.section_finances))
    add(MenuRow.Item(Feature.MY_FINANCES))

    add(MenuRow.Header(R.string.section_settings))
    add(MenuRow.Item(Feature.PASSWORD_CHANGE))
    add(MenuRow.Item(Feature.SETTINGS))
    add(MenuRow.Item(Feature.THEME))
}

/**
 * The staff (Administrative / Faculty employee) dashboard menu.
 *
 * @param canApproveLeaves whether the employee's user group is part of an
 * active leave approval flow – adds the Leave Approvals entry.
 */
fun buildStaffDashboardMenu(canApproveLeaves: Boolean = false): List<MenuRow> = buildList {
    add(MenuRow.Header(R.string.section_staff_workspace))
    add(MenuRow.Item(Feature.MY_ATTENDANCE))
    add(MenuRow.Item(Feature.LEAVE_MANAGEMENT))
    if (canApproveLeaves) add(MenuRow.Item(Feature.LEAVE_APPROVALS))
    add(MenuRow.Item(Feature.STAFF_NOTICES))
    add(MenuRow.Item(Feature.ANNOUNCEMENTS))
    add(MenuRow.Item(Feature.STAFF_PROFILE))

    add(MenuRow.Header(R.string.section_settings))
    add(MenuRow.Item(Feature.SETTINGS))
    add(MenuRow.Item(Feature.THEME))
}
