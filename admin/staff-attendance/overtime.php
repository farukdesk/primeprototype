<?php
/**
 * Staff Attendance – Overtime report & settings.
 *
 * Settings (admins): OT start time, no-OT grace window, daily cap, and the
 * list of eligible designations with their hourly rates (Tk). Designations
 * marked "actual hours" (e.g. Driver) are exempt from the daily cap.
 *
 * Report: a From–To date range (optionally filtered to one designation) with
 * one row per eligible staff member showing OT days, total OT hours, rate and
 * the payable amount, plus a per-day breakdown. Exports to PDF.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/overtime-helpers.php';

$page_title = 'Staff Overtime';

// ── Save settings ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $start = att_normalize_time($_POST['ot_start_time'] ?? '') ?? ATT_OT_START;
    $thr   = max(0, min(240, (int)($_POST['ot_threshold'] ?? ATT_OT_THRESHOLD_MIN)));
    $cap   = max(0, min(720, (int)($_POST['ot_cap'] ?? ATT_OT_CAP_MIN)));

    $names = (array)($_POST['d_name'] ?? []);
    $on    = (array)($_POST['d_on'] ?? []);
    $rates = (array)($_POST['d_rate'] ?? []);
    $uncap = (array)($_POST['d_uncapped'] ?? []);

    $designations = [];
    $seen         = [];
    foreach ($names as $i => $name) {
        $name = trim(preg_replace('/\s+/', ' ', (string)$name));
        if ($name === '' || empty($on[$i])) continue;
        $k = att_ot_norm($name);
        if (isset($seen[$k])) continue;
        $seen[$k]       = true;
        $designations[] = [
            'name'     => $name,
            'rate'     => max(0, round((float)($rates[$i] ?? 0), 2)),
            'uncapped' => !empty($uncap[$i]),
        ];
    }

    att_ot_save_config([
        'start_time'        => $start,
        'threshold_minutes' => $thr,
        'cap_minutes'       => $cap,
        'designations'      => $designations,
    ]);
    log_change('staff-attendance', 'UPDATE', null, 'Overtime settings', null, null,
        "start=$start, grace=$thr" . "m, cap=$cap" . 'm, designations=' . count($designations));
    flash_set('success', 'Overtime settings saved.');
    redirect(APP_URL . '/staff-attendance/overtime.php');
}

$cfg = att_ot_config();

// ── Report filters ───────────────────────────────────────────────────────────
$from  = att_normalize_date($_GET['from'] ?? date('Y-m-01'));
$to    = att_normalize_date($_GET['to']   ?? date('Y-m-d'));
if ($from > $to) { [$from, $to] = [$to, $from]; }
if (strtotime($to) - strtotime($from) > 366 * 86400) {
    $to = date('Y-m-d', strtotime($from . ' +366 days'));
}
$desig = trim($_GET['designation'] ?? '');

$report       = att_ot_report($from, $to, $cfg, $desig);
$sum_minutes  = array_sum(array_column($report, 'ot_minutes'));
$sum_amount   = array_sum(array_column($report, 'amount'));
$range_label  = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));

$pdf_url = APP_URL . '/staff-attendance/overtime-pdf.php?' . http_build_query([
    'from' => $from, 'to' => $to, 'designation' => $desig,
]);

$settings_rows = att_ot_settings_rows($cfg);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-business-time me-2 text-primary"></i>Staff Overtime</h1>
        <p class="text-muted mb-0 small">Overtime after <?= h(date('g:i A', strtotime($cfg['start_time']))) ?> for eligible designations, with per-designation hourly rates.</p>
    </div>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Overtime</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<!-- ── Overtime rules ── -->
<div class="card mb-3" style="border-radius:12px;border-left:4px solid #0d6efd;">
    <div class="card-body py-3">
        <h6 class="fw-semibold mb-2"><i class="fas fa-scale-balanced me-2 text-primary"></i>Overtime Rules</h6>
        <ul class="small mb-0 ps-3">
            <li>Overtime counts only <strong>after <?= h(date('g:i A', strtotime($cfg['start_time']))) ?></strong>.</li>
            <li>Leaving within the first <strong><?= (int)$cfg['threshold_minutes'] ?> minutes</strong> (by <?= h(date('g:i A', strtotime($cfg['start_time']) + $cfg['threshold_minutes'] * 60)) ?>) earns <strong>no overtime</strong> for that day.</li>
            <li>Leaving later counts overtime <strong>from <?= h(date('g:i A', strtotime($cfg['start_time']))) ?></strong>, not from the end of the grace window.</li>
            <li>Daily maximum: <strong><?= h(att_format_hours((int)$cfg['cap_minutes'])) ?></strong> — except designations marked <em>actual hours</em> (e.g. Driver), which are paid for the real time worked.</li>
            <li>Weekend / holiday work is <strong>not</strong> overtime by itself; only time after <?= h(date('g:i A', strtotime($cfg['start_time']))) ?> counts, under the same rules.</li>
        </ul>
    </div>
</div>

<!-- ── Settings (collapsible) ── -->
<details class="mb-4" <?= $_SERVER['REQUEST_METHOD'] === 'GET' && empty($report) && empty($_GET) ? 'open' : '' ?>>
    <summary class="text-muted small mb-2" style="cursor:pointer"><i class="fas fa-gear me-1"></i><strong>Overtime Settings</strong> — eligible designations &amp; hourly rates (click to show/hide)</summary>
    <div class="card" style="border-radius:12px;">
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small mb-1">Overtime Starts After</label>
                        <input type="time" name="ot_start_time" class="form-control" value="<?= h($cfg['start_time']) ?>" required>
                        <div class="form-text">Only time after this counts as overtime (default 5:00 PM).</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small mb-1">No-Overtime Grace Window (minutes)</label>
                        <input type="number" name="ot_threshold" class="form-control" min="0" max="240" value="<?= (int)$cfg['threshold_minutes'] ?>">
                        <div class="form-text">Leaving within this window after the start time earns no overtime.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small mb-1">Daily Maximum (minutes)</label>
                        <input type="number" name="ot_cap" class="form-control" min="0" max="720" value="<?= (int)$cfg['cap_minutes'] ?>">
                        <div class="form-text">180 = 3 hours. Ignored for designations marked “actual hours”.</div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:60px;">Eligible</th>
                                <th>Designation</th>
                                <th style="width:180px;">Rate (Tk / hour)</th>
                                <th style="width:220px;">Actual hours (no daily cap)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 0; foreach ($settings_rows as $row): ?>
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input" name="d_on[<?= $i ?>]" value="1" <?= $row['on'] ? 'checked' : '' ?>>
                                    <input type="hidden" name="d_name[<?= $i ?>]" value="<?= h($row['name']) ?>">
                                </td>
                                <td class="fw-semibold"><?= h($row['name']) ?></td>
                                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="d_rate[<?= $i ?>]" value="<?= h(number_format((float)$row['rate'], 2, '.', '')) ?>"></td>
                                <td>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="d_uncapped[<?= $i ?>]" value="1" id="uc<?= $i ?>" <?= $row['uncapped'] ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="uc<?= $i ?>">Pay actual hours worked (Driver rule)</label>
                                    </div>
                                </td>
                            </tr>
                            <?php $i++; endforeach; ?>
                            <?php for ($x = 0; $x < 2; $x++): ?>
                            <tr class="table-light">
                                <td class="text-center"><input type="checkbox" class="form-check-input" name="d_on[<?= $i ?>]" value="1"></td>
                                <td><input type="text" class="form-control form-control-sm" name="d_name[<?= $i ?>]" placeholder="Add another designation…"></td>
                                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm" name="d_rate[<?= $i ?>]" value="0.00"></td>
                                <td>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="d_uncapped[<?= $i ?>]" value="1" id="uc<?= $i ?>">
                                        <label class="form-check-label small" for="uc<?= $i ?>">Pay actual hours worked</label>
                                    </div>
                                </td>
                            </tr>
                            <?php $i++; endfor; ?>
                        </tbody>
                    </table>
                </div>
                <button class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Overtime Settings</button>
            </form>
        </div>
    </div>
</details>

<!-- ── Report filters ── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold small mb-1">From – To</label>
                <div class="input-group">
                    <input type="date" name="from" class="form-control" value="<?= h($from) ?>" title="From date">
                    <input type="date" name="to"   class="form-control" value="<?= h($to) ?>"   title="To date">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Designation</label>
                <select name="designation" class="form-select">
                    <option value="">All Eligible Designations</option>
                    <?php foreach ($cfg['designations'] as $d): ?>
                    <option value="<?= h($d['name']) ?>" <?= att_ot_norm($desig) === att_ot_norm($d['name']) && $desig !== '' ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Apply</button>
                <a href="<?= APP_URL ?>/staff-attendance/overtime.php" class="btn btn-secondary"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- ── Report ── -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-stopwatch me-2 text-muted"></i>Overtime Report — <?= h($range_label) ?>
            <span class="badge bg-secondary ms-1"><?= count($report) ?> staff</span>
        </h6>
        <a href="<?= h($pdf_url) ?>" class="btn btn-outline-danger btn-sm" target="_blank" rel="noopener">
            <i class="fas fa-file-pdf me-1"></i> Export PDF
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Staff Name</th>
                        <th>Emp ID</th>
                        <th>Designation</th>
                        <th class="text-center">OT Days</th>
                        <th class="text-center">Total OT Hours</th>
                        <th class="text-end">Rate (Tk/hr)</th>
                        <th class="text-end">Amount (Tk)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No overtime found for the selected range. Check that eligible designations and rates are set in the Overtime Settings above.</td></tr>
                    <?php else: $sn = 0; foreach ($report as $r): $sn++; ?>
                    <tr>
                        <td><?= $sn ?></td>
                        <td class="fw-semibold">
                            <?= h($r['full_name']) ?>
                            <details class="small text-muted">
                                <summary style="cursor:pointer">day-by-day</summary>
                                <?php foreach ($r['days'] as $d => $m): ?>
                                <div><?= h(date('d M Y (D)', strtotime($d))) ?> — <?= h(att_format_hours($m)) ?></div>
                                <?php endforeach; ?>
                            </details>
                        </td>
                        <td><?= h($r['employee_id'] !== '' ? $r['employee_id'] : '—') ?></td>
                        <td>
                            <?= h($r['designation']) ?>
                            <?php if ($r['uncapped']): ?><span class="badge bg-info text-dark ms-1">actual hours</span><?php endif; ?>
                        </td>
                        <td class="text-center"><?= (int)$r['ot_days'] ?></td>
                        <td class="text-center fw-semibold"><?= h(att_format_hours((int)$r['ot_minutes'])) ?></td>
                        <td class="text-end"><?= h(att_ot_money((float)$r['rate'])) ?></td>
                        <td class="text-end fw-bold text-success"><?= h(att_ot_money((float)$r['amount'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-light fw-bold">
                        <td colspan="5" class="text-end">Total</td>
                        <td class="text-center"><?= h(att_format_hours((int)$sum_minutes)) ?></td>
                        <td></td>
                        <td class="text-end text-success"><?= h(att_ot_money((float)$sum_amount)) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
