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
) {
    // Academic
    REGISTERED_COURSES(R.string.feat_registered_courses, R.drawable.ic_school, R.color.primary),
    COURSE_REGISTRATION(R.string.feat_course_registration, R.drawable.ic_registration, R.color.primary),
    COURSE_MODULES(R.string.feat_course_modules, R.drawable.ic_modules, R.color.primary),

    // Examination
    RESULTS(R.string.feat_results, R.drawable.ic_results, R.color.info, R.string.feat_results_desc),
    EXAM_SCHEDULE(R.string.feat_exam_schedule, R.drawable.ic_calendar, R.color.info),
    SEAT_PLAN(R.string.feat_seat_plan, R.drawable.ic_seat, R.color.info),
    ADMIT_CARD(R.string.feat_admit_card, R.drawable.ic_receipt, R.color.info),
    TRANSCRIPT(R.string.feat_transcript, R.drawable.ic_description, R.color.info),

    // Campus
    NOTICES(R.string.feat_notices, R.drawable.ic_notifications, R.color.warning),
    ACADEMIC_CALENDAR(R.string.feat_academic_calendar, R.drawable.ic_calendar, R.color.warning),
    EVENTS(R.string.feat_events, R.drawable.ic_event, R.color.warning),
    HOLIDAY_LIST(R.string.feat_holiday_list, R.drawable.ic_today, R.color.warning),
    CLASS_SCHEDULE(R.string.feat_class_schedule, R.drawable.ic_schedule, R.color.warning),
    OVERALL_ATTENDANCE(R.string.feat_overall_attendance, R.drawable.ic_check_circle, R.color.warning),
    DAILY_ATTENDANCE(R.string.feat_daily_attendance, R.drawable.ic_today, R.color.warning),

    // Profile
    STUDENT_PROFILE(R.string.feat_student_profile, R.drawable.ic_person, R.color.success),
    ID_CARD(R.string.feat_id_card, R.drawable.ic_qr_code, R.color.success),
    EMERGENCY_CONTACT(R.string.feat_emergency_contact, R.drawable.ic_contacts, R.color.success),

    // Settings
    PASSWORD_CHANGE(R.string.feat_password_change, R.drawable.ic_lock, R.color.primary),
    SETTINGS(R.string.feat_settings, R.drawable.ic_settings, R.color.primary),
    THEME(R.string.feat_theme, R.drawable.ic_dark_mode, R.color.primary),

    // Finances
    DUE_TODAY(R.string.feat_due_today, R.drawable.ic_wallet, R.color.error),
    TOTAL_PAID(R.string.feat_total_paid, R.drawable.ic_paid, R.color.success),
    TRANSACTION_HISTORY(R.string.feat_transaction_history, R.drawable.ic_receipt, R.color.info),
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
    add(MenuRow.Item(Feature.DUE_TODAY))
    add(MenuRow.Item(Feature.TOTAL_PAID))
    add(MenuRow.Item(Feature.TRANSACTION_HISTORY))

    add(MenuRow.Header(R.string.section_settings))
    add(MenuRow.Item(Feature.PASSWORD_CHANGE))
    add(MenuRow.Item(Feature.SETTINGS))
    add(MenuRow.Item(Feature.THEME))
}
