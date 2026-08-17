<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../accounting/helpers.php';
require_once __DIR__ . '/../students/helpers.php';  // sm_program_data(), sm_batches()

$page_title = 'Student Accounts';
$db         = db();

// ── Department scope ──────────────────────────────────────────────────────────
$dept_scope = get_dept_scope(); // null = unrestricted; int[] = allowed dept ids

// ── Filters ───────────────────────────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$f_dept    = (int)($_GET['dept']    ?? 0);
$f_program = (int)($_GET['program'] ?? 0);
$f_batch   = (int)($_GET['batch']   ?? 0);
$f_sems    = (int)($_GET['sems']    ?? 0);
// OLD ERP cross-check status filter: '' | 'mismatch' | 'match' | 'unchecked'
$f_erp     = trim($_GET['erp'] ?? '');
if (!in_array($f_erp, ['', 'mismatch', 'match', 'unchecked'], true)) {
    $f_erp = '';
}

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($f_dept > 0) {
    $where[]  = 's.dept_id = ?';
    $params[] = $f_dept;
}
if ($f_program > 0) {
    $where[]  = 's.program_id = ?';
    $params[] = $f_program;
}
if ($f_batch > 0) {
    $where[]  = 's.batch_id = ?';
    $params[] = $f_batch;
}
if ($f_sems > 0) {
    $where[]  = 'p.total_semesters = ?';
    $params[] = $f_sems;
}
if ($f_erp === 'mismatch' || $f_erp === 'match') {
    // Only checked accounts can have a match / mismatch status;
    // the exact status is computed per row in PHP below.
    $where[] = 'p.old_erp_payable_amount IS NOT NULL';
} elseif ($f_erp === 'unchecked') {
    $where[] = "p.old_erp_payable_amount IS NULL
                AND EXISTS (SELECT 1 FROM student_files stf
                             WHERE stf.student_id = s.id
                               AND stf.file_name = 'OLD ERP Proof')";
}

// Apply department scope restriction for non-super-admins
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $where[] = '0 = 1';
    } else {
        $phs     = implode(',', array_fill(0, count($dept_scope), '?'));
        $where[] = "s.dept_id IN ($phs)";
        array_push($params, ...$dept_scope);
    }
}

$where_sql = implode(' AND ', $where);

// ── OLD ERP cross-check for a list row (shared by status filter + rendering) ──
$sfp_index_erp_check = static function (array $pkg): ?array {
    if (!isset($pkg['old_erp_payable_amount']) || $pkg['old_erp_payable_amount'] === null) {
        return null;
    }
    $months   = (float)($pkg['total_months'] ?? 0);
    $mps      = (float)($pkg['months_per_semester'] ?? 0);
    $fixed_ps = ($months > 0 && $mps > 0)
        ? round((float)$pkg['fixed_institutional_fees'] * $mps / $months, 2) : 0.0;
    $eng_ps   = ($months > 0 && $mps > 0)
        ? round((float)$pkg['english_course_fee'] * $mps / $months, 2) : 0.0;
    $sem_cnt  = (int)($pkg['erp_sem_count'] ?? 0);
    $proj_fee = acc_package_project_fee($pkg);
    $form_id  = acc_package_form_id_fee($pkg);
    $grand = (float)($pkg['erp_sum_tuition'] ?? 0)
           + max(0.0, $fixed_ps * $sem_cnt - (float)($pkg['erp_sum_fixed_disc'] ?? 0))
           + max(0.0, $eng_ps   * $sem_cnt - (float)($pkg['erp_sum_eng_disc']   ?? 0))
           + (float)($pkg['reg_fee_per_semester'] ?? 0) * $sem_cnt
           + (float)($pkg['admission_fees'] ?? 0)
           + $form_id
           + $proj_fee
           + (float)($pkg['bi_tri_shift_fee'] ?? 0);
    // OLD ERP payable excludes Form, ID Card and Project fees
    return sfp_old_erp_check((float)$pkg['old_erp_payable_amount'], $grand, $proj_fee, $form_id);
};

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

