<?php
/**
 * Shared helpers for the Staff Attendance module.
 *
 * Attendance is stored in att_records (one row per staff per day) holding the
 * in/out times. The office schedule that decides whether an entry is "late in"
 * or "early out" comes from:
 *   1. a per-staff override (att_staff_schedule), when present and active; else
 *   2. the global settings (att_settings): office start/close time and the
 *      in-time / out-time grace buffers.
 *
 * A day is only counted as Absent when it is a working day (not a holiday and
 * not a weekly-off day), the date is not in the future, and the staff member
 * has no in-time and no approved leave covering that date. Future dates are
 * reported as "upcoming" instead of "absent".
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Defaults (used before the settings migration is applied / when unset) ────
const ATT_DEFAULT_START      = '09:00';
const ATT_DEFAULT_CLOSE      = '17:00';
const ATT_DEFAULT_IN_BUFFER  = 15;
const ATT_DEFAULT_OUT_BUFFER = 15;
const ATT_DEFAULT_WEEKLY_OFF = '5'; // Friday (PHP date('N'): 1=Mon … 7=Sun)

// ── Attendance policy effective 1 June 2026 ─────────────────────────────────
// From this date: employee-type based clock-in buffers (no clock-out buffer),
// faculty flexible Fridays (strict 8 hours), early-bird 8:30→16:30 flexibility
// and the "4 late/early days = 1 absent" salary-deduction rule.
const ATT_POLICY_EFFECTIVE          = '2026-06-01';
const ATT_POLICY_ADMIN_IN_BUFFER    = 15;      // administrative clock-in grace (min)
const ATT_POLICY_FACULTY_IN_BUFFER  = 20;      // faculty clock-in grace (min)
const ATT_POLICY_OUT_BUFFER         = 0;       // no clock-out grace for both types
const ATT_POLICY_EARLY_IN           = '08:30'; // earliest counted clock-in
const ATT_POLICY_EARLY_OUT_OK       = '16:30'; // early birds may leave from here
const ATT_POLICY_FRIDAY_MIN_MINUTES = 480;     // faculty Friday minimum (strict 8 hours)
const ATT_POLICY_LATE_PER_ABSENT    = 4;       // 4 late-in/early-out days = 1 absent

/** Whether the June-2026 attendance policy applies to a date. */
function att_policy_active(string $date): bool
{
    return $date >= ATT_POLICY_EFFECTIVE;
}

/**
 * Employee Type from the staff profile: 'administrative', 'educational'
 * (faculty) or '' when unknown. Cached for the request.
 */
function att_employee_type(int $user_id): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            foreach (db()->query('SELECT user_id, department_type FROM staff_profiles')->fetchAll() as $r) {
                $map[(int)$r['user_id']] = (string)$r['department_type'];
            }
        } catch (Throwable $e) {
            // staff_profiles missing – schedule buffers apply unchanged.
        }
    }
    return $map[$user_id] ?? '';
}

/** Penalty absents: every 4 late-in / early-out days count as 1 absent day. */
function att_late_penalty_days(int $late_early_days): int
{
    return intdiv(max(0, $late_early_days), ATT_POLICY_LATE_PER_ABSENT);
}

/** Reusable card describing the attendance policy (shown to staff and admins). */
function att_policy_rules_html(): string
{
    $eff = date('d M Y', strtotime(ATT_POLICY_EFFECTIVE));
    return '
    <div class="card mb-3" style="border-radius:12px;border-left:4px solid #0d6efd;">
        <div class="card-body py-3">
            <h6 class="fw-semibold mb-2">
                <i class="fas fa-scale-balanced me-2 text-primary"></i>Attendance Policy
                <span class="badge bg-primary ms-1">Effective from ' . h($eff) . '</span>
            </h6>
            <div class="row small g-2">
                <div class="col-md-6">
                    <ul class="mb-0 ps-3">
                        <li><strong>Weekend:</strong> weekly off days are marked as <em>Weekend</em>.</li>
                        <li><strong>Administrative staff:</strong> clock-in grace of <strong>15 minutes</strong> after office start; <strong>no clock-out grace</strong>.</li>
                        <li><strong>Faculty:</strong> clock-in grace of <strong>20 minutes</strong> after office start; <strong>no clock-out grace</strong>.</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <ul class="mb-0 ps-3">
                        <li><strong>Faculty on Friday:</strong> flexible clock in/out — must complete a strict <strong>8 hours</strong> in total; never marked Late In or Early Out.</li>
                        <li><strong>Early birds:</strong> clock in by <strong>8:30 AM</strong> → may leave from <strong>4:30 PM</strong> without Early Out.</li>
                        <li><strong>Penalty:</strong> every <strong>4 Late In / Early Out days</strong> count as <strong>1 Absent day</strong> (salary may be deducted).</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>';
}

