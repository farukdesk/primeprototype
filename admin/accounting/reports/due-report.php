<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Student Due Report';
$currency   = acc_currency();
$db         = db();

// ── Filters ───────────────────────────────────────────────────────────────────
$f_dept      = (int)($_GET['dept_id']  ?? 0);
$f_program   = trim($_GET['program']   ?? '');
$f_batch     = trim($_GET['batch']     ?? '');
$f_status    = trim($_GET['status']    ?? '');
$f_min_due   = max(0.0, (float)($_GET['min_due'] ?? 0));
$active_tab  = trim($_GET['tab']       ?? 'students');

// ── Load filter options (departments, programs, batches) ──────────────────────
$depts = $db->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name'
)->fetchAll();

$programs_list = $db->query(
    'SELECT DISTINCT program_name FROM sfp_packages ORDER BY program_name'
)->fetchAll(PDO::FETCH_COLUMN);

$batches_list = $db->query(
    'SELECT DISTINCT admitted_semester FROM students
     WHERE admitted_semester IS NOT NULL AND admitted_semester != ""
     ORDER BY admitted_semester DESC'
)->fetchAll(PDO::FETCH_COLUMN);

// ── Main data query ────────────────────────────────────────────────────────────
// Fetches one row per student-package, with aggregated semester fees and paid amounts.

$where  = ['1=1'];
$params = [];

if ($f_dept) {
    $where[]  = 's.dept_id = ?';
    $params[] = $f_dept;
}
if ($f_program !== '') {
    $where[]  = 'p.program_name = ?';
    $params[] = $f_program;
}
if ($f_batch !== '') {
    $where[]  = 's.admitted_semester = ?';
    $params[] = $f_batch;
}
if ($f_status !== '') {
    $where[]  = 's.status = ?';
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT
         p.id                       AS package_id,
         p.student_id,
         s.student_id               AS student_sid,
         s.full_name,
         s.admitted_semester,
         s.status                   AS student_status,
         s.phone,
         d.name                     AS dept_name,
         d.code                     AS dept_code,
         p.program_name,
         p.reg_fee_per_semester,
         p.form_id_fee,
         p.admission_fees,
         p.fixed_institutional_fees,
         p.english_course_fee,
         p.note,
         COALESCE(sf_agg.num_sems,     0) AS num_sems,
         COALESCE(sf_agg.tuition_total, 0) AS tuition_total,
         COALESCE(pay_agg.total_paid,   0) AS total_paid
     FROM sfp_packages p
     JOIN students s ON s.id = p.student_id
     LEFT JOIN dept_departments d ON d.id = s.dept_id
     LEFT JOIN (
         SELECT package_id,
                COUNT(*)                       AS num_sems,
                COALESCE(SUM(tuition_payable), 0) AS tuition_total
         FROM sfp_semester_fees
         GROUP BY package_id
     ) sf_agg ON sf_agg.package_id = p.id
     LEFT JOIN (
         SELECT package_id, COALESCE(SUM(amount), 0) AS total_paid
         FROM sfp_payments
         GROUP BY package_id
     ) pay_agg ON pay_agg.package_id = p.id
     WHERE $where_sql
     ORDER BY d.name, p.program_name, s.admitted_semester DESC, s.full_name"
);
$stmt->execute($params);
$raw_rows = $stmt->fetchAll();

// ── Compute outstanding balance per row ───────────────────────────────────────
$default_form_id_fee = acc_student_form_id_total_fee(); // 1000 BDT
$erp_marker          = OLD_ERP_SETTLEMENT_MARKER;

