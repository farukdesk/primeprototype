<?php
/**
 * Staff Attendance – per-staff monthly calendar drill-down.
 *
 * Reached by clicking a staff member (name or any summary count) on the daily /
 * weekly / monthly report. Shows, for a single staff member and a calendar month:
 *   1. a month calendar where every day cell shows the in/out times and is colour
 *      marked Present / Late / Early / Absent / On Leave / Off (holiday·weekly-off);
 *   2. a summary strip (present, late, early, on-leave, absent, working days, hours);
 *   3. a day-by-day table of in / out / total working hours / status.
 *
 * Query string: user_id (required), month=YYYY-MM (default current month),
 * plus dept & q so the "Back to report" link preserves the report filters.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('staff-attendance');
require_once __DIR__ . '/helpers.php';

$page_title = 'Staff Attendance – Calendar';
$can_edit   = att_can_edit();

// ── Resolve the staff member ────────────────────────────────────────────────
$user_id = (int)($_GET['user_id'] ?? 0);
$staff   = att_staff_list();
$member  = null;
foreach ($staff as $s) {
    if ((int)$s['id'] === $user_id) { $member = $s; break; }
}

// Filters to carry back to the report.
$dept_id = (int)($_GET['dept'] ?? 0);
$search  = trim($_GET['q'] ?? '');
$report  = $_GET['report'] ?? 'monthly';
if (!in_array($report, ['daily', 'weekly', 'monthly', 'range'], true)) $report = 'monthly';
$r_from  = $report === 'range' ? att_normalize_date($_GET['from'] ?? '') : null;
$r_to    = $report === 'range' ? att_normalize_date($_GET['to'] ?? '')   : null;

// ── Resolve the calendar month ──────────────────────────────────────────────
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$from  = $month . '-01';
$to    = date('Y-m-t', strtotime($from));
$month_label = date('F Y', strtotime($from));
$prev_month  = date('Y-m', strtotime($from . ' -1 month'));
$next_month  = date('Y-m', strtotime($from . ' +1 month'));

require_once __DIR__ . '/../includes/header.php';

// Build the report-link query (used for the "Back" link).
$report_qs = http_build_query(array_filter([
    'report' => $report,
    'month'  => $report === 'monthly' ? $month : null,
    'from'   => $r_from,
    'to'     => $r_to,
    'dept'   => $dept_id ?: null,
    'q'      => $search ?: null,
]));

if (!$member):
?>
<div class="alert alert-warning">Staff member not found or not visible on the attendance report.</div>
<a href="<?= APP_URL ?>/staff-attendance/index.php" class="btn btn-secondary btn-sm">Back to Attendance</a>
<?php
require_once __DIR__ . '/../includes/footer.php';
return;
endif;

// ── Gather the month's data for this staff member ───────────────────────────
$records  = att_records_map([$user_id], $from, $to);
$holidays = att_holidays_in_range($from, $to);
$sched    = att_effective_schedule($user_id);

$dates = [];
for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
    $dates[] = date('Y-m-d', $d);
}
$leave_by_date = [];
foreach ($dates as $d) $leave_by_date[$d] = att_on_leave_user_ids($d);

// Per-day computed rows + running summary.
$days = [];
$sum  = ['present' => 0, 'late' => 0, 'early' => 0, 'absent' => 0, 'leave' => 0, 'off' => 0, 'working_days' => 0, 'minutes' => 0];
foreach ($dates as $d) {
    $rec      = $records[$user_id . '|' . $d] ?? null;
    $on_leave = $leave_by_date[$d] ?? [];
    $status   = att_compute_status($rec, $user_id, $d, $sched, $holidays, $on_leave);
    $mins     = $rec ? att_worked_minutes($rec['in_time'] ?? null, $rec['out_time'] ?? null) : 0;
    $off      = isset($holidays[$d]) || att_is_weekly_off_for($sched, $d);

    if (!$off) $sum['working_days']++;
    switch ($status) {
        case 'present':                                        $sum['present']++; break;
        case 'late_in':        $sum['present']++; $sum['late']++;  break;
        case 'early_out':      $sum['present']++; $sum['early']++; break;
        case 'late_and_early': $sum['present']++; $sum['late']++; $sum['early']++; break;
        case 'incomplete':     $sum['present']++; break;
        case 'leave':          $sum['leave']++;   break;
        case 'absent':         $sum['absent']++;  break;
        case 'holiday':
        case 'weekly_off':     $sum['off']++;     break;
    }
    $sum['minutes'] += $mins;

    $days[$d] = [
        'rec'      => $rec,
        'status'   => $status,
        'minutes'  => $mins,
        'off'      => $off,
        'holiday'  => $holidays[$d] ?? null,
    ];
}

// Cell colour class per status (mirrors att_status_badge colours).
$status_cell_class = static function (string $status): string {
    return match ($status) {
        'present'                     => 'cal-present',
        'late_in', 'late_and_early'   => 'cal-late',
        'early_out'                   => 'cal-early',
        'incomplete'                  => 'cal-incomplete',
        'absent'                      => 'cal-absent',
        'upcoming'                    => 'cal-upcoming',
        'leave'                       => 'cal-leave',
        'holiday', 'weekly_off'       => 'cal-off',
        default                       => '',
    };
};

$weekday_abbr = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
?>

<style>
    /* Calendar grid — Monday-first week. */
    .att-cal { width:100%; border-collapse:collapse; table-layout:fixed; }
    .att-cal th { background:#f8f9fa; border:1px solid #e5e7eb; padding:.4rem; font-size:.75rem; text-transform:uppercase; color:#6c757d; letter-spacing:.03em; }
    .att-cal td { border:1px solid #e5e7eb; vertical-align:top; height:78px; padding:0; }
    .att-cal td.empty { background:#fafafa; }
    .cal-cell { display:block; height:100%; padding:.3rem .35rem; text-decoration:none; color:inherit; }
    .cal-cell:hover { outline:2px solid #0d6efd; outline-offset:-2px; }
    .cal-day { font-weight:600; font-size:.85rem; }
    .cal-io { font-size:.72rem; line-height:1.2; margin-top:.15rem; }
    .cal-io .io-out { color:#495057; }
    .cal-hh { font-size:.7rem; font-weight:600; color:#0a58ca; }
    .cal-tag { font-size:.68rem; font-weight:700; text-transform:uppercase; }
    .cal-present    { background:#e8f5e9; }
    .cal-late       { background:#fff3cd; }
    .cal-early      { background:#ffe8cc; }
    .cal-incomplete { background:#e7f5ff; }
    .cal-absent     { background:#f8d7da; }
    .cal-leave      { background:#cfe2ff; }
    .cal-off        { background:#f1f3f5; color:#868e96; }
    .cal-upcoming   { background:#ffffff; color:#adb5bd; }
    .cal-today .cal-day { color:#0d6efd; }
    .cal-today { box-shadow:inset 0 0 0 2px #0d6efd; }
    @media print {
        .no-print { display:none !important; }
        .att-cal td { height:64px; }
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <nav aria-label="breadcrumb" class="no-print">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php?<?= h($report_qs) ?>">Staff Attendance</a></li>
            <li class="breadcrumb-item active"><?= h($member['full_name']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 no-print">
        <a href="<?= APP_URL ?>/staff-attendance/index.php?<?= h($report_qs) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to report</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i> Print</button>
    </div>
</div>

<?= flash_show() ?>

<div class="card mb-3" style="border-radius:12px;">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-user-clock me-2 text-primary"></i><?= h($member['full_name']) ?></h1>
            <div class="text-muted small">
                <?= h($member['employee_id'] ?? '—') ?>
                <?php if (!empty($member['dept_name'])): ?> &middot; <?= h($member['dept_name']) ?><?php endif; ?>
                &middot; Schedule <?= h($sched['start_time']) ?>–<?= h($sched['close_time']) ?>
                <?php if (!empty($sched['custom'])): ?><span class="badge bg-info text-dark ms-1">override</span><?php endif; ?>
            </div>
        </div>
        <div class="btn-group no-print" role="group" aria-label="Month navigation">
            <a href="?user_id=<?= $user_id ?>&month=<?= urlencode($prev_month) ?>&report=<?= h($report) ?>&dept=<?= $dept_id ?>&q=<?= urlencode($search) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-chevron-left"></i></a>
            <span class="btn btn-light btn-sm disabled fw-semibold" style="min-width:130px;"><?= h($month_label) ?></span>
            <a href="?user_id=<?= $user_id ?>&month=<?= urlencode($next_month) ?>&report=<?= h($report) ?>&dept=<?= $dept_id ?>&q=<?= urlencode($search) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
</div>

<!-- Summary strip -->
<div class="row g-2 mb-3">
    <?php
    $tiles = [
        ['Working Days', (int)$sum['working_days'], 'bg-light text-dark border'],
        ['Present',      (int)$sum['present'],      'bg-success'],
        ['Late In',      (int)$sum['late'],         'bg-warning text-dark'],
        ['Early Out',    (int)$sum['early'],        'bg-warning text-dark'],
        ['On Leave',     (int)$sum['leave'],        'bg-primary'],
        ['Absent',       (int)$sum['absent'],       'bg-danger'],
    ];
    foreach ($tiles as [$lbl, $val, $cls]): ?>
    <div class="col-6 col-md">
        <div class="card text-center h-100" style="border-radius:10px;">
            <div class="card-body py-2 px-1">
                <div class="badge <?= $cls ?> mb-1"><?= $val ?></div>
                <div class="small text-muted"><?= $lbl ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-6 col-md">
        <div class="card text-center h-100" style="border-radius:10px;">
            <div class="card-body py-2 px-1">
                <div class="fw-bold text-primary"><?= h(att_format_hours((int)$sum['minutes'])) ?></div>
                <div class="small text-muted">Total Hours</div>
            </div>
        </div>
    </div>
</div>

<!-- Calendar -->
<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-alt me-2 text-muted"></i>Calendar — <?= h($month_label) ?></h6>
        <div class="small text-muted d-none d-md-block">
            <span class="badge cal-present">Present</span>
            <span class="badge cal-late text-dark">Late</span>
            <span class="badge cal-early text-dark">Early</span>
            <span class="badge cal-leave text-dark">Leave</span>
            <span class="badge cal-absent text-dark">Absent</span>
            <span class="badge cal-off">Off</span>
        </div>
    </div>
    <div class="card-body p-2">
        <table class="att-cal">
            <thead>
                <tr><?php foreach ($weekday_abbr as $wd): ?><th><?= $wd ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php
            // Leading blanks: ISO weekday of the 1st (1=Mon … 7=Sun).
            $lead = (int)date('N', strtotime($from));
            $col  = 1;
            echo '<tr>';
            for ($i = 1; $i < $lead; $i++) { echo '<td class="empty"></td>'; $col++; }

            foreach ($dates as $d) {
                if ($col > 7) { echo '</tr><tr>'; $col = 1; }
                $info   = $days[$d];
                $rec    = $info['rec'];
                $status = $info['status'];
                $cls    = $status_cell_class($status);
                $is_today = ($d === date('Y-m-d')) ? ' cal-today' : '';
                $link = APP_URL . '/staff-attendance/entry.php?user_id=' . $user_id . '&date=' . urlencode($d);
                $tag  = '';
                if (in_array($status, ['holiday', 'weekly_off'], true)) {
                    $tag = '<span class="cal-tag">' . ($info['holiday'] ? 'Holiday' : 'Off') . '</span>';
                } elseif ($status === 'absent') {
                    $tag = '<span class="cal-tag text-danger">Absent</span>';
                } elseif ($status === 'leave') {
                    $tag = '<span class="cal-tag text-primary">Leave</span>';
                }

                echo '<td class="' . $cls . $is_today . '">';
                echo $can_edit
                    ? '<a class="cal-cell" href="' . $link . '" title="' . h(att_status_label($status)) . ' — click to edit">'
                    : '<span class="cal-cell">';
                echo '<span class="cal-day">' . (int)date('j', strtotime($d)) . '</span>';
                if ($rec && !empty($rec['in_time'])) {
                    echo '<div class="cal-io">'
                       . '<div>' . h(att_display_time($rec['in_time'] ?? null)) . '</div>'
                       . '<div class="io-out">' . h(att_display_time($rec['out_time'] ?? null)) . '</div>'
                       . '</div>';
                    if ($info['minutes'] > 0) echo '<div class="cal-hh">' . h(att_format_hours((int)$info['minutes'])) . '</div>';
                    if (in_array($status, ['late_in', 'late_and_early'], true)) echo '<span class="cal-tag text-warning">Late</span>';
                    elseif ($status === 'early_out') echo '<span class="cal-tag text-warning">Early</span>';
                    elseif ($status === 'incomplete') echo '<span class="cal-tag text-info">No out</span>';
                } elseif ($tag !== '') {
                    echo '<div class="cal-io">' . $tag . '</div>';
                }
                echo $can_edit ? '</a>' : '</span>';
                echo '</td>';
                $col++;
            }
            while ($col <= 7) { echo '<td class="empty"></td>'; $col++; }
            echo '</tr>';
            ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Day-by-day table -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Daily Detail</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Date</th>
                        <th>Day</th>
                        <th>In Time</th>
                        <th>Out Time</th>
                        <th>Total Working Hours</th>
                        <th>Status</th>
                        <?php if ($can_edit): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dates as $d):
                    $info = $days[$d];
                    $rec  = $info['rec'];
                ?>
                    <tr>
                        <td class="px-3 small"><?= h(date('d M Y', strtotime($d))) ?></td>
                        <td class="small text-muted"><?= h(date('D', strtotime($d))) ?></td>
                        <td><?= h(att_display_time($rec['in_time'] ?? null)) ?></td>
                        <td><?= h(att_display_time($rec['out_time'] ?? null)) ?></td>
                        <td><?= h(att_format_hours((int)$info['minutes'])) ?></td>
                        <td><?php
                            echo $info['holiday']
                                ? '<span class="badge bg-secondary">Holiday: ' . h($info['holiday']) . '</span>'
                                : att_status_badge($info['status']);
                        ?></td>
                        <?php if ($can_edit): ?>
                        <td class="text-end pe-3 no-print">
                            <a href="<?= APP_URL ?>/staff-attendance/entry.php?user_id=<?= $user_id ?>&date=<?= urlencode($d) ?>"
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
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