// ── Permission helpers ──────────────────────────────────────────────────────

/** Anyone with view access can open the module. */
function att_can_view(): bool
{
    return is_super_admin() || can_access('staff-attendance', 'can_view');
}

/** Whether the current user can add/edit attendance records. */
function att_can_edit(): bool
{
    return is_super_admin() || can_access('staff-attendance', 'can_edit');
}

/**
 * Whether the current user administers the module: they configure the office
 * schedule, per-staff overrides and holidays. Reuses can_edit so the Module
 * Access page controls it; super admins always pass.
 */
function att_is_admin(): bool
{
    return att_can_edit();
}

/**
 * Self-service: staff whose Employee Type (staff_profiles.department_type) is
 * Administrative ('administrative') or Faculty ('educational') may view their
 * OWN attendance page (staff.php) read-only, even without Staff Attendance
 * module access. They cannot add/edit records or open any other module page;
 * anyone granted module access via the Module Access page keeps the full
 * experience based on their access level.
 */
function att_self_service_allowed(): bool
{
    $user = auth_user();
    if (!$user) return false;
    try {
        $stmt = db()->prepare('SELECT department_type FROM staff_profiles WHERE user_id = ?');
        $stmt->execute([(int)$user['id']]);
        $type = (string)$stmt->fetchColumn();
        return in_array($type, ['administrative', 'educational'], true);
    } catch (Throwable $e) {
        return false;
    }
}

// ── Settings (key / value) ──────────────────────────────────────────────────

/** Read a single global setting, falling back to $default. */
function att_get_setting(string $key, ?string $default = null): ?string
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        try {
            $rows = db()->query('SELECT setting_key, setting_val FROM att_settings')->fetchAll();
            foreach ($rows as $row) {
                $settings[(string)$row['setting_key']] = $row['setting_val'];
            }
        } catch (Throwable $e) {
            // Table may not exist yet on older deployments – use defaults.
        }
    }
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

/** Persist a single global setting. */
function att_save_setting(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO att_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)'
    )->execute([$key, $value]);
}

/** The global office schedule (start/close time + buffers) with defaults. */
function att_global_schedule(): array
{
    return [
        'start_time'         => att_normalize_time(att_get_setting('office_start_time', ATT_DEFAULT_START)) ?? ATT_DEFAULT_START,
        'close_time'         => att_normalize_time(att_get_setting('office_close_time', ATT_DEFAULT_CLOSE)) ?? ATT_DEFAULT_CLOSE,
        'in_buffer_minutes'  => max(0, (int)att_get_setting('in_buffer_minutes',  (string)ATT_DEFAULT_IN_BUFFER)),
        'out_buffer_minutes' => max(0, (int)att_get_setting('out_buffer_minutes', (string)ATT_DEFAULT_OUT_BUFFER)),
    ];
}

/** Parse a comma-separated weekly-off string into day numbers (1=Mon … 7=Sun). */
function att_parse_off_days(string $raw): array
{
    if (trim($raw) === '') return [];
    $days = [];
    foreach (explode(',', $raw) as $d) {
        $d = (int)trim($d);
        if ($d >= 1 && $d <= 7) $days[] = $d;
    }
    return array_values(array_unique($days));
}

/** GLOBAL weekly-off day numbers (PHP date('N'): 1=Mon … 7=Sun) as an int array. */
function att_weekly_off_days(): array
{
    return att_parse_off_days((string)att_get_setting('weekly_off_days', ATT_DEFAULT_WEEKLY_OFF));
}

// ── Per-staff effective schedule ────────────────────────────────────────────

