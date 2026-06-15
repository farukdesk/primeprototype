<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Student Due Report';
$currency   = acc_currency();
$db         = db();
$payment_timeline_limit = 500;
$format_enum_label = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));

// ── Filters ───────────────────────────────────────────────────────────────────
$f_dept      = (int)($_GET['dept_id']  ?? 0);
$f_program   = trim($_GET['program']   ?? '');
$f_batch     = trim($_GET['batch']     ?? '');
$f_status    = trim($_GET['status']    ?? '');
$f_student_q = trim($_GET['student_q'] ?? '');
$f_as_of_date = trim($_GET['as_of_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_as_of_date)) {
    $f_as_of_date = date('Y-m-d');
}
$f_min_due   = max(0.0, (float)($_GET['min_due'] ?? 0));
$f_focus_package_id = (int)($_GET['focus_package_id'] ?? 0);
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
if ($f_student_q !== '') {
    $where[] = '(s.full_name LIKE ? OR s.student_id LIKE ?)';
    $student_like = '%' . $f_student_q . '%';
    $params[] = $student_like;
    $params[] = $student_like;
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
        SELECT p.package_id, COALESCE(SUM(p.amount), 0) AS total_paid
        FROM sfp_payments p
        JOIN acc_vouchers v ON v.id = p.voucher_id
        WHERE v.status = 'posted'
          AND v.is_deleted = 0
          AND v.voucher_date < DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY p.package_id
     ) pay_agg ON pay_agg.package_id = p.id
     WHERE $where_sql
     ORDER BY d.name, p.program_name, s.admitted_semester DESC, s.full_name"
);
$stmt->execute(array_merge([$f_as_of_date], $params));
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
$kpi_students_clear  = count(array_filter($rows, fn($r) => abs($r['outstanding']) < 0.01));
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

// ── 360° student financial statement (focused student) ────────────────────────
$focus_row = null;
foreach ($rows as $row) {
    if ($f_focus_package_id > 0 && (int)$row['package_id'] === $f_focus_package_id) {
        $focus_row = $row;
        break;
    }
}
if (!$focus_row && count($rows) === 1) {
    $focus_row = $rows[0];
}

