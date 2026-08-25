<?php
/**
 * Overtime helpers for the Staff Attendance module.
 *
 * Rules:
 *   • Overtime counts only AFTER the OT start time (default 5:00 PM).
 *   • Staff leaving within the grace window after that (default 30 minutes,
 *     i.e. out by 5:30 PM) earn NO overtime for the day.
 *   • Staff leaving after the grace window earn overtime counted from the OT
 *     start time (5:00 PM) — not from 5:30.
 *   • Daily overtime is capped (default 3 hours). Designations marked
 *     "actual hours" (e.g. Driver) are exempt from the cap and are paid for
 *     the real time worked after the OT start time.
 *   • Weekend / holiday work is NOT overtime by itself: only time after the
 *     OT start time counts, under exactly the same rules as normal days.
 *   • Only staff whose designation is enabled in Overtime Settings are
 *     eligible; each designation carries its own hourly rate (Tk).
 *
 * Configuration is stored as JSON in att_settings under the key "ot_config".
 */

require_once __DIR__ . '/helpers.php';

const ATT_OT_START         = '17:00'; // overtime counted from this time
const ATT_OT_THRESHOLD_MIN = 30;      // leaving within this window → no OT
const ATT_OT_CAP_MIN       = 180;     // daily maximum OT (3 hours)

/** Designations eligible for overtime by default (until configured). */
const ATT_OT_DEFAULT_DESIGNATIONS = [
    'Book Sorter', 'Driver', 'Plumber', 'MLSS Operator', 'Office Assistant',
    'Lab Assistant', 'Cataloguer', 'Receptionist', 'AC Technician',
    'Store Keeper', 'Lift Operator',
];

/** Normalise a designation name for case/space-insensitive matching. */
function att_ot_norm(string $name): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
}

/**
 * The overtime configuration with defaults. Designations are keyed by their
 * normalised name; each entry is ['name', 'rate' (Tk/hour), 'uncapped'].
 * Before any admin saves the settings, the default eligible list applies
 * (rates 0.00, Driver marked "actual hours").
 */
function att_ot_config(): array
{
    $raw        = (string)att_get_setting('ot_config', '');
    $cfg        = $raw !== '' ? json_decode($raw, true) : null;
    $configured = is_array($cfg);
    if (!$configured) $cfg = [];

    $out = [
        'start_time'        => att_normalize_time((string)($cfg['start_time'] ?? '')) ?? ATT_OT_START,
        'threshold_minutes' => isset($cfg['threshold_minutes']) ? max(0, (int)$cfg['threshold_minutes']) : ATT_OT_THRESHOLD_MIN,
        'cap_minutes'       => isset($cfg['cap_minutes']) ? max(0, (int)$cfg['cap_minutes']) : ATT_OT_CAP_MIN,
        'designations'      => [],
    ];

    if ($configured) {
        foreach ((array)($cfg['designations'] ?? []) as $d) {
            $name = trim(preg_replace('/\s+/', ' ', (string)($d['name'] ?? '')));
            if ($name === '') continue;
            $out['designations'][att_ot_norm($name)] = [
                'name'     => $name,
                'rate'     => max(0.0, round((float)($d['rate'] ?? 0), 2)),
                'uncapped' => !empty($d['uncapped']),
            ];
        }
    } else {
        foreach (ATT_OT_DEFAULT_DESIGNATIONS as $name) {
            $out['designations'][att_ot_norm($name)] = [
                'name'     => $name,
                'rate'     => 0.0,
                'uncapped' => att_ot_norm($name) === 'driver', // Drivers: actual hours
            ];
        }
    }
    return $out;
}

/** Persist the overtime configuration. */
function att_ot_save_config(array $cfg): void
{
    att_save_setting('ot_config', json_encode($cfg, JSON_UNESCAPED_UNICODE));
}

/**
 * Overtime minutes for a single day.
 *
 * No in/out time → 0. Out at or before OT start + grace window (5:30 PM by
 * default) → 0. Otherwise OT runs from the OT start time (or the clock-in
 * time when the staff member arrived after it, e.g. an evening call-out) to
 * the clock-out, capped at the daily maximum unless $uncapped (Driver rule:
 * paid for actual hours worked).
 */
function att_ot_day_minutes(?array $record, array $cfg, bool $uncapped): int
{
    if (empty($record['in_time']) || empty($record['out_time'])) return 0;
    $start = (int)att_time_to_minutes($cfg['start_time']);
    $in    = att_time_to_minutes($record['in_time']);
    $out   = att_time_to_minutes($record['out_time']);
    if ($in === null || $out === null || $out <= $in) return 0;

    // Left within the grace window (e.g. by 5:30 PM) → no overtime today.
    if ($out <= $start + (int)$cfg['threshold_minutes']) return 0;

    // Past the window → count from the OT start time (5:00 PM), or from the
    // actual clock-in when they arrived after it.
    $ot = $out - max($start, $in);
    if ($ot <= 0) return 0;
    if (!$uncapped && (int)$cfg['cap_minutes'] > 0) {
        $ot = min($ot, (int)$cfg['cap_minutes']);
    }
    return $ot;
}

