<?php
/**
 * Staff Attendance dashboard.
 *   report = daily   → one date, every staff member with in/out/hours/status.
 *   report = weekly  → a Mon–Sun week, per-staff summary (present / late / hours).
 *   report = monthly → a calendar month, per-staff summary.
 *
 * Filters (top of page): report type, date/week/month, department, search.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('staff-attendance');
require_once __DIR__ . '/helpers.php';

$page_title = 'Staff Attendance';
$can_edit   = att_can_edit();
$is_admin   = att_is_admin();

// ── Filters ──────────────────────────────────────────────────────────────────
$report  = $_GET['report'] ?? 'daily';
if (!in_array($report, ['daily', 'weekly', 'monthly', 'range'], true)) $report = 'daily';
$dept_id = (int)($_GET['dept'] ?? 0);
$search  = trim($_GET['q'] ?? '');

// Resolve the reporting date range.
$today = date('Y-m-d');
if ($report === 'daily') {
    $date = att_normalize_date($_GET['date'] ?? $today);
    $from = $to = $date;
    $range_label = date('l, d M Y', strtotime($date));
} elseif ($report === 'weekly') {
    $anchor = att_normalize_date($_GET['date'] ?? $today);
    // ISO week: Monday → Sunday containing the anchor date. Derived from the
    // ISO weekday number because strtotime('monday this week') on a Sunday
    // jumps to the FOLLOWING Monday, which showed the wrong week.
    $anchor_ts = strtotime($anchor);
    $from = date('Y-m-d', strtotime('-' . ((int)date('N', $anchor_ts) - 1) . ' days', $anchor_ts));
    $to   = date('Y-m-d', strtotime($from . ' +6 days'));
    $range_label = date('d M', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
} elseif ($report === 'range') {
    // Custom From – To range. Reversed dates are swapped; capped at 366 days.
    $from = att_normalize_date($_GET['from'] ?? date('Y-m-01'));
    $to   = att_normalize_date($_GET['to']   ?? $today);
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    if (strtotime($to) - strtotime($from) > 366 * 86400) {
        $to = date('Y-m-d', strtotime($from . ' +366 days'));
    }
    $range_label = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
} else { // monthly
    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
    $from = $month . '-01';
    $to   = date('Y-m-t', strtotime($from));
    $range_label = date('F Y', strtotime($from));
}

// ── Data ─────────────────────────────────────────────────────────────────────
$staff     = att_staff_list($dept_id, $search);
$user_ids  = array_map(fn($s) => (int)$s['id'], $staff);
$records   = att_records_map($user_ids, $from, $to);
$holidays  = att_holidays_in_range($from, $to);

// Enumerate the dates in range once (used for weekly/monthly aggregation).
$dates = [];
for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
    $dates[] = date('Y-m-d', $d);
}

// Pre-load approved-leave user ids per date.
$leave_by_date = [];
foreach ($dates as $d) $leave_by_date[$d] = att_on_leave_user_ids($d);

// ── CSV export – follows the exact filters & date range resolved above ──────
if (($_GET['export'] ?? '') === 'csv') {
    // Daily is always exported day-by-day; other reports export a per-staff
    // summary unless detail=1 requests the day-by-day breakdown.
    $detail = $report === 'daily' || ($_GET['detail'] ?? '') === '1';

    $status_labels = [
        'present' => 'Present', 'late_in' => 'Late In', 'early_out' => 'Early Out',
        'late_and_early' => 'Late In + Early Out', 'incomplete' => 'Incomplete',
        'leave' => 'On Leave', 'absent' => 'Absent', 'holiday' => 'Holiday',
        'weekly_off' => 'Weekend', 'off' => 'Weekend',
        'short_hours' => 'Insufficient Hours',
    ];
    $status_text = static fn(string $s): string => $status_labels[$s] ?? ucwords(str_replace('_', ' ', $s));

    $filename = 'staff-attendance-' . $report . '-' . $from . '-to-' . $to
              . ($detail ? '-day-by-day' : '-summary') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly

    if ($detail) {
        fputcsv($out, ['Date', 'Employee Name', 'Employee ID', 'Department',
                       'In Time', 'Out Time', 'Total Working Hours', 'Status']);
        foreach ($dates as $d) {
            $on_leave = $leave_by_date[$d] ?? [];
            foreach ($staff as $s) {
                $uid    = (int)$s['id'];
                $rec    = $records[$uid . '|' . $d] ?? null;
                $sched  = att_effective_schedule($uid);
                $status = att_compute_status($rec, $uid, $d, $sched, $holidays, $on_leave);
                $mins   = $rec ? att_worked_minutes($rec['in_time'], $rec['out_time']) : 0;
                fputcsv($out, [
                    date('d/m/Y', strtotime($d)),
                    (string)$s['full_name'],
                    (string)($s['employee_id'] ?? ''),
                    (string)($s['dept_name'] ?? ''),
                    att_display_time($rec['in_time'] ?? null),
                    att_display_time($rec['out_time'] ?? null),
                    att_format_hours($mins),
                    $status_text($status),
                ]);
            }
        }
    } else {
        fputcsv($out, ['Employee Name', 'Employee ID', 'Department', 'Working Days',
                       'Present', 'Late In', 'Early Out', 'On Leave', 'Absent',
                       'Penalty Absent (4 Late/Early = 1)', 'Total Absent (incl. Penalty)', 'Total Working Hours']);
        foreach ($staff as $s) {
            $uid   = (int)$s['id'];
            $sched = att_effective_schedule($uid);
            $x = ['present' => 0, 'late' => 0, 'early' => 0, 'absent' => 0,
                  'leave' => 0, 'minutes' => 0, 'working_days' => 0, 'pen' => 0];
            foreach ($dates as $d) {
                $on_leave = $leave_by_date[$d] ?? [];
                $off      = (isset($holidays[$d]) && att_holiday_applies($uid, $d)) || att_is_weekly_off_for($sched, $d);
                $rec      = $records[$uid . '|' . $d] ?? null;
                $status   = att_compute_status($rec, $uid, $d, $sched, $holidays, $on_leave);
                if (!$off) $x['working_days']++;
                switch ($status) {
                    case 'present':                              $x['present']++; break;
                    case 'late_in':        $x['present']++; $x['late']++;  break;
                    case 'early_out':      $x['present']++; $x['early']++; break;
                    case 'late_and_early': $x['present']++; $x['late']++; $x['early']++; break;
                    case 'short_hours':                          $x['present']++; break;
                    case 'incomplete':                           $x['present']++; break;
                    case 'leave':                                $x['leave']++;   break;
                    case 'absent':                               $x['absent']++;  break;
                }
                // Policy (from 01 Jun 2026): each late-in/early-out day counts toward the penalty.
                if (att_policy_active($d) && in_array($status, ['late_in', 'early_out', 'late_and_early'], true)) {
                    $x['pen']++;
                }
                if ($rec) $x['minutes'] += att_worked_minutes($rec['in_time'], $rec['out_time']);
            }
            $pen_abs = att_late_penalty_days((int)$x['pen']);
            fputcsv($out, [
                (string)$s['full_name'],
                (string)($s['employee_id'] ?? ''),
                (string)($s['dept_name'] ?? ''),
                $x['working_days'],
                $x['present'],
                $x['late'],
                $x['early'],
                $x['leave'],
                $x['absent'],
                $pen_abs,
                $x['absent'] + $pen_abs,
                att_format_hours((int)$x['minutes']),
            ]);
        }
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/header.php';

$depts   = att_departments();
$weekday = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'];

// Calendar month the drill-down opens on (the month containing the range start).
$staff_month = date('Y-m', strtotime($from));

/** URL to a staff member's calendar drill-down, preserving the report filters. */
$staff_link = static function (int $uid) use ($report, $staff_month, $dept_id, $search, $from, $to): string {
    $params = [
        'user_id' => $uid,
        'month'   => $staff_month,
        'report'  => $report,
        'dept'    => $dept_id,
        'q'       => $search,
    ];
    // Carry the custom range through so "Back to report" restores it.
    if ($report === 'range') { $params['from'] = $from; $params['to'] = $to; }
    return APP_URL . '/staff-attendance/staff.php?' . http_build_query($params);
};
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-user-clock me-2 text-primary"></i>Staff Attendance</h1>
        <p class="text-muted mb-0 small">Daily attendance with in/out times, total working hours and reports.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($can_edit): ?>
        <a href="<?= APP_URL ?>/staff-attendance/entry.php<?= $report === 'daily' ? '?date=' . urlencode($from) : '' ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i> Record Attendance
        </a>
        <?php endif; ?>
        <?php if (att_can_mark_dayoff()): ?>
        <a href="<?= APP_URL ?>/staff-attendance/day-status.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-umbrella-beach me-1"></i> Approved Leave / Day Off</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/staff-attendance/weekend-approvals.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-clipboard-check me-1"></i> Schedule Approvals</a>
        <?php if ($is_admin): ?>
        <a href="<?= APP_URL ?>/staff-attendance/overtime.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-business-time me-1"></i> Overtime</a>
        <a href="<?= APP_URL ?>/staff-attendance/schedule-flow.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-diagram-project me-1"></i> Schedule Approval Flow</a>
        <a href="<?= APP_URL ?>/staff-attendance/settings.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-gear me-1"></i> Settings</a>
        <a href="<?= APP_URL ?>/staff-attendance/schedules.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-user-gear me-1"></i> Staff Schedules</a>
        <a href="<?= APP_URL ?>/staff-attendance/holidays.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-calendar-day me-1"></i> Holidays</a>
        <a href="<?= APP_URL ?>/staff-attendance/devices.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-fingerprint me-1"></i> Devices</a>
        <a href="<?= APP_URL ?>/staff-attendance/duplicate-review.php" class="btn btn-outline-danger btn-sm"><i class="fas fa-people-arrows me-1"></i> Duplicate ID Review</a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<details class="mb-3" open>
    <summary class="text-muted small mb-1" style="cursor:pointer"><i class="fas fa-scale-balanced me-1"></i>Attendance policy (effective 01 Jun 2026) — click to show/hide</summary>
    <?= att_policy_rules_html() ?>