/** Fetch all active per-staff schedule overrides keyed by user_id. */
function att_all_overrides(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        // Preferred query includes the per-staff weekly-off column.
        $rows = db()->query(
            'SELECT user_id, start_time, close_time, in_buffer_minutes, out_buffer_minutes, weekly_off_days
               FROM att_staff_schedule WHERE is_active = 1'
        )->fetchAll();
        foreach ($rows as $r) $cache[(int)$r['user_id']] = $r;
    } catch (Throwable $e) {
        // weekly_off_days column (or the whole table) may not exist yet –
        // fall back to the legacy column list so existing overrides keep working.
        try {
            $rows = db()->query(
                'SELECT user_id, start_time, close_time, in_buffer_minutes, out_buffer_minutes
                   FROM att_staff_schedule WHERE is_active = 1'
            )->fetchAll();
            foreach ($rows as $r) $cache[(int)$r['user_id']] = $r;
        } catch (Throwable $e2) {
            // ignore – overrides table may not exist yet
        }
    }
    return $cache;
}

/**
 * The effective schedule for a staff member: their active override merged over
 * the global schedule (any NULL override field falls back to the global value).
 */
function att_effective_schedule(int $user_id): array
{
    $global    = att_global_schedule();
    $overrides = att_all_overrides();
    $o         = $overrides[$user_id] ?? null;
    if (!$o) return $global + ['custom' => false, 'weekly_off_days' => att_weekly_off_days(), 'weekly_off_custom' => false];

    $own_off = trim((string)($o['weekly_off_days'] ?? ''));
    return [
        'start_time'         => att_normalize_time($o['start_time']) ?? $global['start_time'],
        'close_time'         => att_normalize_time($o['close_time']) ?? $global['close_time'],
        'in_buffer_minutes'  => $o['in_buffer_minutes']  !== null ? max(0, (int)$o['in_buffer_minutes'])  : $global['in_buffer_minutes'],
        'out_buffer_minutes' => $o['out_buffer_minutes'] !== null ? max(0, (int)$o['out_buffer_minutes']) : $global['out_buffer_minutes'],
        // Per-staff weekly-off days: empty → inherit the global setting.
        'weekly_off_days'    => $own_off !== '' ? att_parse_off_days($own_off) : att_weekly_off_days(),
        'weekly_off_custom'  => $own_off !== '',
        'custom'             => true,
    ];
}

// ── Time / duration helpers ─────────────────────────────────────────────────

/** Normalise a time string to HH:MM, or null when empty/invalid. */
function att_normalize_time(?string $t): ?string
{
    if ($t === null) return null;
    $t = trim($t);
    if ($t === '') return null;
    $ts = strtotime($t);
    if ($ts === false) return null;
    return date('H:i', $ts);
}

/** Normalise a date string to Y-m-d, falling back to today when invalid. */
function att_normalize_date(?string $d): string
{
    $d  = trim((string)$d);
    $ts = $d !== '' ? strtotime($d) : false;
    return $ts !== false ? date('Y-m-d', $ts) : date('Y-m-d');
}

/** Minutes since midnight for a HH:MM(:SS) string, or null. */
function att_time_to_minutes(?string $t): ?int
{
    $t = att_normalize_time($t);
    if ($t === null) return null;
    [$h, $m] = array_map('intval', explode(':', $t));
    return $h * 60 + $m;
}

/** Worked minutes between in and out time (0 when incomplete or out<in). */
function att_worked_minutes(?string $in, ?string $out): int
{
    $i = att_time_to_minutes($in);
    $o = att_time_to_minutes($out);
    if ($i === null || $o === null || $o < $i) return 0;
    return $o - $i;
}

/** Format a minute count as "8h 30m" (or "—" for 0). */
function att_format_hours(int $minutes): string
{
    if ($minutes <= 0) return '—';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h > 0 && $m > 0) return $h . 'h ' . $m . 'm';
    if ($h > 0)           return $h . 'h';
    return $m . 'm';
}

/** Format a HH:MM:SS time to HH:MM for display, or "—". */
function att_display_time(?string $t): string
{
    $n = att_normalize_time($t);
    return $n ?? '—';
}

// ── Holidays / weekly-off / leave lookups ───────────────────────────────────

