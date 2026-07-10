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
 * not a weekly-off day) and the staff member has no in-time and no approved
 * leave covering that date.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Defaults (used before the settings migration is applied / when unset) ────
const ATT_DEFAULT_START      = '09:00';
const ATT_DEFAULT_CLOSE      = '17:00';
const ATT_DEFAULT_IN_BUFFER  = 15;
const ATT_DEFAULT_OUT_BUFFER = 15;
const ATT_DEFAULT_WEEKLY_OFF = '5'; // Friday (PHP date('N'): 1=Mon … 7=Sun)

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

/** Weekly-off day numbers (PHP date('N'): 1=Mon … 7=Sun) as an int array. */
function att_weekly_off_days(): array
{
    $raw = (string)att_get_setting('weekly_off_days', ATT_DEFAULT_WEEKLY_OFF);
    if (trim($raw) === '') return [];
    $days = [];
    foreach (explode(',', $raw) as $d) {
        $d = (int)trim($d);
        if ($d >= 1 && $d <= 7) $days[] = $d;
    }
    return array_values(array_unique($days));
}

// ── Per-staff effective schedule ────────────────────────────────────────────

/** Fetch all active per-staff schedule overrides keyed by user_id. */
function att_all_overrides(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $rows = db()->query(
            'SELECT user_id, start_time, close_time, in_buffer_minutes, out_buffer_minutes
               FROM att_staff_schedule WHERE is_active = 1'
        )->fetchAll();
        foreach ($rows as $r) $cache[(int)$r['user_id']] = $r;
    } catch (Throwable $e) {
        // ignore – overrides table may not exist yet
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
    if (!$o) return $global + ['custom' => false];

    return [
        'start_time'         => att_normalize_time($o['start_time']) ?? $global['start_time'],
        'close_time'         => att_normalize_time($o['close_time']) ?? $global['close_time'],
        'in_buffer_minutes'  => $o['in_buffer_minutes']  !== null ? max(0, (int)$o['in_buffer_minutes'])  : $global['in_buffer_minutes'],
        'out_buffer_minutes' => $o['out_buffer_minutes'] !== null ? max(0, (int)$o['out_buffer_minutes']) : $global['out_buffer_minutes'],
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

/** Whether a given Y-m-d date is a weekly-off day. */
function att_is_weekly_off(string $date): bool
{
    $n = (int)date('N', strtotime($date));
    return in_array($n, att_weekly_off_days(), true);
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
    return $cache[$date] = $ids;
}

// ── Status computation ──────────────────────────────────────────────────────

/**
 * Derive the attendance status for a staff member on a date.
 *
 * $record is an att_records row (or null when none exists). Returns one of:
 *   holiday, weekly_off, leave, absent, present, late_in, early_out,
 *   late_and_early (both), incomplete (in-time but no out-time).
 */
function att_compute_status(?array $record, int $user_id, string $date, array $sched, array $holidays, array $on_leave): string
{
    $has_in  = !empty($record['in_time']);
    $has_out = !empty($record['out_time']);

    if (isset($holidays[$date]))            return $has_in ? 'present' : 'holiday';
    if (att_is_weekly_off($date))           return $has_in ? 'present' : 'weekly_off';
    if (!$has_in && in_array($user_id, $on_leave, true)) return 'leave';
    if (!$has_in)                           return 'absent';

    // Present with an in-time: check late-in / early-out against the schedule.
    $start_lim = att_time_to_minutes($sched['start_time']) + (int)$sched['in_buffer_minutes'];
    $close_lim = att_time_to_minutes($sched['close_time']) - (int)$sched['out_buffer_minutes'];
    $in_min    = att_time_to_minutes($record['in_time']);
    $out_min   = $has_out ? att_time_to_minutes($record['out_time']) : null;

    $late  = $in_min !== null && $in_min > $start_lim;
    $early = $out_min !== null && $out_min < $close_lim;

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
        'absent'         => 'Absent',
        'leave'          => 'On Leave',
        'holiday'        => 'Holiday',
        'weekly_off'     => 'Weekly Off',
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
        'absent'         => 'bg-danger',
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

    if ($dept_id > 0) {
        $where[]  = 'sp.staff_dept_id = ?';
        $params[] = $dept_id;
    }
    if ($search !== '') {
        $like     = '%' . $search . '%';
        $where[]  = '(u.full_name LIKE ? OR u.username LIKE ? OR sp.employee_id LIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $sql = 'SELECT u.id, u.full_name, u.username,
                   sp.employee_id, sp.staff_dept_id, sd.name AS dept_name
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