$list_select =
    "SELECT p.*,
            s.full_name    AS student_name,
            s.student_id   AS student_sid,
            s.admitted_semester,
            s.status       AS student_status,
            sf1.tuition_payable        AS current_tuition_payable,
            sf1.tuition_fee            AS current_tuition_fee,
            sf1.fixed_discount_amount  AS current_fixed_discount,
            sf1.english_discount_amount AS current_english_discount,
            (SELECT COUNT(*)
               FROM sfp_payments sp
               JOIN acc_vouchers v ON v.id = sp.voucher_id
              WHERE sp.package_id = p.id
                AND v.is_deleted = 0) AS payment_count,
            (SELECT COALESCE(SUM(sf.tuition_payable), 0)
               FROM sfp_semester_fees sf WHERE sf.package_id = p.id) AS erp_sum_tuition,
            (SELECT COALESCE(SUM(sf.fixed_discount_amount), 0)
               FROM sfp_semester_fees sf WHERE sf.package_id = p.id) AS erp_sum_fixed_disc,
            (SELECT COALESCE(SUM(sf.english_discount_amount), 0)
               FROM sfp_semester_fees sf WHERE sf.package_id = p.id) AS erp_sum_eng_disc,
            (SELECT COUNT(*)
               FROM sfp_semester_fees sf WHERE sf.package_id = p.id) AS erp_sem_count,
            EXISTS(SELECT 1 FROM student_files stf
                    WHERE stf.student_id = s.id
                      AND stf.file_name = 'OLD ERP Proof') AS has_erp_proof
     FROM sfp_packages p
      JOIN students s ON s.id = p.student_id
      LEFT JOIN sfp_semester_fees sf1
             ON sf1.package_id = p.id
            AND sf1.semester_number = 1
      WHERE $where_sql
      ORDER BY p.created_at DESC";

if ($f_erp === 'mismatch' || $f_erp === 'match') {
    // Match / mismatch is computed from live fee math, so load all checked
    // candidate rows (SQL already restricts to old_erp_payable_amount IS NOT
    // NULL), evaluate each one and paginate in PHP.
    $stmt = $db->prepare($list_select);
    $stmt->execute($params);
    $filtered = [];
    foreach ($stmt->fetchAll() as $row) {
        $chk = $sfp_index_erp_check($row);
        if ($chk === null) {
            continue;
        }
        if (($f_erp === 'mismatch' && !$chk['matched'])
            || ($f_erp === 'match' && $chk['matched'])) {
            $filtered[] = $row;
        }
    }
    $total    = count($filtered);
    $pages    = max(1, (int)ceil($total / $per_page));
    $page     = min($page, $pages);
    $packages = array_slice($filtered, ($page - 1) * $per_page, $per_page);
} else {
    $cnt_stmt = $db->prepare(
        "SELECT COUNT(*)
         FROM sfp_packages p
         JOIN students s ON s.id = p.student_id
         WHERE $where_sql"
    );
    $cnt_stmt->execute($params);
    $total = (int)$cnt_stmt->fetchColumn();
    $pages = max(1, (int)ceil($total / $per_page));
    $page  = min($page, $pages);
    $off   = ($page - 1) * $per_page;

    $stmt = $db->prepare($list_select . " LIMIT $per_page OFFSET $off");
    $stmt->execute($params);
    $packages = $stmt->fetchAll();
}

// ── Filter dropdown data ──────────────────────────────────────────────────────
$departments = $db->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
$all_programs = sm_program_data();
$batches      = sm_batches();
// Distinct semester counts that actually exist across packages
$semester_options = $db->query(
    'SELECT DISTINCT total_semesters FROM sfp_packages ORDER BY total_semesters ASC'
)->fetchAll(PDO::FETCH_COLUMN);