$rows = [];
foreach ($raw_rows as $r) {
    $form_id_fee = ((float)$r['form_id_fee'] > 0) ? (float)$r['form_id_fee'] : $default_form_id_fee;
    $has_old_erp = stripos((string)$r['note'], $erp_marker) !== false;

    $num_sems = (int)$r['num_sems'];
    $total_due = (float)$r['admission_fees']
               + $form_id_fee
               + (float)$r['reg_fee_per_semester'] * $num_sems
               + (float)$r['fixed_institutional_fees']
               + (float)$r['english_course_fee']
               + (float)$r['tuition_total'];

    $erp_admission_credit  = $has_old_erp ? ((float)$r['admission_fees'] + $form_id_fee) : 0.0;
    $erp_reg_credit        = ($has_old_erp && $num_sems > 0)
                             ? (float)$r['reg_fee_per_semester']
                             : 0.0;

    $total_paid      = (float)$r['total_paid'] + $erp_admission_credit + $erp_reg_credit;
    $outstanding     = max(0.0, $total_due - $total_paid);
    $paid_percentage = $total_due > 0 ? min(100, round($total_paid / $total_due * 100)) : 100;

    if ($outstanding < $f_min_due) {
        continue;
    }

    $rows[] = $r + [
        'total_due'       => round($total_due, 2),
        'total_paid'      => round($total_paid, 2),
        'outstanding'     => round($outstanding, 2),
        'paid_percentage' => $paid_percentage,
    ];
}

// ── KPI totals ────────────────────────────────────────────────────────────────
$kpi_total_students  = count($rows);
$kpi_total_due       = array_sum(array_column($rows, 'total_due'));
$kpi_total_paid      = array_sum(array_column($rows, 'total_paid'));
$kpi_total_out       = array_sum(array_column($rows, 'outstanding'));
$kpi_students_clear  = count(array_filter($rows, fn($r) => $r['outstanding'] == 0));
$kpi_students_due    = $kpi_total_students - $kpi_students_clear;

// ── Group by batch ────────────────────────────────────────────────────────────
$by_batch = [];
foreach ($rows as $r) {
    $key = $r['admitted_semester'] ?: '(No Batch)';
    if (!isset($by_batch[$key])) {
        $by_batch[$key] = ['batch' => $key, 'students' => 0, 'total_due' => 0.0, 'total_paid' => 0.0, 'outstanding' => 0.0];
    }
    $by_batch[$key]['students']++;
    $by_batch[$key]['total_due']   += $r['total_due'];
    $by_batch[$key]['total_paid']  += $r['total_paid'];
    $by_batch[$key]['outstanding'] += $r['outstanding'];
}
usort($by_batch, fn($a, $b) => $b['outstanding'] <=> $a['outstanding']);

// ── Group by program ──────────────────────────────────────────────────────────
$by_program = [];
foreach ($rows as $r) {
    $key = $r['program_name'] ?: '(Unknown Program)';
    if (!isset($by_program[$key])) {
        $by_program[$key] = ['program' => $key, 'students' => 0, 'total_due' => 0.0, 'total_paid' => 0.0, 'outstanding' => 0.0];
    }
    $by_program[$key]['students']++;
    $by_program[$key]['total_due']   += $r['total_due'];
    $by_program[$key]['total_paid']  += $r['total_paid'];
    $by_program[$key]['outstanding'] += $r['outstanding'];
}
usort($by_program, fn($a, $b) => $b['outstanding'] <=> $a['outstanding']);

// ── Group by department ───────────────────────────────────────────────────────
$by_dept = [];
foreach ($rows as $r) {
    $key = $r['dept_name'] ?: '(Unknown Department)';
    if (!isset($by_dept[$key])) {
        $by_dept[$key] = ['dept' => $key, 'students' => 0, 'total_due' => 0.0, 'total_paid' => 0.0, 'outstanding' => 0.0];
    }
    $by_dept[$key]['students']++;
    $by_dept[$key]['total_due']   += $r['total_due'];
    $by_dept[$key]['total_paid']  += $r['total_paid'];
    $by_dept[$key]['outstanding'] += $r['outstanding'];
}
usort($by_dept, fn($a, $b) => $b['outstanding'] <=> $a['outstanding']);

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ── Page header ── -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-exclamation-circle me-2 text-danger"></i>Student Due Report</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Due Report</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-print me-1"></i> Print
        </button>
    </div>
</div>