/** All holidays within an inclusive date range keyed by Y-m-d => title. */
function att_holidays_in_range(string $from, string $to): array
{
    $map = [];
    try {
        $stmt = db()->prepare(
            'SELECT holiday_date, title FROM att_holidays
              WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date ASC'
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $r) {
            $map[$r['holiday_date']] = $r['title'];
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $map;
}

/**
 * Prime University attendance "month" range for a given YYYY-MM. The payroll-style
 * cycle runs from the 26th of the previous calendar month to the 25th of the
 * selected month (inclusive) — e.g. month "2026-07" spans 26 Jun 2026 → 25 Jul 2026.
 *
 * @return array{from:string,to:string,label:string,month:string}
 */
function att_prime_month_range(string $month): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        $month = date('Y-m');
    }
    $to        = $month . '-25';
    $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
    $from      = $prevMonth . '-26';
    $label     = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
    return ['from' => $from, 'to' => $to, 'label' => $label, 'month' => $month];
}

/** Whether a given Y-m-d date is a GLOBAL weekly-off day. */
function att_is_weekly_off(string $date): bool
{
    $n = (int)date('N', strtotime($date));
    return in_array($n, att_weekly_off_days(), true);
}

/**
 * Whether a date is an off day under a staff member's EFFECTIVE schedule:
 * their own weekly-off override when set, otherwise the global off days.
 * Pass the array returned by att_effective_schedule().
 */
function att_is_weekly_off_for(array $sched, string $date): bool
{
    $days = $sched['weekly_off_days'] ?? att_weekly_off_days();
    return in_array((int)date('N', strtotime($date)), $days, true);
}

/**
 * User IDs on approved leave for a specific date (integrates with the Leave
 * Management module when present). Returns an empty array if that module's
 * tables are missing.
 */