/**
 * Active staff whose designation is enabled for overtime, optionally limited
 * to one designation. Matching is case/space-insensitive so "Lift Operator"
 * and "lift operator" in staff profiles both qualify.
 */
function att_ot_staff(array $cfg, string $desig_filter = ''): array
{
    try {
        $rows = db()->query(
            "SELECT u.id, u.full_name, sp.employee_id, sp.designation\n               FROM users u\n               JOIN staff_profiles sp ON sp.user_id = u.id\n              WHERE u.is_active = 1\n                AND sp.designation IS NOT NULL AND sp.designation <> ''\n           ORDER BY sp.designation ASC, u.full_name ASC"
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $filter = $desig_filter !== '' ? att_ot_norm($desig_filter) : '';
    $out    = [];
    foreach ($rows as $r) {
        $k = att_ot_norm((string)$r['designation']);
        if (!isset($cfg['designations'][$k])) continue;
        if ($filter !== '' && $k !== $filter) continue;
        $r['ot_key'] = $k;
        $out[]       = $r;
    }
    return $out;
}

/**
 * Build the overtime report for a date range: one row per eligible staff
 * member with OT days, total OT minutes, hourly rate and the payable amount.
 * Each row also carries a per-day breakdown in 'days' (Y-m-d → minutes).
 */
function att_ot_report(string $from, string $to, array $cfg, string $desig_filter = ''): array
{
    $staff = att_ot_staff($cfg, $desig_filter);
    if (empty($staff)) return [];

    $ids     = array_map(static fn($s) => (int)$s['id'], $staff);
    $records = att_records_map($ids, $from, $to);

    $dates = [];
    for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
        $dates[] = date('Y-m-d', $d);
    }

    $rows = [];
    foreach ($staff as $s) {
        $uid   = (int)$s['id'];
        $d_cfg = $cfg['designations'][$s['ot_key']];
        $days  = [];
        $total = 0;
        foreach ($dates as $d) {
            $mins = att_ot_day_minutes($records[$uid . '|' . $d] ?? null, $cfg, (bool)$d_cfg['uncapped']);
            if ($mins > 0) {
                $days[$d] = $mins;
                $total   += $mins;
            }
        }
        if ($total <= 0) continue; // only staff with overtime appear on the report
        $rows[] = [
            'user_id'     => $uid,
            'full_name'   => (string)$s['full_name'],
            'employee_id' => (string)($s['employee_id'] ?? ''),
            'designation' => $d_cfg['name'],
            'rate'        => (float)$d_cfg['rate'],
            'uncapped'    => (bool)$d_cfg['uncapped'],
            'ot_days'     => count($days),
            'ot_minutes'  => $total,
            'amount'      => round($total / 60 * (float)$d_cfg['rate'], 2),
            'days'        => $days,
        ];
    }
    return $rows;
}

/**
 * Rows for the Overtime Settings table: every configured designation, the
 * default eligible list and every distinct designation found in staff
 * profiles, merged and sorted by name.
 */
function att_ot_settings_rows(array $cfg): array
{
    $rows = [];
    foreach ($cfg['designations'] as $k => $d) {
        $rows[$k] = ['name' => $d['name'], 'on' => true, 'rate' => $d['rate'], 'uncapped' => $d['uncapped']];
    }
    foreach (ATT_OT_DEFAULT_DESIGNATIONS as $name) {
        $k = att_ot_norm($name);
        if (!isset($rows[$k])) {
            $rows[$k] = ['name' => $name, 'on' => false, 'rate' => 0.0, 'uncapped' => $k === 'driver'];
        }
    }
    try {
        $res = db()->query(
            "SELECT DISTINCT designation FROM staff_profiles\n              WHERE designation IS NOT NULL AND designation <> ''"
        )->fetchAll();
        foreach ($res as $r) {
            $name = trim(preg_replace('/\s+/', ' ', (string)$r['designation']));
            $k    = att_ot_norm($name);
            if ($name !== '' && !isset($rows[$k])) {
                $rows[$k] = ['name' => $name, 'on' => false, 'rate' => 0.0, 'uncapped' => false];
            }
        }
    } catch (Throwable $e) {
        // staff_profiles missing – defaults/configured rows only.
    }
    ksort($rows);
    return array_values($rows);
}

/** Format an amount as "1,234.50" for Tk display. */
function att_ot_money(float $v): string
{
    return number_format($v, 2);
}
