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

// ── Pagination ────────────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

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

$stmt = $db->prepare(
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
                AND v.is_deleted = 0) AS payment_count
     FROM sfp_packages p
      JOIN students s ON s.id = p.student_id
      LEFT JOIN sfp_semester_fees sf1
             ON sf1.package_id = p.id
            AND sf1.semester_number = 1
      WHERE $where_sql
      ORDER BY p.created_at DESC
      LIMIT $per_page OFFSET $off"
);
$stmt->execute($params);
$packages = $stmt->fetchAll();

// ── Filter dropdown data ──────────────────────────────────────────────────────
$departments = $db->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
$all_programs = sm_program_data();
$batches      = sm_batches();
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
    <?php if (sfp_can_create()): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/student-accounts/create.php" class="btn btn-success btn-sm">
            <i class="fas fa-plus me-1"></i> Assign Package
        </a>
        <a href="<?= APP_URL ?>/student-accounts/bulk-import.php" class="btn btn-outline-success btn-sm">
            <i class="fas fa-file-import me-1"></i> Bulk PDF / CSV Import
        </a>
    </div>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<!-- ── Search & Filter bar ── -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
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
            <div class="col-6 col-md-2 d-flex gap-2">
                <button class="btn btn-primary btn-sm flex-fill" type="submit"><i class="fas fa-search me-1"></i>Filter</button>
                <?php if ($search !== '' || $f_dept || $f_program || $f_batch): ?>
                <a href="<?= APP_URL ?>/student-accounts/index.php" class="btn btn-outline-secondary btn-sm flex-fill">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

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
                <?php foreach ($packages as $pkg): ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= $pkg['student_id'] ?>"
                           class="fw-semibold text-decoration-none">
                            <?= h($pkg['student_name']) ?>
                        </a><br>
                        <small class="text-muted"><?= h($pkg['student_sid']) ?></small>
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

                    // Fixed-payment packages use a flat agreed monthly fee that bundles
                    // tuition + institutional fees (English Course Fee is billed
                    // separately, on top — matches Collect Payment / view).
                    if (acc_package_is_fixed_monthly($pkg)) {
                        $months_int_row = max(1, (int)round($months_per_semester));
                        $sf_calc = [
                            'tuition_fee'     => (float)($pkg['current_tuition_fee'] ?? $tuition_current),
                            'tuition_payable' => $tuition_current,
                        ];
                        [$fixed_sem_total, $fixed_monthly] = acc_semester_monthly_due($pkg, $sf_calc, 0.0, $months_int_row);
                        $current_sem_total     = $fixed_sem_total + $reg;
                        $current_monthly_total = $fixed_monthly + (($months_int_row > 0) ? $reg / $months_int_row : 0.0);
                    }
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
                           href="?<?= http_build_query(['q' => $search, 'dept' => $f_dept ?: '', 'program' => $f_program ?: '', 'batch' => $f_batch ?: '', 'page' => $p]) ?>">
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