function att_on_leave_user_ids(string $date): array
{
    static $cache = [];
    if (isset($cache[$date])) return $cache[$date];
    $ids = [];
    try {
        $stmt = db()->prepare(
            "SELECT DISTINCT user_id FROM leave_requests
              WHERE status = 'approved' AND ? BETWEEN start_date AND end_date"
        );
        $stmt->execute([$date]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    } catch (Throwable $e) {
        // Leave Management module not installed – ignore.
    }
    // Days explicitly marked "Approved Leave / Day Off" by an admin / the
    // Registrar office, or auto-marked when a leave gets its final approval.
    try {
        $stmt = db()->prepare('SELECT DISTINCT user_id FROM att_day_status WHERE status_date = ?');
        $stmt->execute([$date]);
        foreach ($stmt->fetchAll() as $r) $ids[] = (int)$r['user_id'];
    } catch (Throwable $e) {
        // att_day_status migration not applied yet – ignore.
    }
    return $cache[$date] = array_values(array_unique($ids));
}

// ── Status computation ──────────────────────────────────────────────────────

/**
 * Derive the attendance status for a staff member on a date.
 *
 * $record is an att_records row (or null when none exists). Returns one of:
 *   holiday, weekly_off, leave, absent, upcoming, present, late_in, early_out,
 *   late_and_early (both), incomplete (in-time but no out-time).
 *
 * Future dates (after today) with no recorded in-time are "upcoming", never
 * "absent" — a day can only be marked absent once it has actually happened.
 */
function att_compute_status(?array $record, int $user_id, string $date, array $sched, array $holidays, array $on_leave): string
{
    $has_in  = !empty($record['in_time']);
    $has_out = !empty($record['out_time']);

    $policy = att_policy_active($date);
    $etype  = $policy ? att_employee_type($user_id) : '';

    if (isset($holidays[$date]))            return $has_in ? 'present' : 'holiday';

    // Custom Thursday / Friday slots: when the member defined slots for this
    // weekday (e.g. a slot On Campus + a slot for Online Class), the combined
    // slot window (first slot start → last slot end) IS their expected
    // clock-in/out time for the day, and the day counts as a working day.
    $slot_win = att_slot_window($user_id, $date);
    if ($slot_win !== null) {
        $sched = array_merge($sched, [
            'start_time' => $slot_win['start_time'],
            'close_time' => $slot_win['close_time'],
        ]);
    }

    // Effective buffers. Policy (from 01 Jun 2026): buffers come from the
    // Employee Type — Administrative 15 min in / 0 min out, Faculty 20 min in
    // / 0 min out.
    $in_buffer  = (int)$sched['in_buffer_minutes'];
    $out_buffer = (int)$sched['out_buffer_minutes'];
    if ($policy && $etype === 'administrative') {
        $in_buffer  = ATT_POLICY_ADMIN_IN_BUFFER;
        $out_buffer = ATT_POLICY_OUT_BUFFER;
    } elseif ($policy && $etype === 'educational') {
        $in_buffer  = ATT_POLICY_FACULTY_IN_BUFFER;
        $out_buffer = ATT_POLICY_OUT_BUFFER;
    }

    // Policy: faculty working on a FRIDAY are fully flexible — any clock in/out
    // time is fine, never marked Late In or Early Out, but the day must total a
    // strict 8 hours. Applies whether Friday is their weekend or a scheduled
    // work day. Skipped when the member defined custom slots for the day (the
    // slot window then defines their expected in/out times instead).
    if ($slot_win === null && $policy && $etype === 'educational' && (int)date('N', strtotime($date)) === 5 && $has_in) {
        if (!$has_out) return 'incomplete';
        return att_worked_minutes($record['in_time'], $record['out_time']) >= ATT_POLICY_FRIDAY_MIN_MINUTES
            ? 'present'
            : 'short_hours';
    }

    if ($slot_win === null && att_is_weekly_off_for($sched, $date)) return $has_in ? 'present' : 'weekly_off';
    if (!$has_in && in_array($user_id, $on_leave, true)) return 'leave';
    if (!$has_in && $date > date('Y-m-d')) return 'upcoming';
    if (!$has_in && $date === date('Y-m-d')) {
        // Never mark TODAY as Absent before the expected clock-in time plus the
        // grace period has actually passed.
        $limit = (int)att_time_to_minutes($sched['start_time']) + $in_buffer;
        $now   = (int)date('G') * 60 + (int)date('i');
        if ($now <= $limit) return 'upcoming';
    }
    if (!$has_in)                           return 'absent';

    // Present with an in-time: check late-in / early-out against the schedule.

    $start_lim = att_time_to_minutes($sched['start_time']) + $in_buffer;
    $close_lim = att_time_to_minutes($sched['close_time']) - $out_buffer;
    $in_min    = att_time_to_minutes($record['in_time']);
    $out_min   = $has_out ? att_time_to_minutes($record['out_time']) : null;

    $late  = $in_min !== null && $in_min > $start_lim;
    $early = $out_min !== null && $out_min < $close_lim;

    // Policy: early birds who clock in by 08:30 may leave from 16:30 without
    // being counted late or early.
    if ($policy && $in_min !== null && $out_min !== null
        && $in_min <= (int)att_time_to_minutes(ATT_POLICY_EARLY_IN)
        && $out_min >= (int)att_time_to_minutes(ATT_POLICY_EARLY_OUT_OK)) {
        $late  = false;
        $early = false;
    }

    if (!$has_out)          return 'incomplete';
    if ($late && $early)    return 'late_and_early';
    if ($late)              return 'late_in';
    if ($early)             return 'early_out';
    return 'present';
}

/** Human label for a status code. */
function att_status_label(string $status): string
{
    return match ($status) {
        'present'        => 'Present',
        'late_in'        => 'Late In',
        'early_out'      => 'Early Out',
        'late_and_early' => 'Late In & Early Out',
        'incomplete'     => 'No Out Time',
        'short_hours'    => 'Insufficient Hours (<8h)',
        'absent'         => 'Absent',
        'upcoming'       => 'Upcoming',
        'leave'          => 'On Leave',
        'holiday'        => 'Holiday',
        'weekly_off'     => 'Weekend',
        default          => ucfirst(str_replace('_', ' ', $status)),
    };
}

/** Bootstrap badge for a status code. */
function att_status_badge(string $status): string
{
    $map = [
        'present'        => 'bg-success',
        'late_in'        => 'bg-warning text-dark',
        'early_out'      => 'bg-warning text-dark',
        'late_and_early' => 'bg-warning text-dark',
        'incomplete'     => 'bg-info text-dark',
        'short_hours'    => 'bg-warning text-dark',
        'absent'         => 'bg-danger',
        'upcoming'       => 'bg-light text-muted border',
        'leave'          => 'bg-primary',
        'holiday'        => 'bg-secondary',
        'weekly_off'     => 'bg-light text-dark border',
    ];
    $cls = $map[$status] ?? 'bg-light text-dark';
    return '<span class="badge ' . $cls . '">' . h(att_status_label($status)) . '</span>';
}

// ── Staff directory ─────────────────────────────────────────────────────────

/**
 * Whether the att_device_users table (from the ADMS migration) is present.
 * Cached so att_staff_list() can safely reference it before the migration runs.
 */
function att_device_users_table_exists(): bool
{
    static $exists = null;
    if ($exists === null) {
        try {
            db()->query('SELECT 1 FROM att_device_users LIMIT 1');
            $exists = true;
        } catch (Throwable $e) {
            $exists = false;
        }
    }
    return $exists;
}

/**
 * Active staff to show on the attendance report. This is anyone whose primary
 * group can view the Staff Attendance module OR who is mapped to a device on the
 * Devices page (att_device_users). The device mapping is included so punches
 * pushed by a ZKTeco device always surface on the report, even when the mapped
 * employee's own group lacks the module permission (their device punches are
 * still folded into att_records by the ADMS receiver).
 *
 * Optional filters: department id and a name/username/employee-id search term.
 */
function att_staff_list(int $dept_id = 0, string $search = ''): array
{
    $membership = "EXISTS (SELECT 1 FROM group_module_access gma
                   JOIN modules mm ON mm.id = gma.module_id AND mm.slug = 'staff-attendance'
                  WHERE gma.group_id = u.group_id AND gma.can_view = 1)";
    // Also include users mapped to an attendance device so their pushed punches
    // are never hidden from the report. Guarded because the ADMS tables may not
    // exist yet (migration not applied).
    if (att_device_users_table_exists()) {
        $membership = '(' . $membership . "
                  OR EXISTS (SELECT 1 FROM att_device_users du
                              WHERE du.user_id = u.id AND du.is_active = 1))";
    }

    $where  = [
        'u.is_active = 1',
        $membership,
    ];
    $params = [];

    // Employee ID: the staff profile value, falling back to the member's
    // active device PIN (Devices page mapping) when the profile has none.
    // Staff punched via a ZKTeco device therefore always show an id.
    $has_pins = att_device_users_table_exists();
    $emp_expr = $has_pins
        ? "COALESCE(NULLIF(sp.employee_id, ''),
              (SELECT du2.pin FROM att_device_users du2
                WHERE du2.user_id = u.id AND du2.is_active = 1
                ORDER BY du2.id ASC LIMIT 1))"
        : 'sp.employee_id';

    if ($dept_id > 0) {
        $where[]  = 'sp.staff_dept_id = ?';
        $params[] = $dept_id;
    }
    if ($search !== '') {
        $like   = '%' . $search . '%';
        $cond   = '(u.full_name LIKE ? OR u.username LIKE ? OR sp.employee_id LIKE ?';
        $params[] = $like; $params[] = $like; $params[] = $like;
        if ($has_pins) {
            $cond .= ' OR EXISTS (SELECT 1 FROM att_device_users du3
                        WHERE du3.user_id = u.id AND du3.is_active = 1 AND du3.pin LIKE ?)';
            $params[] = $like;
        }
        $where[] = $cond . ')';
    }

    $sql = 'SELECT u.id, u.full_name, u.username,
                   ' . $emp_expr . ' AS employee_id, sp.staff_dept_id, sd.name AS dept_name
              FROM users u
              JOIN user_groups ug ON ug.id = u.group_id
         LEFT JOIN staff_profiles sp ON sp.user_id = u.id
         LEFT JOIN staff_departments sd ON sd.id = sp.staff_dept_id
             WHERE ' . implode(' AND ', $where) . '
          ORDER BY u.full_name ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Every active account that can be mapped to a device user id, i.e. all active
 * `users` EXCEPT members of the Students user group. Unlike att_staff_list()
 * this is NOT limited to groups with staff-attendance access, so any staff,
 * officer or faculty account can be picked on the Devices page. Membership is
 * taken from the user's primary group (users.group_id), consistent with
 * att_staff_list(); groups literally named "Student"/"Students" are excluded.
 */