// Bulk edit is available to super admins and the Freelancer user group
$can_bulk_edit    = sfp_can_bulk_edit();
$cf_programs_bulk = $can_bulk_edit ? sfp_get_cf_programs() : [];
// Restrict dept/program dropdowns to the user's allowed departments
if ($dept_scope !== null) {
    $departments = array_values(array_filter(
        $departments,
        fn($d) => in_array((int)$d['id'], $dept_scope, true)
    ));
    $all_programs = array_values(array_filter(
        $all_programs,
        fn($p) => in_array((int)$p['dept_id'], $dept_scope, true)
    ));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-file-invoice-dollar me-2 text-success"></i>Student Accounts</h1>
        <p class="text-muted mb-0 small">Snapshotted fee structures assigned to students.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/student-accounts/erp-check-runner.php" class="btn btn-outline-primary btn-sm"
           title="Read the Payable Amount from every OLD ERP proof with OCR and cross-check it against the Grand Total, without opening each account.">
            <i class="fas fa-wand-magic-sparkles me-1"></i> Bulk ERP Check
        </a>
        <?php if (sfp_can_create()): ?>
        <a href="<?= APP_URL ?>/student-accounts/create.php" class="btn btn-success btn-sm">
            <i class="fas fa-plus me-1"></i> Assign Package
        </a>
        <a href="<?= APP_URL ?>/student-accounts/bulk-import.php" class="btn btn-outline-success btn-sm">
            <i class="fas fa-file-import me-1"></i> Bulk PDF / CSV Import
        </a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- ── Search & Filter bar ── -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Student name or ID…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Department</label>
                <select name="dept" id="filter_dept" class="form-select form-select-sm">
                    <option value="">All Depts</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Program</label>
                <select name="program" id="filter_program" class="form-select form-select-sm">
                    <option value="">All Programs</option>
                    <?php foreach ($all_programs as $p): ?>
                    <option value="<?= $p['id'] ?>"
                            data-dept="<?= $p['dept_id'] ?>"
                            <?= $f_program == $p['id'] ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Batch</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $f_batch == $b['id'] ? 'selected' : '' ?>>
                        <?= h($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label fw-semibold small mb-1">Semesters</label>
                <select name="sems" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($semester_options as $so): ?>
                    <option value="<?= (int)$so ?>" <?= $f_sems == (int)$so ? 'selected' : '' ?>>
                        <?= (int)$so ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button class="btn btn-primary btn-sm flex-fill" type="submit"><i class="fas fa-search me-1"></i>Filter</button>
                <?php if ($search !== '' || $f_dept || $f_program || $f_batch || $f_sems): ?>
                <a href="<?= APP_URL ?>/student-accounts/index.php" class="btn btn-outline-secondary btn-sm flex-fill">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($can_bulk_edit): ?>
<!-- ── Bulk Edit (super admin & Freelancer group) ── -->
<form id="bulk-form" method="post" action="<?= APP_URL ?>/student-accounts/bulk-update.php">
    <?= csrf_field() ?>
    <input type="hidden" name="select_all_matching" id="select-all-matching" value="0">
    <input type="hidden" name="flt_q" value="<?= h($search) ?>">
    <input type="hidden" name="flt_dept" value="<?= $f_dept ?>">
    <input type="hidden" name="flt_program" value="<?= $f_program ?>">
    <input type="hidden" name="flt_batch" value="<?= $f_batch ?>">
    <input type="hidden" name="flt_sems" value="<?= $f_sems ?>">
    <input type="hidden" name="flt_page" value="<?= (int)$page ?>">
    <div class="card mb-4 border-warning" id="bulk-panel" style="display:none;">
        <div class="card-header bg-warning-subtle fw-semibold py-2">
            <i class="fas fa-layer-group me-2"></i>Bulk Edit
            (<span id="bulk-count">0</span> selected)
            <span class="text-muted fw-normal small ms-2">Leave a field blank to keep it unchanged.</span>
        </div>
        <div class="card-body py-3">
            <div class="alert alert-info py-2 px-3 mb-3 small" id="bulk-all-pages-notice" style="display:none;">
                <span id="bulk-all-pages-text"></span>
                <a href="#" id="bulk-select-all-pages" class="fw-semibold">Select all <?= (int)$total ?> matching account(s) across all pages</a>
                <a href="#" id="bulk-only-this-page" class="fw-semibold" style="display:none;">Select only this page</a>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">Programme (Course Fee Structure)</label>
                    <select name="bulk_cf_program_id" class="form-select form-select-sm">
                        <option value="">&mdash; No change &mdash;</option>
                        <?php foreach ($cf_programs_bulk as $cp): ?>
                        <option value="<?= $cp['id'] ?>"><?= h($cp['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">Student Programme (student record)</label>
                    <select name="bulk_student_program_id" class="form-select form-select-sm">
                        <option value="">&mdash; No change &mdash;</option>
                        <?php foreach ($all_programs as $sp): ?>
                        <option value="<?= $sp['id'] ?>"><?= h($sp['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small mb-1">Department (student record)</label>
                    <select name="bulk_dept_id" class="form-select form-select-sm">
                        <option value="">&mdash; No change &mdash;</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label class="form-label fw-semibold small mb-1">Semesters</label>
                    <input type="number" name="bulk_total_semesters" class="form-control form-control-sm"
                           min="1" step="1" placeholder="&mdash;">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Tuition / Semester</label>
                    <input type="number" name="bulk_tuition_per_semester" class="form-control form-control-sm"
                           min="0" step="0.01" placeholder="&mdash;">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Monthly Fixed</label>
                    <input type="number" name="bulk_monthly_fixed" class="form-control form-control-sm"
                           min="0" step="0.01" placeholder="&mdash;">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Fixed Inst. Fees (Total)</label>
                    <input type="number" name="bulk_fixed_institutional_fees" class="form-control form-control-sm"
                           min="0" step="0.01" placeholder="&mdash;">
                </div>
                <div class="col-6 col-md-1">
                    <label class="form-label fw-semibold small mb-1">Project Fee</label>
                    <input type="number" name="bulk_project_fee" class="form-control form-control-sm"
                           min="0" step="0.01" placeholder="&mdash;">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1"
                           title="Keeps the student's monthly total at this amount by rebalancing Fixed Institutional Fees; the difference is moved into the one-time Bi-Tri Shift Merge fee so the Grand Total stays the same. Project Fee and all other fees are untouched.">
                        Target Monthly Total <i class="fas fa-circle-info text-muted"></i>
                    </label>
                    <input type="number" name="bulk_target_monthly_total" class="form-control form-control-sm"
                           min="0" step="0.01" placeholder="&mdash;"
                           title="Monthly total to keep (tuition + fixed + English per month). The fixed-fee difference is shifted into the one-time Bi-Tri Shift Merge fee so the Grand Total is unchanged.">
                </div>
                <?php $bulk_months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                                      5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                                      9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December']; ?>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Payment Type</label>
                    <select name="bulk_payment_type" class="form-select form-select-sm">
                        <option value="">&mdash; No change &mdash;</option>
                        <option value="merit">Merit (calculated)</option>
                        <option value="fixed">Fixed (flat monthly)</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Monthly Payment (Fixed)</label>
                    <input type="number" name="bulk_monthly_payment" class="form-control form-control-sm"
                           min="0" step="0.01" placeholder="&mdash;">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Bi-Sem Start Month</label>
                    <select name="bulk_bi_start_month" class="form-select form-select-sm">
                        <option value="">&mdash; No change &mdash;</option>
                        <?php foreach ($bulk_months as $mn => $mname): ?>
                        <option value="<?= $mn ?>"><?= $mname ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Tri-Sem Start Month</label>
                    <select name="bulk_tri_start_month" class="form-select form-select-sm">
                        <option value="">&mdash; No change &mdash;</option>
                        <?php foreach ($bulk_months as $mn => $mname): ?>
                        <option value="<?= $mn ?>"><?= $mname ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Total Months</label>
                    <input type="number" name="bulk_total_months" class="form-control form-control-sm"
                           min="1" step="1" placeholder="&mdash;">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Months / Semester</label>
                    <input type="number" name="bulk_months_per_semester" class="form-control form-control-sm"
                           min="0" step="0.01" placeholder="&mdash;">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Standard Tuition (Full)</label>
                    <input type="number" name="bulk_standard_tuition_full" class="form-control form-control-sm"
                           min="0" step="0.01" placeholder="&mdash;">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Reg Fee / Semester</label>
                    <input type="number" name="bulk_reg_fee_per_semester" class="form-control form-control-sm"
                           min="0" step="0.01" placeholder="&mdash;">
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-warning btn-sm">
                    <i class="fas fa-check me-1"></i>Apply to Selected
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="bulk-clear">
                    Clear Selection
                </button>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>

<!-- ── Table ── -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($packages)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-file-invoice-dollar fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No student accounts found.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <?php if ($can_bulk_edit): ?>
                        <th style="width:32px;">
                            <input type="checkbox" class="form-check-input" id="bulk-select-all"
                                   title="Select all on this page">
                        </th>
                        <?php endif; ?>
                        <th>Student</th>
                        <th>Programme</th>
                        <th>Semesters</th>
                        <th>Tuition / Sem</th>
                        <th>Monthly Fixed</th>
                        <th>Assigned</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($packages as $pkg):
                    // ── OLD ERP payable cross-check for this row (±50 BDT) ──
                    // Shared closure defined above (also used by the status filter).
                    $erp_payable = (isset($pkg['old_erp_payable_amount']) && $pkg['old_erp_payable_amount'] !== null)
                        ? (float)$pkg['old_erp_payable_amount'] : null;
                    $erp_check     = $sfp_index_erp_check($pkg);
                    $erp_row_class = ($erp_check !== null && !$erp_check['matched']) ? 'table-danger' : '';
                ?>
                <tr class="<?= $erp_row_class ?>">
                    <?php if ($can_bulk_edit): ?>
                    <td>
                        <input type="checkbox" class="form-check-input bulk-pkg" form="bulk-form"
                               name="package_ids[]" value="<?= $pkg['id'] ?>">
                    </td>
                    <?php endif; ?>
                    <td>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= $pkg['student_id'] ?>"
                           class="fw-semibold text-decoration-none">
                            <?= h($pkg['student_name']) ?>
                        </a><br>
                        <small class="text-muted"><?= h($pkg['student_sid']) ?></small>
                        <?php if (function_exists('sd_current_badge')): $sd_b = sd_current_badge((int)$pkg['student_id']); if ($sd_b !== ''): ?>
                        <div class="mt-1"><?= $sd_b ?></div>
                        <?php endif; endif; ?>
                        <?php if ($erp_check !== null && !$erp_check['matched']): ?>
                        <div class="mt-1">
                            <span class="badge bg-danger"
                                  title="OLD ERP Payable (<?= number_format($erp_payable, 2) ?> BDT) differs from the Grand Total by <?= number_format($erp_check['best_diff'], 2) ?> BDT (tolerance ±<?= number_format(SFP_OLD_ERP_TOLERANCE, 0) ?> BDT, incl. Project Fee cross-check). Open the account to review.">
                                <i class="fas fa-triangle-exclamation me-1"></i>ERP mismatch (Δ <?= number_format($erp_check['best_diff'], 0) ?>)
                            </span>
                        </div>
                        <?php elseif ($erp_check !== null): ?>
                        <div class="mt-1">
                            <span class="badge bg-success bg-opacity-75"
                                  title="OLD ERP Payable (<?= number_format($erp_payable, 2) ?> BDT) matches the Grand Total within ±<?= number_format(SFP_OLD_ERP_TOLERANCE, 0) ?> BDT.">
                                <i class="fas fa-check me-1"></i>ERP ✓
                            </span>
                        </div>
                        <?php elseif (!empty($pkg['has_erp_proof'])): ?>
                        <div class="mt-1">
                            <span class="badge bg-secondary"
                                  title="An OLD ERP proof is attached but the Payable Amount has not been read yet. Open the account to run the automatic check.">
                                <i class="fas fa-hourglass-half me-1"></i>ERP unchecked
                            </span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= h($pkg['program_name']) ?>
                    </td>
                    <?php
                    $months = (float)($pkg['total_months'] ?? 0);
                    $months_per_semester = (float)($pkg['months_per_semester'] ?? 0);
                    $reg    = (float)($pkg['reg_fee_per_semester'] ?? 0);
                    $has_semester_months = ($months > 0 && $months_per_semester > 0);

                    $fixed_per_sem = $has_semester_months
                        ? round((float)$pkg['fixed_institutional_fees'] * $months_per_semester / $months, 2)
                        : 0.0;
                    $english_per_sem = $has_semester_months
                        ? round((float)$pkg['english_course_fee'] * $months_per_semester / $months, 2)
                        : 0.0;

                    $fixed_after_discount = max(0.0, $fixed_per_sem - (float)($pkg['current_fixed_discount'] ?? 0));
                    $english_after_discount = max(0.0, $english_per_sem - (float)($pkg['current_english_discount'] ?? 0));
                    $tuition_current = (float)($pkg['current_tuition_payable'] ?? $pkg['tuition_per_semester'] ?? 0);

                    $current_sem_total = $tuition_current + $fixed_after_discount + $english_after_discount + $reg;
                    $current_monthly_total = ($months_per_semester > 0) ? ($current_sem_total / $months_per_semester) : 0.0;
                    ?>
                    <td class="text-center"><?= (int)$pkg['total_semesters'] ?></td>
                    <td><?= sfp_money($current_sem_total) ?></td>
                    <td><?= sfp_money($current_monthly_total) ?></td>
                    <td>
                        <small class="text-muted"><?= date('d M Y', strtotime($pkg['created_at'])) ?></small>
                    </td>
                    <td class="text-end">
                        <a href="<?= APP_URL ?>/student-accounts/view.php?id=<?= $pkg['id'] ?>"
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye me-1"></i>View
                        </a>
                        <a href="<?= APP_URL ?>/student-accounts/statement.php?id=<?= $pkg['id'] ?>"
                           class="btn btn-outline-success btn-sm" target="_blank">
                            <i class="fas fa-file-invoice me-1"></i>Statement
                        </a>
                        <?php if (sfp_can_delete()): ?>
                        <?php if ((int)($pkg['payment_count'] ?? 0) > 0): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm" disabled
                                title="This account has recorded payments or vouchers and cannot be deleted.">
                            <i class="fas fa-lock"></i>
                        </button>
                        <?php else: ?>
                        <form method="post" action="<?= APP_URL ?>/student-accounts/delete.php"
                              class="d-inline"
                              onsubmit="return confirm('Delete this student account? This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $pkg['id'] ?>">
                            <button class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
            <small class="text-muted">
                Page <?= $page ?> of <?= $pages ?> &middot; <?= $total ?> total
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link"
                           href="?<?= http_build_query(['q' => $search, 'dept' => $f_dept ?: '', 'program' => $f_program ?: '', 'batch' => $f_batch ?: '', 'sems' => $f_sems ?: '', 'page' => $p]) ?>">
                            <?= $p ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    var deptSel    = document.getElementById('filter_dept');
    var programSel = document.getElementById('filter_program');
    if (!deptSel || !programSel) return;

    function filterPrograms() {
        var deptId = deptSel.value;
        var opts   = programSel.querySelectorAll('option[data-dept]');
        opts.forEach(function (opt) {
            var show = !deptId || opt.dataset.dept === deptId;
            opt.hidden   = !show;
            opt.disabled = !show;
            if (!show && opt.selected) {
                programSel.value = '';
            }
        });
    }

    deptSel.addEventListener('change', filterPrograms);
    filterPrograms(); // run on page load to respect pre-selected dept
}());
</script>
<script>
(function () {
    var form = document.getElementById('bulk-form');
    if (!form) return; // bulk edit is only rendered for super admins and the Freelancer group

    var panel    = document.getElementById('bulk-panel');
    var countEl  = document.getElementById('bulk-count');
    var selAll   = document.getElementById('bulk-select-all');
    var clearBtn = document.getElementById('bulk-clear');
    var boxes    = Array.prototype.slice.call(document.querySelectorAll('.bulk-pkg'));

    var allMatching        = document.getElementById('select-all-matching');
    var noticeEl           = document.getElementById('bulk-all-pages-notice');
    var noticeText         = document.getElementById('bulk-all-pages-text');
    var selectAllPagesLink = document.getElementById('bulk-select-all-pages');
    var onlyThisPageLink   = document.getElementById('bulk-only-this-page');
    var totalMatching      = <?= (int)$total ?>;

    function selectedCount() {
        return boxes.filter(function (b) { return b.checked; }).length;
    }

    function allPagesSelected() {
        return allMatching && allMatching.value === '1';
    }

    function refresh() {
        var n        = selectedCount();
        var allPages = allPagesSelected();
        countEl.textContent = allPages ? totalMatching : n;
        panel.style.display = (n > 0 || allPages) ? '' : 'none';
        if (selAll) {
            selAll.checked       = n > 0 && n === boxes.length;
            selAll.indeterminate = n > 0 && n < boxes.length;
        }
        if (noticeEl) {
            var offer = !allPages && n > 0 && n === boxes.length && totalMatching > boxes.length;
            noticeEl.style.display = (offer || allPages) ? '' : 'none';
            if (selectAllPagesLink) selectAllPagesLink.style.display = allPages ? 'none' : '';
            if (onlyThisPageLink)   onlyThisPageLink.style.display   = allPages ? '' : 'none';
            if (noticeText) {
                noticeText.textContent = allPages
                    ? 'All ' + totalMatching + ' matching account(s) across all pages are selected. '
                    : 'All ' + n + ' account(s) on this page are selected. ';
            }
        }
    }

    boxes.forEach(function (b) {
        b.addEventListener('change', function () {
            if (allMatching) allMatching.value = '0';
            refresh();
        });
    });

    if (selAll) {
        selAll.addEventListener('change', function () {
            if (allMatching) allMatching.value = '0';
            boxes.forEach(function (b) { b.checked = selAll.checked; });
            refresh();
        });
    }

    if (selectAllPagesLink) {
        selectAllPagesLink.addEventListener('click', function (e) {
            e.preventDefault();
            boxes.forEach(function (b) { b.checked = true; });
            if (allMatching) allMatching.value = '1';
            refresh();
        });
    }

    if (onlyThisPageLink) {
        onlyThisPageLink.addEventListener('click', function (e) {
            e.preventDefault();
            if (allMatching) allMatching.value = '0';
            refresh();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (allMatching) allMatching.value = '0';
            boxes.forEach(function (b) { b.checked = false; });
            refresh();
        });
    }

    form.addEventListener('submit', function (e) {
        var allPages = allPagesSelected();
        var n = allPages ? totalMatching : selectedCount();
        if (n === 0) {
            e.preventDefault();
            alert('Select at least one student account.');
            return;
        }
        var fields = ['bulk_cf_program_id', 'bulk_student_program_id', 'bulk_dept_id',
                      'bulk_total_semesters',
                      'bulk_tuition_per_semester', 'bulk_monthly_fixed',
                      'bulk_fixed_institutional_fees', 'bulk_project_fee',
                      'bulk_target_monthly_total',
                      'bulk_payment_type', 'bulk_monthly_payment',
                      'bulk_bi_start_month', 'bulk_tri_start_month',
                      'bulk_total_months', 'bulk_months_per_semester',
                      'bulk_standard_tuition_full', 'bulk_reg_fee_per_semester'];
        var any = fields.some(function (f) {
            var el = form.elements[f];
            return el && el.value !== '';
        });
        if (!any) {
            e.preventDefault();
            alert('Set at least one field to change.');
            return;
        }
        if (!confirm('Apply bulk changes to ' + n + ' student account(s)' + (allPages ? ' across all pages' : '') + '? This cannot be undone.')) {
            e.preventDefault();
        }
    });

    refresh();
}());
</script>