<!-- ── Filters ── -->
<div class="card border-0 shadow-sm mb-3 no-print">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= h($active_tab) ?>">

            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Department</label>
                <select name="dept_id" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($depts as $dep): ?>
                    <option value="<?= (int)$dep['id'] ?>" <?= $f_dept === (int)$dep['id'] ? 'selected' : '' ?>>
                        <?= h($dep['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Program</label>
                <select name="program" class="form-select form-select-sm">
                    <option value="">All Programs</option>
                    <?php foreach ($programs_list as $prog): ?>
                    <option value="<?= h($prog) ?>" <?= $f_program === $prog ? 'selected' : '' ?>>
                        <?= h($prog) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Batch (Semester)</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($batches_list as $b): ?>
                    <option value="<?= h($b) ?>" <?= $f_batch === $b ? 'selected' : '' ?>>
                        <?= h($b) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Student Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (['Active', 'Inactive', 'Graduated', 'Dropped'] as $st): ?>
                    <option value="<?= h($st) ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= h($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1">
                <label class="form-label small fw-semibold mb-1">Min Due (<?= h($currency) ?>)</label>
                <input type="number" name="min_due" class="form-control form-control-sm"
                       value="<?= $f_min_due > 0 ? h($f_min_due) : '' ?>" min="0" step="1" placeholder="0">
            </div>

            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync me-1"></i> Generate</button>
                <a href="?" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── KPI Cards ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm h-100 text-center">
            <div class="card-body p-3">
                <div class="fs-4 fw-bold text-primary"><?= number_format($kpi_total_students) ?></div>
                <div class="text-muted small">Total Students</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm h-100 text-center">
            <div class="card-body p-3">
                <div class="fs-4 fw-bold text-danger"><?= number_format($kpi_students_due) ?></div>
                <div class="text-muted small">With Due</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm h-100 text-center">
            <div class="card-body p-3">
                <div class="fs-4 fw-bold text-success"><?= number_format($kpi_students_clear) ?></div>
                <div class="text-muted small">Cleared</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm h-100 text-center">
            <div class="card-body p-3">
                <div class="fw-bold" style="font-size:1.05rem"><?= number_format($kpi_total_due, 0) ?></div>
                <div class="text-muted small">Total Obligation (<?= h($currency) ?>)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm h-100 text-center">
            <div class="card-body p-3">
                <div class="fw-bold text-success" style="font-size:1.05rem"><?= number_format($kpi_total_paid, 0) ?></div>
                <div class="text-muted small">Total Collected (<?= h($currency) ?>)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm h-100 text-center border-danger">
            <div class="card-body p-3">
                <div class="fw-bold text-danger" style="font-size:1.05rem"><?= number_format($kpi_total_out, 0) ?></div>
                <div class="text-muted small">Total Outstanding (<?= h($currency) ?>)</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Print header (hidden on screen) ── -->
<div class="d-none d-print-block mb-3">
    <div class="text-center mb-2">
        <?php $logo = acc_university_logo_url(); if ($logo): ?>
        <img src="<?= h($logo) ?>" alt="Logo" style="height:50px;width:auto;margin-bottom:6px"><br>
        <?php endif; ?>
        <strong style="font-size:14pt">Student Due Report</strong><br>
        <span style="font-size:9pt;color:#555"><?= h(acc_university_address()) ?></span><br>
        <span style="font-size:8pt;color:#888">Generated: <?= date('d M Y, h:i A') ?></span>
        <?php if ($f_dept || $f_program !== '' || $f_batch !== '' || $f_status !== ''): ?>
        <div style="font-size:8pt;color:#666;margin-top:4px">
            Filters:
            <?php if ($f_dept):      $dn = array_column($depts, 'name', 'id')[$f_dept] ?? ''; echo h("Dept: $dn"); echo " &nbsp;"; endif; ?>
            <?php if ($f_program):   echo h("Program: $f_program"); echo " &nbsp;"; endif; ?>
            <?php if ($f_batch):     echo h("Batch: $f_batch"); echo " &nbsp;"; endif; ?>
            <?php if ($f_status):    echo h("Status: $f_status"); endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:9pt;margin-bottom:8px">
        <tr>
            <td style="border:1px solid #ccc;padding:5px;background:#f5f5f5;font-weight:bold">Total Students</td>
            <td style="border:1px solid #ccc;padding:5px"><?= number_format($kpi_total_students) ?></td>
            <td style="border:1px solid #ccc;padding:5px;background:#f5f5f5;font-weight:bold">Students with Due</td>
            <td style="border:1px solid #ccc;padding:5px;color:#c00"><?= number_format($kpi_students_due) ?></td>
            <td style="border:1px solid #ccc;padding:5px;background:#f5f5f5;font-weight:bold">Total Outstanding</td>
            <td style="border:1px solid #ccc;padding:5px;color:#c00;font-weight:bold"><?= $currency ?> <?= number_format($kpi_total_out, 2) ?></td>
        </tr>
    </table>
</div>

<!-- ── Tabs ── -->
<ul class="nav nav-tabs mb-0 no-print" id="dueTabs" role="tablist">
    <?php
    $tabs = [
        'students'  => ['<i class="fas fa-users me-1"></i>By Student',     count($rows)],
        'batch'     => ['<i class="fas fa-layer-group me-1"></i>By Batch',  count($by_batch)],
        'program'   => ['<i class="fas fa-graduation-cap me-1"></i>By Program', count($by_program)],
        'dept'      => ['<i class="fas fa-building me-1"></i>By Department',count($by_dept)],
    ];
    foreach ($tabs as $tid => [$label, $cnt]):
    ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === $tid ? 'active' : '' ?>"
                id="tab-<?= $tid ?>"
                data-bs-toggle="tab"
                data-bs-target="#pane-<?= $tid ?>"
                type="button" role="tab"
                onclick="updateTabParam('<?= $tid ?>')">
            <?= $label ?>
            <span class="badge bg-secondary bg-opacity-25 text-dark ms-1"><?= $cnt ?></span>
        </button>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content">

    <!-- ── TAB: By Student ── -->
    <div class="tab-pane fade <?= $active_tab === 'students' ? 'show active' : '' ?>" id="pane-students" role="tabpanel">
        <div class="card border-0 shadow-sm border-top-0 rounded-0 rounded-bottom">
            <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center no-print">
                <strong class="small"><?= number_format(count($rows)) ?> student(s) with due ≥ <?= $currency ?> <?= number_format($f_min_due, 0) ?></strong>
                <span class="text-danger fw-bold small">Outstanding: <?= $currency ?> <?= number_format($kpi_total_out, 2) ?></span>
            </div>
            <?php if (empty($rows)): ?>
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-check-circle fa-3x mb-3 text-success opacity-50"></i>
                <p class="mb-0 fw-semibold">No outstanding dues found for the selected filters.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small" id="tbl-students">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Department</th>
                            <th>Program</th>
                            <th>Batch</th>
                            <th>Status</th>
                            <th class="text-end">Total Due (<?= h($currency) ?>)</th>
                            <th class="text-end">Paid (<?= h($currency) ?>)</th>
                            <th class="text-end">Outstanding (<?= h($currency) ?>)</th>
                            <th class="no-print">Progress</th>
                            <th class="no-print text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                    <tr class="<?= $r['outstanding'] > 0 ? '' : 'table-success' ?>">
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($r['student_sid']) ?></td>
                        <td>
                            <?= h($r['full_name']) ?>
                            <?php if ($r['phone']): ?>
                            <br><small class="text-muted"><?= h($r['phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($r['dept_name'] ?? '—') ?></td>
                        <td><?= h($r['program_name']) ?></td>
                        <td><?= h($r['admitted_semester'] ?? '—') ?></td>
                        <td>
                            <?php
                            $status_map = [
                                'Active'    => 'bg-success',
                                'Inactive'  => 'bg-secondary',
                                'Graduated' => 'bg-info',
                                'Dropped'   => 'bg-danger',
                            ];
                            $sc = $status_map[$r['student_status']] ?? 'bg-secondary';
                            ?>
                            <span class="badge <?= $sc ?> bg-opacity-75"><?= h($r['student_status']) ?></span>
                        </td>
                        <td class="text-end"><?= number_format($r['total_due'], 2) ?></td>
                        <td class="text-end text-success fw-semibold"><?= number_format($r['total_paid'], 2) ?></td>
                        <td class="text-end <?= $r['outstanding'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                            <?= number_format($r['outstanding'], 2) ?>
                        </td>
                        <td class="no-print" style="min-width:100px">
                            <div class="progress" style="height:6px" title="<?= $r['paid_percentage'] ?>% paid">
                                <div class="progress-bar <?= $r['paid_percentage'] >= 100 ? 'bg-success' : ($r['paid_percentage'] >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                                     style="width:<?= $r['paid_percentage'] ?>%"></div>
                            </div>
                            <div class="text-muted" style="font-size:.68rem"><?= $r['paid_percentage'] ?>%</div>
                        </td>
                        <td class="no-print text-end">
                            <a href="<?= APP_URL ?>/student-accounts/statement.php?id=<?= (int)$r['package_id'] ?>"
                               class="btn btn-outline-primary btn-sm" target="_blank" title="Statement">
                                <i class="fas fa-file-invoice"></i>
                            </a>
                            <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$r['student_id'] ?>"
                               class="btn btn-outline-secondary btn-sm" title="View Student">
                                <i class="fas fa-user"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="7" class="text-end">Grand Total</td>
                            <td class="text-end"><?= number_format($kpi_total_due, 2) ?></td>
                            <td class="text-end text-success"><?= number_format($kpi_total_paid, 2) ?></td>
                            <td class="text-end text-danger"><?= number_format($kpi_total_out, 2) ?></td>
                            <td colspan="2" class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /pane-students -->

    <!-- ── TAB: By Batch ── -->
    <div class="tab-pane fade <?= $active_tab === 'batch' ? 'show active' : '' ?>" id="pane-batch" role="tabpanel">
        <div class="card border-0 shadow-sm border-top-0 rounded-0 rounded-bottom">
            <div class="card-header py-2 px-3 no-print">
                <strong class="small">Due by Admission Batch</strong>
            </div>
            <?php if (empty($by_batch)): ?>
            <div class="card-body text-center py-5 text-muted">
                <p class="mb-0">No data.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small" id="tbl-batch">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Admission Batch</th>
                            <th class="text-center">Students</th>
                            <th class="text-end">Total Due (<?= h($currency) ?>)</th>
                            <th class="text-end">Total Paid (<?= h($currency) ?>)</th>
                            <th class="text-end">Outstanding (<?= h($currency) ?>)</th>
                            <th class="no-print">Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $batch_total_due = $batch_total_paid = $batch_total_out = 0;
                    foreach ($by_batch as $i => $b):
                        $pct = $b['total_due'] > 0 ? min(100, round($b['total_paid'] / $b['total_due'] * 100)) : 100;
                        $batch_total_due  += $b['total_due'];
                        $batch_total_paid += $b['total_paid'];
                        $batch_total_out  += $b['outstanding'];
                    ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($b['batch']) ?></td>
                        <td class="text-center"><?= number_format($b['students']) ?></td>
                        <td class="text-end"><?= number_format($b['total_due'], 2) ?></td>
                        <td class="text-end text-success"><?= number_format($b['total_paid'], 2) ?></td>
                        <td class="text-end <?= $b['outstanding'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                            <?= number_format($b['outstanding'], 2) ?>
                        </td>
                        <td class="no-print" style="min-width:120px">
                            <div class="progress" style="height:8px">
                                <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                                     style="width:<?= $pct ?>%"></div>
                            </div>
                            <div class="text-muted" style="font-size:.68rem"><?= $pct ?>% paid</div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Total</td>
                            <td class="text-center"><?= number_format(array_sum(array_column($by_batch, 'students'))) ?></td>
                            <td class="text-end"><?= number_format($batch_total_due, 2) ?></td>
                            <td class="text-end text-success"><?= number_format($batch_total_paid, 2) ?></td>
                            <td class="text-end text-danger"><?= number_format($batch_total_out, 2) ?></td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /pane-batch -->

    <!-- ── TAB: By Program ── -->
    <div class="tab-pane fade <?= $active_tab === 'program' ? 'show active' : '' ?>" id="pane-program" role="tabpanel">
        <div class="card border-0 shadow-sm border-top-0 rounded-0 rounded-bottom">
            <div class="card-header py-2 px-3 no-print">
                <strong class="small">Due by Program</strong>
            </div>
            <?php if (empty($by_program)): ?>
            <div class="card-body text-center py-5 text-muted">
                <p class="mb-0">No data.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small" id="tbl-program">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Program</th>
                            <th class="text-center">Students</th>
                            <th class="text-end">Total Due (<?= h($currency) ?>)</th>
                            <th class="text-end">Total Paid (<?= h($currency) ?>)</th>
                            <th class="text-end">Outstanding (<?= h($currency) ?>)</th>
                            <th class="no-print">Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $prog_total_due = $prog_total_paid = $prog_total_out = 0;
                    foreach ($by_program as $i => $bp):
                        $pct = $bp['total_due'] > 0 ? min(100, round($bp['total_paid'] / $bp['total_due'] * 100)) : 100;
                        $prog_total_due  += $bp['total_due'];
                        $prog_total_paid += $bp['total_paid'];
                        $prog_total_out  += $bp['outstanding'];
                    ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($bp['program']) ?></td>
                        <td class="text-center"><?= number_format($bp['students']) ?></td>
                        <td class="text-end"><?= number_format($bp['total_due'], 2) ?></td>
                        <td class="text-end text-success"><?= number_format($bp['total_paid'], 2) ?></td>
                        <td class="text-end <?= $bp['outstanding'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                            <?= number_format($bp['outstanding'], 2) ?>
                        </td>
                        <td class="no-print" style="min-width:120px">
                            <div class="progress" style="height:8px">
                                <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                                     style="width:<?= $pct ?>%"></div>
                            </div>
                            <div class="text-muted" style="font-size:.68rem"><?= $pct ?>% paid</div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Total</td>
                            <td class="text-center"><?= number_format(array_sum(array_column($by_program, 'students'))) ?></td>
                            <td class="text-end"><?= number_format($prog_total_due, 2) ?></td>
                            <td class="text-end text-success"><?= number_format($prog_total_paid, 2) ?></td>
                            <td class="text-end text-danger"><?= number_format($prog_total_out, 2) ?></td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /pane-program -->

    <!-- ── TAB: By Department ── -->
    <div class="tab-pane fade <?= $active_tab === 'dept' ? 'show active' : '' ?>" id="pane-dept" role="tabpanel">
        <div class="card border-0 shadow-sm border-top-0 rounded-0 rounded-bottom">
            <div class="card-header py-2 px-3 no-print">
                <strong class="small">Due by Department</strong>
            </div>
            <?php if (empty($by_dept)): ?>
            <div class="card-body text-center py-5 text-muted">
                <p class="mb-0">No data.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small" id="tbl-dept">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Department</th>
                            <th class="text-center">Students</th>
                            <th class="text-end">Total Due (<?= h($currency) ?>)</th>
                            <th class="text-end">Total Paid (<?= h($currency) ?>)</th>
                            <th class="text-end">Outstanding (<?= h($currency) ?>)</th>
                            <th class="no-print">Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $dept_total_due = $dept_total_paid = $dept_total_out = 0;
                    foreach ($by_dept as $i => $bd):
                        $pct = $bd['total_due'] > 0 ? min(100, round($bd['total_paid'] / $bd['total_due'] * 100)) : 100;
                        $dept_total_due  += $bd['total_due'];
                        $dept_total_paid += $bd['total_paid'];
                        $dept_total_out  += $bd['outstanding'];
                    ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($bd['dept']) ?></td>
                        <td class="text-center"><?= number_format($bd['students']) ?></td>
                        <td class="text-end"><?= number_format($bd['total_due'], 2) ?></td>
                        <td class="text-end text-success"><?= number_format($bd['total_paid'], 2) ?></td>
                        <td class="text-end <?= $bd['outstanding'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                            <?= number_format($bd['outstanding'], 2) ?>
                        </td>
                        <td class="no-print" style="min-width:120px">
                            <div class="progress" style="height:8px">
                                <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                                     style="width:<?= $pct ?>%"></div>
                            </div>
                            <div class="text-muted" style="font-size:.68rem"><?= $pct ?>% paid</div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Total</td>
                            <td class="text-center"><?= number_format(array_sum(array_column($by_dept, 'students'))) ?></td>
                            <td class="text-end"><?= number_format($dept_total_due, 2) ?></td>
                            <td class="text-end text-success"><?= number_format($dept_total_paid, 2) ?></td>
                            <td class="text-end text-danger"><?= number_format($dept_total_out, 2) ?></td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /pane-dept -->

</div><!-- /tab-content -->

<!-- ── Print-only: grouped summary tables ── -->
<div class="d-none d-print-block mt-4">
    <?php if (!empty($by_dept)): ?>
    <h6 style="font-size:10pt;font-weight:bold;margin-bottom:4px;border-bottom:1px solid #ccc">Due by Department</h6>
    <table style="width:100%;border-collapse:collapse;font-size:8.5pt;margin-bottom:12px">
        <thead><tr style="background:#eee">
            <th style="border:1px solid #ccc;padding:4px">#</th>
            <th style="border:1px solid #ccc;padding:4px">Department</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:center">Students</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:right">Total Due</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:right">Paid</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:right">Outstanding</th>
        </tr></thead>
        <tbody>
        <?php foreach ($by_dept as $i => $bd): ?>
        <tr>
            <td style="border:1px solid #ccc;padding:4px"><?= $i+1 ?></td>
            <td style="border:1px solid #ccc;padding:4px"><?= h($bd['dept']) ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:center"><?= $bd['students'] ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:right"><?= number_format($bd['total_due'],2) ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:right"><?= number_format($bd['total_paid'],2) ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:right;font-weight:bold;color:#c00"><?= number_format($bd['outstanding'],2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($by_program)): ?>
    <h6 style="font-size:10pt;font-weight:bold;margin-bottom:4px;border-bottom:1px solid #ccc">Due by Program</h6>
    <table style="width:100%;border-collapse:collapse;font-size:8.5pt;margin-bottom:12px">
        <thead><tr style="background:#eee">
            <th style="border:1px solid #ccc;padding:4px">#</th>
            <th style="border:1px solid #ccc;padding:4px">Program</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:center">Students</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:right">Total Due</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:right">Paid</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:right">Outstanding</th>
        </tr></thead>
        <tbody>
        <?php foreach ($by_program as $i => $bp): ?>
        <tr>
            <td style="border:1px solid #ccc;padding:4px"><?= $i+1 ?></td>
            <td style="border:1px solid #ccc;padding:4px"><?= h($bp['program']) ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:center"><?= $bp['students'] ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:right"><?= number_format($bp['total_due'],2) ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:right"><?= number_format($bp['total_paid'],2) ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:right;font-weight:bold;color:#c00"><?= number_format($bp['outstanding'],2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($by_batch)): ?>
    <h6 style="font-size:10pt;font-weight:bold;margin-bottom:4px;border-bottom:1px solid #ccc">Due by Batch</h6>
    <table style="width:100%;border-collapse:collapse;font-size:8.5pt;margin-bottom:12px">
        <thead><tr style="background:#eee">
            <th style="border:1px solid #ccc;padding:4px">#</th>
            <th style="border:1px solid #ccc;padding:4px">Admission Batch</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:center">Students</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:right">Total Due</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:right">Paid</th>
            <th style="border:1px solid #ccc;padding:4px;text-align:right">Outstanding</th>
        </tr></thead>
        <tbody>
        <?php foreach ($by_batch as $i => $b): ?>
        <tr>
            <td style="border:1px solid #ccc;padding:4px"><?= $i+1 ?></td>
            <td style="border:1px solid #ccc;padding:4px"><?= h($b['batch']) ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:center"><?= $b['students'] ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:right"><?= number_format($b['total_due'],2) ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:right"><?= number_format($b['total_paid'],2) ?></td>
            <td style="border:1px solid #ccc;padding:4px;text-align:right;font-weight:bold;color:#c00"><?= number_format($b['outstanding'],2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<style>
@media print {
    #sidebar, #topbar, .no-print, nav[aria-label="breadcrumb"] { display: none !important; }
    #main-wrapper { margin-left: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
    .tab-pane { display: block !important; opacity: 1 !important; }
    #pane-batch, #pane-program, #pane-dept { display: none !important; }
    .rounded-0.rounded-bottom { border-radius: 0 !important; }
}
@page { size: A4 portrait; margin: 12mm; }
</style>

<script>
function updateTabParam(tabId) {
    var url = new URL(window.location.href);
    url.searchParams.set('tab', tabId);
    history.replaceState(null, '', url.toString());
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