function att_mappable_users(): array
{
    try {
        $stmt = db()->prepare(
            "SELECT u.id, u.full_name, u.username, sp.employee_id
               FROM users u
               JOIN user_groups ug ON ug.id = u.group_id
          LEFT JOIN staff_profiles sp ON sp.user_id = u.id
              WHERE u.is_active = 1
                AND LOWER(TRIM(ug.name)) NOT IN ('student', 'students')
           ORDER BY u.full_name ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Active staff departments for filter dropdowns. */
function att_departments(): array
{
    try {
        return db()->query(
            'SELECT id, name, type FROM staff_departments
              WHERE is_active = 1 ORDER BY type ASC, sort_order ASC, name ASC'
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Attendance records for the given user IDs within a date range, keyed by "uid|date". */
function att_records_map(array $user_ids, string $from, string $to): array
{
    $map = [];
    if (empty($user_ids)) return $map;
    $ph   = implode(',', array_fill(0, count($user_ids), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM att_records
          WHERE user_id IN ($ph) AND work_date BETWEEN ? AND ?"
    );
    $stmt->execute(array_merge(array_map('intval', $user_ids), [$from, $to]));
    foreach ($stmt->fetchAll() as $r) {
        $map[$r['user_id'] . '|' . $r['work_date']] = $r;
    }
    return $map;
}

/** University logo as a base64 data URI for embedding in a PDF, or '' if none. */
function att_logo_data_uri(): string
{
    $logo = dirname(dirname(__DIR__)) . '/assets/img/logo/logo-black.png';
    if (is_file($logo) && is_readable($logo)) {
        $bytes = file_get_contents($logo);
        if ($bytes !== false) {
            return 'data:image/png;base64,' . base64_encode($bytes);
        }
    }
    return '';
}

// ── Approved Leave / Day Off marks (admin & Registrar office) ─────────────

/**
 * Whether the current user may mark a day as Approved Leave / Day Off:
 * module admins (can_edit) and any member of the "Registrar office" user group.
 */
function att_can_mark_dayoff(): bool
{
    if (att_is_admin()) return true;
    $user = auth_user();
    if (!$user) return false;
    $gids = array_map('intval', $user['group_ids'] ?? [(int)$user['group_id']]);
    if (empty($gids)) return false;
    try {
        $ph   = implode(',', array_fill(0, count($gids), '?'));
        $stmt = db()->prepare("SELECT name FROM user_groups WHERE id IN ($ph)");
        $stmt->execute($gids);
        foreach ($stmt->fetchAll() as $g) {
            $name = strtolower(trim((string)$g['name']));
            if (in_array($name, ['registrar office', 'registrar-office', 'office of registrar', 'registrar'], true)) {
                return true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return false;
}

/** All Approved Leave / Day Off marks within a date range (admin listing). */
function att_day_status_rows(string $from, string $to): array
{
    try {
        $stmt = db()->prepare(
            'SELECT ds.*, u.full_name, cb.full_name AS created_by_name
               FROM att_day_status ds
               JOIN users u ON u.id = ds.user_id
          LEFT JOIN users cb ON cb.id = ds.created_by
              WHERE ds.status_date BETWEEN ? AND ?
           ORDER BY ds.status_date DESC, u.full_name ASC'
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Mark one day as Approved Leave / Day Off for a staff member (idempotent). */
function att_mark_dayoff(int $user_id, string $date, string $status, ?string $note, string $source = 'manual', ?int $leave_request_id = null, ?int $created_by = null): void
{
    if (!in_array($status, ['approved_leave', 'day_off'], true)) $status = 'approved_leave';
    db()->prepare(
        'INSERT INTO att_day_status (user_id, status_date, status, note, source, leave_request_id, created_by)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note),
                                 source = VALUES(source), leave_request_id = VALUES(leave_request_id)'
    )->execute([$user_id, $date, $status, $note, $source, $leave_request_id, $created_by]);
}

// ── Custom Thursday / Friday day slots ────────────────────────────────────

/** All active day slots keyed by user_id → weekday → ordered slot rows. */
function att_all_slots(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $rows = db()->query(
            'SELECT * FROM att_day_slots WHERE is_active = 1 ORDER BY user_id, weekday, start_time'
        )->fetchAll();
        foreach ($rows as $r) {
            $cache[(int)$r['user_id']][(int)$r['weekday']][] = $r;
        }
    } catch (Throwable $e) {
        // att_day_slots migration not applied yet.
    }
    return $cache;
}

/** Active Thu/Fri slots for a user, keyed by ISO weekday (4=Thu, 5=Fri). */
function att_user_slots(int $user_id): array
{
    $all = att_all_slots();
    return $all[$user_id] ?? [];
}

/**
 * The combined slot window for a user on a date: first slot start → last slot
 * end, used as their expected clock-in/out time. Only Thursday (4) and Friday
 * (5) support custom slots. Returns null when the user has none for the day.
 */
function att_slot_window(int $user_id, string $date): ?array
{
    $wd = (int)date('N', strtotime($date));
    if ($wd !== 4 && $wd !== 5) return null;
    $slots = att_user_slots($user_id)[$wd] ?? [];
    if (empty($slots)) return null;
    $start = null;
    $end   = null;
    foreach ($slots as $s) {
        $st = att_normalize_time($s['start_time'] ?? null);
        $en = att_normalize_time($s['end_time'] ?? null);
        if ($st === null || $en === null) continue;
        if ($start === null || $st < $start) $start = $st;
        if ($end === null || $en > $end)     $end   = $en;
    }
    if ($start === null || $end === null) return null;
    return ['start_time' => $start, 'close_time' => $end, 'slots' => $slots];
}

// ── Weekend (weekly-off) change requests + approval chain ───────────────────

/**
 * The ordered approval chain that applies to a requesting user. Reuses the
 * Leave Management per-group approval flow (leave_approval_flow) so a single
 * chain configuration drives both leave and weekend approvals. Prefers the
 * user's primary group when it has a configured chain, otherwise the first of
 * their groups that does.
 */
function att_weekend_flow_for_user(array $user): array
{
    $primary    = (int)($user['group_id'] ?? 0);
    $group_ids  = array_map('intval', $user['group_ids'] ?? ($primary ? [$primary] : []));
    $candidates = array_values(array_unique(array_merge($primary ? [$primary] : [], $group_ids)));
    try {
        $stmt = db()->prepare(
            'SELECT f.*, g.name AS group_name
               FROM leave_approval_flow f
               JOIN user_groups g ON g.id = f.group_id
              WHERE f.is_active = 1 AND f.requester_group_id = ?
              ORDER BY f.step_order ASC, f.id ASC'
        );
        foreach ($candidates as $gid) {
            if ($gid < 1) continue;
            $stmt->execute([$gid]);
            $rows = $stmt->fetchAll();
            if (!empty($rows)) return $rows;
        }
    } catch (Throwable $e) {
        // Approval-flow tables not installed.
    }
    return [];
}

/** Snapshot the chain into a weekend request; returns the number of steps. */
function att_weekend_snapshot_flow(int $request_id, array $flow): int
{
    $ins = db()->prepare(
        'INSERT INTO att_weekend_request_approvals (request_id, step_order, group_id, label)
         VALUES (?,?,?,?)'
    );
    $step = 0;
    foreach ($flow as $f) {
        $step++;
        $ins->execute([$request_id, $step, (int)$f['group_id'], ($f['label'] ?? '') !== '' ? $f['label'] : null]);
    }
    return $step;
}

/** Ordered approval steps of a weekend request, with group/approver names. */
function att_weekend_request_approvals(int $request_id): array
{
    $stmt = db()->prepare(
        'SELECT a.*, g.name AS group_name, u.full_name AS approver_name
           FROM att_weekend_request_approvals a
           JOIN user_groups g ON g.id = a.group_id
      LEFT JOIN users u ON u.id = a.approver_id
          WHERE a.request_id = ?
          ORDER BY a.step_order ASC'
    );
    $stmt->execute([$request_id]);
    return $stmt->fetchAll();
}

/** The current pending step row of a weekend request, or null. */
function att_weekend_current_step(int $request_id, int $current_step): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM att_weekend_request_approvals WHERE request_id = ? AND step_order = ?'
    );
    $stmt->execute([$request_id, $current_step]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Whether the user may act on the request's current pending step. */
function att_weekend_user_can_act(array $request, array $user): bool
{
    if (($request['status'] ?? '') !== 'pending') return false;
    $step = att_weekend_current_step((int)$request['id'], (int)$request['current_step']);
    if (!$step || $step['status'] !== 'pending') return false;
    $group_ids = array_map('intval', $user['group_ids'] ?? [(int)$user['group_id']]);
    return in_array((int)$step['group_id'], $group_ids, true);
}

/**
 * Apply an approved weekend request: store the requested days as the member's
 * per-staff weekly-off override (other override fields stay untouched).
 */
function att_apply_weekend(int $user_id, string $off_days): void
{
    db()->prepare(
        'INSERT INTO att_staff_schedule (user_id, weekly_off_days, is_active)
         VALUES (?,?,1)
         ON DUPLICATE KEY UPDATE weekly_off_days = VALUES(weekly_off_days), is_active = 1'
    )->execute([$user_id, $off_days !== '' ? $off_days : null]);
}
