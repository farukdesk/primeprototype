<?php
/**
 * Staff API – GET /api/staff/attendance.php?month=YYYY-MM
 * =========================================================
 * Day-wise attendance for the signed-in employee over the Prime attendance
 * month (26th of the previous month → 25th of the selected month), plus a
 * summary. Statuses are computed with the exact same engine the Staff
 * Attendance admin module uses (schedules, holidays, weekends, leave,
 * employee-type policy buffers, penalty rule).
 */

require_once __DIR__ . '/includes/auth_staff_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx = staff_api_auth();
$uid = (int)$ctx['user']['user_id'];

// Reuse the Staff Attendance module's schedule / status engine.
require_once dirname(__DIR__, 2) . '/staff-attendance/helpers.php';

$month    = (string)($_GET['month'] ?? date('Y-m'));
$range    = att_prime_month_range($month);
$sched    = att_effective_schedule($uid);
$holidays = att_holidays_in_range($range['from'], $range['to']);
$records  = att_records_map([$uid], $range['from'], $range['to']);

$days    = [];
$summary = [
    'working_days' => 0, 'present' => 0, 'late_in' => 0, 'early_out' => 0,
    'late_and_early' => 0, 'incomplete' => 0, 'short_hours' => 0, 'absent' => 0,
    'leave' => 0, 'holiday' => 0, 'weekly_off' => 0, 'upcoming' => 0,
];

for ($d = strtotime($range['from']); $d <= strtotime($range['to']); $d += 86400) {
    $date   = date('Y-m-d', $d);
    $rec    = $records[$uid . '|' . $date] ?? null;
    $status = att_compute_status($rec, $uid, $date, $sched, $holidays, att_on_leave_user_ids($date));

    if (isset($summary[$status])) $summary[$status]++;
    if (!in_array($status, ['holiday', 'weekly_off', 'upcoming'], true)) $summary['working_days']++;

    $worked = att_worked_minutes($rec['in_time'] ?? null, $rec['out_time'] ?? null);
    $days[] = [
        'date'         => $date,
        'weekday'      => date('D', $d),
        'in_time'      => !empty($rec['in_time'])  ? substr((string)$rec['in_time'], 0, 5)  : null,
        'out_time'     => !empty($rec['out_time']) ? substr((string)$rec['out_time'], 0, 5) : null,
        'worked'       => $worked > 0 ? att_format_hours($worked) : null,
        'status'       => $status,
        'status_label' => att_status_label($status),
        'holiday'      => $holidays[$date] ?? null,
    ];
}

$late_early = $summary['late_in'] + $summary['early_out'] + $summary['late_and_early'];
$summary['late_early_days']   = $late_early;
$summary['late_penalty_days'] = att_late_penalty_days($late_early);

api_ok([
    'month'   => $range['month'],
    'from'    => $range['from'],
    'to'      => $range['to'],
    'label'   => $range['label'],
    'summary' => $summary,
    'days'    => array_reverse($days), // newest first for the app list
]);