$focus_summary = null;
$focus_metrics = null;
$focus_payments = [];
if ($focus_row) {
    $focus_summary = acc_student_fee_summary((int)$focus_row['student_id']);
    if ($focus_summary) {
        $as_of_month_start = strtotime(date('Y-m-01', strtotime($f_as_of_date)));
        $admission_due = (float)($focus_summary['totals']['admission']['due'] ?? 0);
        $registration_due_total = (float)($focus_summary['totals']['registration']['due'] ?? 0);
        $tuition_due_total = (float)($focus_summary['totals']['tuition']['due'] ?? 0);
        $total_obligation = $admission_due + $registration_due_total + $tuition_due_total;
        $total_paid = (float)$focus_row['total_paid'];

        $current_registration_due = 0.0;
        $current_tuition_due = 0.0;
        foreach (($focus_summary['semesters'] ?? []) as $sem) {
            $month_rows = $sem['monthly_rows'] ?? [];
            if (!empty($month_rows)) {
                $first_month = $month_rows[0];
                $sem_start_ts = strtotime(sprintf('%04d-%02d-01', (int)$first_month['cal_year'], (int)$first_month['cal_month']));
                if ($sem_start_ts <= $as_of_month_start) {
                    $current_registration_due += (float)($sem['reg_fee'] ?? 0);
                }
            }

            foreach ($month_rows as $mr) {
                $month_ts = strtotime(sprintf('%04d-%02d-01', (int)$mr['cal_year'], (int)$mr['cal_month']));
                if ($month_ts <= $as_of_month_start) {
                    $current_tuition_due += (float)$mr['due'];
                }
            }
        }

        $current_obligation = $admission_due + $current_registration_due + $current_tuition_due;
        $current_due = max(0.0, $current_obligation - $total_paid);
        $overall_outstanding = max(0.0, $total_obligation - $total_paid);
        $future_obligation = max(0.0, $total_obligation - $current_obligation);
        $current_paid_coverage = min($total_paid, $current_obligation);
        $current_coverage_pct = $current_obligation > 0
            ? min(100, round($current_paid_coverage / $current_obligation * 100))
            : 100;
        $overall_coverage_pct = $total_obligation > 0
            ? min(100, round($total_paid / $total_obligation * 100))
            : 100;

        $focus_metrics = [
            'admission_due'          => round($admission_due, 2),
            'registration_due_total' => round($registration_due_total, 2),
            'tuition_due_total'      => round($tuition_due_total, 2),
            'current_registration_due' => round($current_registration_due, 2),
            'current_tuition_due'      => round($current_tuition_due, 2),
            'total_obligation'       => round($total_obligation, 2),
            'total_paid'             => round($total_paid, 2),
            'current_obligation'     => round($current_obligation, 2),
            'current_due'            => round($current_due, 2),
            'future_obligation'      => round($future_obligation, 2),
            'overall_outstanding'    => round($overall_outstanding, 2),
            'current_coverage_pct'   => $current_coverage_pct,
            'overall_coverage_pct'   => $overall_coverage_pct,
        ];
    }

    $pay_stmt = $db->prepare(
        "SELECT v.voucher_date, v.voucher_number, p.fee_type, p.semester_number, p.month_number,
                p.amount, p.payment_method, p.mobile_banking_provider, p.transaction_number, p.note
         FROM sfp_payments p
         JOIN acc_vouchers v ON v.id = p.voucher_id
         WHERE p.package_id = :package_id
           AND v.status = 'posted'
           AND v.is_deleted = 0
           AND v.voucher_date < DATE_ADD(:as_of_date, INTERVAL 1 DAY)
         ORDER BY v.voucher_date DESC, v.id DESC
         LIMIT :timeline_limit"
    );
    $pay_stmt->bindValue(':package_id', (int)$focus_row['package_id'], PDO::PARAM_INT);
    $pay_stmt->bindValue(':as_of_date', $f_as_of_date, PDO::PARAM_STR);
    $pay_stmt->bindValue(':timeline_limit', (int)$payment_timeline_limit, PDO::PARAM_INT);
    $pay_stmt->execute();
    $focus_payments = $pay_stmt->fetchAll();
}

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
            <?php if ($f_focus_package_id > 0): ?>
            <input type="hidden" name="focus_package_id" value="<?= (int)$f_focus_package_id ?>">
            <?php endif; ?>

            <div class="col-md">
                <label class="form-label small fw-semibold mb-1">Student Name / ID</label>
                <input type="text" name="student_q" class="form-control form-control-sm"
                       value="<?= h($f_student_q) ?>" placeholder="e.g. 22345 or Rahim">
            </div>

            <div class="col-md">
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

            <div class="col-md">
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

            <div class="col-md">
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

            <div class="col-md">
                <label class="form-label small fw-semibold mb-1">Student Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (['Active', 'Inactive', 'Graduated', 'Dropped'] as $st): ?>
                    <option value="<?= h($st) ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= h($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Min Due (<?= h($currency) ?>)</label>
                <input type="number" name="min_due" class="form-control form-control-sm"
                       value="<?= $f_min_due > 0 ? h($f_min_due) : '' ?>" min="0" step="1" placeholder="0">
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">As of Date</label>
                <input type="date" name="as_of_date" class="form-control form-control-sm" value="<?= h($f_as_of_date) ?>">
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
        <span style="font-size:8pt;color:#888">Generated: <?= date('d M Y, h:i A') ?> | Due As of: <?= h(date('d M Y', strtotime($f_as_of_date))) ?></span>
        <?php if ($f_dept || $f_program !== '' || $f_batch !== '' || $f_status !== '' || $f_student_q !== ''): ?>
        <div style="font-size:8pt;color:#666;margin-top:4px">
            Filters:
            <?php if ($f_student_q): echo h("Student: $f_student_q"); echo " &nbsp;"; endif; ?>
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
        'overview'  => ['<i class="fas fa-chart-line me-1"></i>360° Overview', null],
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
                <?php if ($cnt !== null): ?>
                <span class="badge bg-secondary bg-opacity-25 text-dark ms-1"><?= $cnt ?></span>
                <?php endif; ?>
        </button>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content">

    <!-- ── TAB: 360° Overview ── -->
    <div class="tab-pane fade <?= $active_tab === 'overview' ? 'show active' : '' ?>" id="pane-overview" role="tabpanel">
        <div class="card border-0 shadow-sm border-top-0 rounded-0 rounded-bottom">
            <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <strong class="small">Student Financial Statement (As of <?= h(date('d M Y', strtotime($f_as_of_date))) ?>)</strong>
                <?php if ($focus_row): ?>
                <span class="badge bg-primary-subtle text-primary-emphasis border">
                    <?= h($focus_row['student_sid']) ?> · <?= h($focus_row['full_name']) ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if (!$focus_row || !$focus_metrics): ?>
            <div class="card-body text-center py-5 text-muted">
                <i class="fas fa-user-check fa-3x mb-3 opacity-50"></i>
                <p class="mb-1 fw-semibold">Choose a student from “By Student” tab to view 360° overview.</p>
                <p class="mb-0 small">Tip: search by Student ID for quick access to one student profile.</p>
            </div>
            <?php else: ?>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="border rounded p-2 h-100">
                            <div class="text-muted small">Total Obligation</div>
                            <div class="fw-bold"><?= $currency ?> <?= number_format($focus_metrics['total_obligation'], 2) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="border rounded p-2 h-100">
                            <div class="text-muted small">Total Paid</div>
                            <div class="fw-bold text-success"><?= $currency ?> <?= number_format($focus_metrics['total_paid'], 2) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="border rounded p-2 h-100">
                            <div class="text-muted small">Current Obligation</div>
                            <div class="fw-bold"><?= $currency ?> <?= number_format($focus_metrics['current_obligation'], 2) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="border rounded p-2 h-100 border-danger">
                            <div class="text-muted small">Current Due</div>
                            <div class="fw-bold text-danger"><?= $currency ?> <?= number_format($focus_metrics['current_due'], 2) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="border rounded p-2 h-100">
                            <div class="text-muted small">Future Obligation</div>
                            <div class="fw-bold text-warning-emphasis"><?= $currency ?> <?= number_format($focus_metrics['future_obligation'], 2) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="border rounded p-2 h-100 border-danger-subtle">
                            <div class="text-muted small">Overall Outstanding</div>
                            <div class="fw-bold text-danger"><?= $currency ?> <?= number_format($focus_metrics['overall_outstanding'], 2) ?></div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="card h-100 border-0 bg-light">
                            <div class="card-body">
                                <div class="small fw-semibold mb-2">Current Coverage</div>
                                <div class="progress" style="height:8px">
                                    <div class="progress-bar <?= $focus_metrics['current_coverage_pct'] >= 100 ? 'bg-success' : ($focus_metrics['current_coverage_pct'] >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                                         style="width:<?= $focus_metrics['current_coverage_pct'] ?>%"></div>
                                </div>
                                <div class="small text-muted mt-1"><?= $focus_metrics['current_coverage_pct'] ?>% covered against current obligation</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 border-0 bg-light">
                            <div class="card-body">
                                <div class="small fw-semibold mb-2">Overall Coverage</div>
                                <div class="progress" style="height:8px">
                                    <div class="progress-bar <?= $focus_metrics['overall_coverage_pct'] >= 100 ? 'bg-success' : ($focus_metrics['overall_coverage_pct'] >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                                         style="width:<?= $focus_metrics['overall_coverage_pct'] ?>%"></div>
                                </div>
                                <div class="small text-muted mt-1"><?= $focus_metrics['overall_coverage_pct'] ?>% covered against full program obligation</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Fee Head</th>
                            <th class="text-end">Total Obligation (<?= h($currency) ?>)</th>
                            <th class="text-end">Current Obligation (<?= h($currency) ?>)</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td>Admission + Form/ID</td>
                            <td class="text-end"><?= number_format($focus_metrics['admission_due'], 2) ?></td>
                            <td class="text-end"><?= number_format($focus_metrics['admission_due'], 2) ?></td>
                        </tr>
                        <tr>
                            <td>Registration Fees</td>
                            <td class="text-end"><?= number_format($focus_metrics['registration_due_total'], 2) ?></td>
                            <td class="text-end"><?= number_format($focus_metrics['current_registration_due'], 2) ?></td>
                        </tr>
                        <tr>
                            <td>Tuition + Fixed + English</td>
                            <td class="text-end"><?= number_format($focus_metrics['tuition_due_total'], 2) ?></td>
                            <td class="text-end"><?= number_format($focus_metrics['current_tuition_due'], 2) ?></td>
                        </tr>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                        <tr>
                            <td>Total</td>
                            <td class="text-end"><?= number_format($focus_metrics['total_obligation'], 2) ?></td>
                            <td class="text-end"><?= number_format($focus_metrics['current_obligation'], 2) ?></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <h6 class="mb-0">Payment Timeline (Posted vouchers · latest <?= number_format($payment_timeline_limit) ?>)</h6>
                    <a href="<?= APP_URL ?>/student-accounts/statement.php?id=<?= (int)$focus_row['package_id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm no-print">
                        <i class="fas fa-file-invoice-dollar me-1"></i>Open Financial Statement
                    </a>
                </div>

                <?php if (empty($focus_payments)): ?>
                <div class="alert alert-light border small mb-0">No posted payment found up to selected as-of date.</div>
                <?php else: ?>
                <?php
                $fee_labels = [
                    'admission' => 'Admission',
                    'registration' => 'Registration',
                    'semester_tuition' => 'Tuition',
                    'fixed_fee' => 'Fixed Fee',
                    'english_fee' => 'English Fee',
                    'other' => 'Other',
                ];
                ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Voucher</th>
                            <th>Head</th>
                            <th>Slot</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th class="text-end">Amount (<?= h($currency) ?>)</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($focus_payments as $pay): ?>
                        <tr>
                            <td><?= h(date('d M Y', strtotime($pay['voucher_date']))) ?></td>
                            <td><?= h($pay['voucher_number'] ?: '-') ?></td>
                            <td><?= h($fee_labels[$pay['fee_type']] ?? $format_enum_label((string)$pay['fee_type'])) ?></td>
                            <td>
                                <?= $pay['semester_number'] ? 'Sem ' . (int)$pay['semester_number'] : '-' ?>
                                <?= $pay['month_number'] ? ' / M' . (int)$pay['month_number'] : '' ?>
                            </td>
                            <td>
                                <?= h($format_enum_label((string)$pay['payment_method'])) ?>
                                <?php if (!empty($pay['mobile_banking_provider'])): ?>
                                    <small class="text-muted">(<?= h(ucfirst((string)$pay['mobile_banking_provider'])) ?>)</small>
                                <?php endif; ?>
                            </td>
                            <td><?= h($pay['transaction_number'] ?: '-') ?></td>
                            <td class="text-end fw-semibold"><?= number_format((float)$pay['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div><!-- /pane-overview -->

    <!-- ── TAB: By Student ── -->
    <div class="tab-pane fade <?= $active_tab === 'students' ? 'show active' : '' ?>" id="pane-students" role="tabpanel">
        <div class="card border-0 shadow-sm border-top-0 rounded-0 rounded-bottom">
            <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center no-print">
                <strong class="small"><?= number_format(count($rows)) ?> student(s) with due ≥ <?= $currency ?> <?= number_format($f_min_due, 0) ?> · As of <?= h(date('d M Y', strtotime($f_as_of_date))) ?></strong>
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
                    <tr class="<?= $r['outstanding'] >= 0.01 ? '' : 'table-success' ?>">
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
                        <td class="text-end <?= $r['outstanding'] >= 0.01 ? 'text-danger fw-bold' : 'text-success' ?>">
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
                            <?php
                            $focus_url = ['tab' => 'overview', 'focus_package_id' => (int)$r['package_id']];
                            if ($f_student_q !== '') $focus_url['student_q'] = $f_student_q;
                            if ($f_dept > 0) $focus_url['dept_id'] = $f_dept;
                            if ($f_program !== '') $focus_url['program'] = $f_program;
                            if ($f_batch !== '') $focus_url['batch'] = $f_batch;
                            if ($f_status !== '') $focus_url['status'] = $f_status;
                            if ($f_min_due > 0) $focus_url['min_due'] = $f_min_due;
                            $focus_url['as_of_date'] = $f_as_of_date;
                            ?>
                            <a href="?<?= h(http_build_query($focus_url)) ?>"
                               class="btn btn-outline-warning btn-sm" title="360° Overview">
                                <i class="fas fa-chart-line me-1"></i>360°
                            </a>
                            <a href="<?= APP_URL ?>/student-accounts/statement.php?id=<?= (int)$r['package_id'] ?>"
                               class="btn btn-outline-primary btn-sm" target="_blank" title="Financial Statement">
                                <i class="fas fa-file-invoice-dollar me-1"></i>Financial Statement
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
                        <td class="text-end <?= $b['outstanding'] >= 0.01 ? 'text-danger fw-bold' : 'text-success' ?>">
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
                        <td class="text-end <?= $bp['outstanding'] >= 0.01 ? 'text-danger fw-bold' : 'text-success' ?>">
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
                        <td class="text-end <?= $bd['outstanding'] >= 0.01 ? 'text-danger fw-bold' : 'text-success' ?>">
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