</details>

<!-- ── Filters ── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label fw-semibold small mb-1">Report</label>
                <select name="report" class="form-select" onchange="this.form.submit()">
                    <option value="daily"   <?= $report === 'daily'   ? 'selected' : '' ?>>Daily</option>
                    <option value="weekly"  <?= $report === 'weekly'  ? 'selected' : '' ?>>Weekly</option>
                    <option value="monthly" <?= $report === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    <option value="range"   <?= $report === 'range'   ? 'selected' : '' ?>>Custom Range</option>
                </select>
            </div>
            <?php if ($report === 'monthly'): ?>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Month</label>
                <input type="month" name="month" class="form-control" value="<?= h(date('Y-m', strtotime($from))) ?>">
            </div>
            <?php elseif ($report === 'range'): ?>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">From – To</label>
                <div class="input-group">
                    <input type="date" name="from" class="form-control" value="<?= h($from) ?>" title="From date">
                    <input type="date" name="to"   class="form-control" value="<?= h($to) ?>"   title="To date">
                </div>
            </div>
            <?php else: ?>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1"><?= $report === 'weekly' ? 'Week of' : 'Date' ?></label>
                <input type="date" name="date" class="form-control" value="<?= h($from) ?>">
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Department</label>
                <select name="dept" class="form-select">
                    <option value="0">All Departments</option>
                    <?php foreach ($depts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $dept_id === (int)$d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?> (<?= ucfirst($d['type']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold small mb-1">Search</label>
                <input type="text" name="q" class="form-control" value="<?= h($search) ?>" placeholder="Name / ID">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Apply</button>
                <a href="<?= APP_URL ?>/staff-attendance/index.php" class="btn btn-secondary"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-calendar-check me-2 text-muted"></i>
            <?= ucfirst($report) ?> Report — <?= h($range_label) ?>
            <span class="badge bg-secondary ms-1"><?= count($staff) ?> staff</span>
        </h6>
        <div class="d-flex gap-2 flex-wrap">
            <?php
            // Export links are rebuilt from the RESOLVED filters, so the CSV
            // always matches exactly the dates and staff shown on screen.
            $export_base = ['report' => $report, 'dept' => $dept_id, 'q' => $search, 'export' => 'csv'];
            if ($report === 'monthly')     { $export_base['month'] = date('Y-m', strtotime($from)); }
            elseif ($report === 'range')   { $export_base['from'] = $from; $export_base['to'] = $to; }
            else                           { $export_base['date'] = $from; }
            $export_url        = APP_URL . '/staff-attendance/index.php?' . http_build_query($export_base);
            $export_detail_url = APP_URL . '/staff-attendance/index.php?' . http_build_query($export_base + ['detail' => '1']);
            ?>
            <a href="<?= h($export_url) ?>" class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv me-1"></i> Export CSV<?= $report === 'daily' ? '' : ' (Summary)' ?>
            </a>
            <?php if ($report !== 'daily'): ?>
            <a href="<?= h($export_detail_url) ?>" class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv me-1"></i> Export CSV (Day-by-Day)
            </a>
            <?php endif; ?>
            <?php if ($report === 'monthly'): ?>
            <a href="<?= APP_URL ?>/staff-attendance/report-pdf.php?month=<?= urlencode(date('Y-m', strtotime($from))) ?>&dept=<?= (int)$dept_id ?>&q=<?= urlencode($search) ?>"
               class="btn btn-outline-danger btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-file-pdf me-1"></i> Download PDF (26th–25th)
            </a>
            <?php endif; ?>
            <?php
            // Department-wise Attendance Statement (landscape, one department per
            // page) for the resolved date range — downloadable as PDF or DOC.
            $stmt_base = ['from' => $from, 'to' => $to, 'dept' => $dept_id, 'q' => $search];
            $stmt_pdf  = APP_URL . '/staff-attendance/statement.php?' . http_build_query($stmt_base + ['format' => 'pdf']);
            $stmt_doc  = APP_URL . '/staff-attendance/statement.php?' . http_build_query($stmt_base + ['format' => 'doc']);
            ?>
            <a href="<?= h($stmt_pdf) ?>" class="btn btn-outline-danger btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-file-pdf me-1"></i> Attendance Statement (PDF)
            </a>
            <a href="<?= h($stmt_doc) ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-file-word me-1"></i> Attendance Statement (DOC)
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($staff)): ?>
            <p class="text-muted p-4 mb-0">No staff found for the selected filters.</p>
        <?php elseif ($report === 'daily'):
            $date       = $from;
            $on_leave   = $leave_by_date[$date] ?? [];
            $is_holiday = isset($holidays[$date]);
            $hol_groups = $is_holiday ? att_holiday_group_names($date) : [];
        ?>
            <?php if ($is_holiday): ?>
            <div class="alert alert-secondary mb-0 rounded-0 border-0 border-bottom">
                <i class="fas fa-calendar-day me-1"></i> Holiday: <strong><?= h($holidays[$date]) ?></strong>
                <?php if (!empty($hol_groups)): ?>
                <span class="text-muted small">(applies to <?= h(implode(', ', $hol_groups)) ?> only)</span>
                <?php endif; ?>
            </div>
            <?php elseif (att_is_weekly_off($date)): ?>
            <div class="alert alert-light mb-0 rounded-0 border-0 border-bottom">
                <i class="fas fa-couch me-1"></i> Weekend.
            </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-3">Date</th>
                            <th>Employee Name</th>
                            <th>Employee ID</th>
                            <th>Designation</th>
                            <th>Department</th>
                            <th>In Time</th>
                            <th>Out Time</th>
                            <th>Total Working Hours</th>
                            <th>Status</th>
                            <?php if ($can_edit): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($staff as $s):
                        $uid    = (int)$s['id'];
                        $rec    = $records[$uid . '|' . $date] ?? null;
                        $sched  = att_effective_schedule($uid);
                        $status = att_compute_status($rec, $uid, $date, $sched, $holidays, $on_leave);
                        $mins   = $rec ? att_worked_minutes($rec['in_time'], $rec['out_time']) : 0;
                    ?>
                        <tr>
                            <td class="px-3 small text-muted"><?= h(date('d M Y', strtotime($date))) ?></td>
                            <td><a href="<?= h($staff_link($uid)) ?>" class="fw-bold text-decoration-none"><?= h($s['full_name']) ?></a></td>
                            <td><?= h($s['employee_id'] ?? '—') ?></td>
                            <td class="small"><?= h($s['dept_name'] ?? '—') ?></td>
                            <td><?= h(att_display_time($rec['in_time'] ?? null)) ?></td>
                            <td><?= h(att_display_time($rec['out_time'] ?? null)) ?></td>
                            <td><?= h(att_format_hours($mins)) ?></td>
                            <td><?= att_status_badge($status) ?></td>
                            <?php if ($can_edit): ?>
                            <td class="text-end pe-3">
                                <a href="<?= APP_URL ?>/staff-attendance/entry.php?user_id=<?= $uid ?>&date=<?= urlencode($date) ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                                    <i class="fas fa-<?= $rec ? 'edit' : 'plus' ?>"></i>
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else:
            // ── Weekly / monthly summary aggregation ──
            $summ = [];
            foreach ($staff as $s) {
                $uid = (int)$s['id'];
                $summ[$uid] = ['present' => 0, 'late' => 0, 'early' => 0, 'absent' => 0, 'leave' => 0, 'minutes' => 0, 'working_days' => 0, 'pen' => 0];
            }
            foreach ($dates as $d) {
                $on_leave    = $leave_by_date[$d] ?? [];
                $holiday_off = isset($holidays[$d]);
                foreach ($staff as $s) {
                    $uid    = (int)$s['id'];
                    $rec    = $records[$uid . '|' . $d] ?? null;
                    $sched  = att_effective_schedule($uid);
                    $status = att_compute_status($rec, $uid, $d, $sched, $holidays, $on_leave);
                    // Off days are per staff member (weekly-off override and holiday user-group aware).
                    $off = ($holiday_off && att_holiday_applies($uid, $d)) || att_is_weekly_off_for($sched, $d);
                    if (!$off) $summ[$uid]['working_days']++;
                    switch ($status) {
                        case 'present':                          $summ[$uid]['present']++; break;
                        case 'late_in':      $summ[$uid]['present']++; $summ[$uid]['late']++;  break;
                        case 'early_out':    $summ[$uid]['present']++; $summ[$uid]['early']++; break;
                        case 'late_and_early': $summ[$uid]['present']++; $summ[$uid]['late']++; $summ[$uid]['early']++; break;
                        case 'short_hours':                      $summ[$uid]['present']++; break;
                        case 'incomplete':                       $summ[$uid]['present']++; break;
                        case 'leave':                            $summ[$uid]['leave']++;   break;
                        case 'absent':                           $summ[$uid]['absent']++;  break;
                    }
                    // Policy (from 01 Jun 2026): each late-in/early-out day counts toward the penalty.
                    if (att_policy_active($d) && in_array($status, ['late_in', 'early_out', 'late_and_early'], true)) {
                        $summ[$uid]['pen']++;
                    }
                    if ($rec) $summ[$uid]['minutes'] += att_worked_minutes($rec['in_time'], $rec['out_time']);
                }
            }
        ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-3">Employee Name</th>
                            <th>Employee ID</th>
                            <th>Department</th>
                            <th>Working Days</th>
                            <th>Present</th>
                            <th>Late In</th>
                            <th>Early Out</th>
                            <th>On Leave</th>
                            <th>Absent</th>
                            <th title="Every 4 Late In / Early Out days = 1 Absent day (effective 01 Jun 2026)">Penalty Absent</th>
                            <th>Total Working Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($staff as $s):
                        $uid = (int)$s['id'];
                        $x   = $summ[$uid];
                        $slink = h($staff_link($uid));
                    ?>
                        <tr style="cursor:pointer;" onclick="window.location='<?= $slink ?>'">
                            <td class="px-3"><a href="<?= $slink ?>" class="fw-bold text-decoration-none"><?= h($s['full_name']) ?></a></td>
                            <td><a href="<?= $slink ?>" class="text-decoration-none text-reset"><?= h($s['employee_id'] ?? '—') ?></a></td>
                            <td class="small"><?= h($s['dept_name'] ?? '—') ?></td>
                            <td><a href="<?= $slink ?>" class="text-decoration-none text-reset"><?= (int)$x['working_days'] ?></a></td>
                            <td><a href="<?= $slink ?>" class="text-decoration-none"><span class="badge bg-success"><?= (int)$x['present'] ?></span></a></td>
                            <td><a href="<?= $slink ?>" class="text-decoration-none"><?= $x['late']  ? '<span class="badge bg-warning text-dark">' . (int)$x['late']  . '</span>' : '<span class="text-muted">0</span>' ?></a></td>
                            <td><a href="<?= $slink ?>" class="text-decoration-none"><?= $x['early'] ? '<span class="badge bg-warning text-dark">' . (int)$x['early'] . '</span>' : '<span class="text-muted">0</span>' ?></a></td>
                            <td><a href="<?= $slink ?>" class="text-decoration-none"><?= $x['leave'] ? '<span class="badge bg-primary">' . (int)$x['leave'] . '</span>' : '<span class="text-muted">0</span>' ?></a></td>
                            <td><a href="<?= $slink ?>" class="text-decoration-none"><?= $x['absent'] ? '<span class="badge bg-danger">' . (int)$x['absent'] . '</span>' : '<span class="text-muted">0</span>' ?></a></td>
                            <?php $pen_abs = att_late_penalty_days((int)($x['pen'] ?? 0)); ?>
                            <td><a href="<?= $slink ?>" class="text-decoration-none" title="<?= (int)($x['pen'] ?? 0) ?> Late In / Early Out day(s) since 01 Jun 2026 — every 4 = 1 Absent"><?= $pen_abs ? '<span class="badge bg-danger">' . $pen_abs . '</span>' : '<span class="text-muted">0</span>' ?></a></td>
                            <td><a href="<?= $slink ?>" class="text-decoration-none text-reset"><strong><?= h(att_format_hours((int)$x['minutes'])) ?></strong></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<p class="text-muted small mt-3">
    Click any staff member (name or count) to open their monthly calendar with in/out
    times and a day-by-day breakdown. Statuses are derived from each staff member's
    effective schedule (individual override or the global office hours) and the
    configured grace buffers. Holidays and Weekend days are excluded from absence counts.
    From <strong>01 Jun 2026</strong>: Administrative staff get a 15-minute and Faculty a 30-minute
    clock-in grace (9:00 start → in by 9:30 is on time); faculty must complete at least
    <strong>7 hours 30 minutes</strong> in a day (below this shows <em>Insufficient Hours</em>);
    faculty Fridays are flexible (strict 8 hours, never marked Late/Early); clocking in by
    8:30 AM allows leaving from 4:30 PM; and every
    <strong>4 Late In / Early Out days count as 1 Absent day</strong> (Penalty Absent column — salary may be deducted).
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
