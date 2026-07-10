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
if (!in_array($report, ['daily', 'weekly', 'monthly'], true)) $report = 'daily';
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
    // ISO week: Monday → Sunday containing the anchor date.
    $from = date('Y-m-d', strtotime('monday this week', strtotime($anchor)));
    $to   = date('Y-m-d', strtotime('sunday this week', strtotime($anchor)));
    $range_label = date('d M', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
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

require_once __DIR__ . '/../includes/header.php';

$depts   = att_departments();
$weekday = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'];
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
        <?php if ($is_admin): ?>
        <a href="<?= APP_URL ?>/staff-attendance/settings.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-gear me-1"></i> Settings</a>
        <a href="<?= APP_URL ?>/staff-attendance/schedules.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-user-gear me-1"></i> Staff Schedules</a>
        <a href="<?= APP_URL ?>/staff-attendance/holidays.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-calendar-day me-1"></i> Holidays</a>
        <a href="<?= APP_URL ?>/staff-attendance/devices.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-fingerprint me-1"></i> Devices</a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

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
                </select>
            </div>
            <?php if ($report === 'monthly'): ?>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Month</label>
                <input type="month" name="month" class="form-control" value="<?= h(date('Y-m', strtotime($from))) ?>">
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
        <?php if ($report === 'monthly'): ?>
        <a href="<?= APP_URL ?>/staff-attendance/report-pdf.php?month=<?= urlencode(date('Y-m', strtotime($from))) ?>&dept=<?= (int)$dept_id ?>&q=<?= urlencode($search) ?>"
           class="btn btn-outline-danger btn-sm" target="_blank" rel="noopener">
            <i class="fas fa-file-pdf me-1"></i> Download PDF (26th–25th)
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($staff)): ?>
            <p class="text-muted p-4 mb-0">No staff found for the selected filters.</p>
        <?php elseif ($report === 'daily'):
            $date       = $from;
            $on_leave   = $leave_by_date[$date] ?? [];
            $is_holiday = isset($holidays[$date]);
        ?>
            <?php if ($is_holiday): ?>
            <div class="alert alert-secondary mb-0 rounded-0 border-0 border-bottom">
                <i class="fas fa-calendar-day me-1"></i> Holiday: <strong><?= h($holidays[$date]) ?></strong>
            </div>
            <?php elseif (att_is_weekly_off($date)): ?>
            <div class="alert alert-light mb-0 rounded-0 border-0 border-bottom">
                <i class="fas fa-couch me-1"></i> Weekly off day.
            </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-3">Date</th>
                            <th>Employee Name</th>
                            <th>Employee ID</th>
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
                            <td><strong><?= h($s['full_name']) ?></strong></td>
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
                $summ[$uid] = ['present' => 0, 'late' => 0, 'early' => 0, 'absent' => 0, 'leave' => 0, 'minutes' => 0, 'working_days' => 0];
            }
            foreach ($dates as $d) {
                $on_leave = $leave_by_date[$d] ?? [];
                $off      = isset($holidays[$d]) || att_is_weekly_off($d);
                foreach ($staff as $s) {
                    $uid    = (int)$s['id'];
                    $rec    = $records[$uid . '|' . $d] ?? null;
                    $sched  = att_effective_schedule($uid);
                    $status = att_compute_status($rec, $uid, $d, $sched, $holidays, $on_leave);
                    if (!$off) $summ[$uid]['working_days']++;
                    switch ($status) {
                        case 'present':                          $summ[$uid]['present']++; break;
                        case 'late_in':      $summ[$uid]['present']++; $summ[$uid]['late']++;  break;
                        case 'early_out':    $summ[$uid]['present']++; $summ[$uid]['early']++; break;
                        case 'late_and_early': $summ[$uid]['present']++; $summ[$uid]['late']++; $summ[$uid]['early']++; break;
                        case 'incomplete':                       $summ[$uid]['present']++; break;
                        case 'leave':                            $summ[$uid]['leave']++;   break;
                        case 'absent':                           $summ[$uid]['absent']++;  break;
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
                            <th>Total Working Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($staff as $s):
                        $uid = (int)$s['id'];
                        $x   = $summ[$uid];
                    ?>
                        <tr>
                            <td class="px-3"><strong><?= h($s['full_name']) ?></strong></td>
                            <td><?= h($s['employee_id'] ?? '—') ?></td>
                            <td class="small"><?= h($s['dept_name'] ?? '—') ?></td>
                            <td><?= (int)$x['working_days'] ?></td>
                            <td><span class="badge bg-success"><?= (int)$x['present'] ?></span></td>
                            <td><?= $x['late']  ? '<span class="badge bg-warning text-dark">' . (int)$x['late']  . '</span>' : '<span class="text-muted">0</span>' ?></td>
                            <td><?= $x['early'] ? '<span class="badge bg-warning text-dark">' . (int)$x['early'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
                            <td><?= $x['leave'] ? '<span class="badge bg-primary">' . (int)$x['leave'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
                            <td><?= $x['absent'] ? '<span class="badge bg-danger">' . (int)$x['absent'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
                            <td><strong><?= h(att_format_hours((int)$x['minutes'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<p class="text-muted small mt-3">
    Statuses are derived from each staff member's effective schedule
    (individual override or the global office hours) and the configured grace buffers.
    Holidays and weekly-off days are excluded from absence counts.
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
