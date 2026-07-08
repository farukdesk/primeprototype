<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

$page_title = 'Student Management';
$user       = auth_user();
$is_staff   = sm_is_staff();

// ── Department scope ──────────────────────────────────────────────────────────
// null = unrestricted; int[] = allowed dept ids
$dept_scope = get_dept_scope();

// ── Filters ───────────────────────────────────────────────────────────────────
$search     = trim($_GET['search']   ?? '');
$f_dept     = (int)($_GET['dept']    ?? 0);
$f_status   = $_GET['status']        ?? '';
$f_sem      = trim($_GET['semester'] ?? '');
$f_sem_type = trim($_GET['sem_type'] ?? '');
$f_program  = (int)($_GET['program'] ?? 0);
$f_batch    = (int)($_GET['batch']   ?? 0);
$f_section  = trim($_GET['section']  ?? '');
$f_shift    = trim($_GET['shift']    ?? '');
$f_gender   = trim($_GET['gender']   ?? '');
$f_blood    = trim($_GET['blood']    ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$valid_statuses  = ['Active', 'Inactive', 'Graduated', 'Dropped', 'Not Admitted Yet'];
$valid_sem_types = ['bi_semester', 'trimester'];
$valid_sections  = SM_SECTIONS;
$valid_shifts    = SM_SHIFTS;
$valid_genders   = ['Male', 'Female', 'Other'];
$valid_bloods    = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

$where  = [];
$params = [];

if ($search !== '') {
    $like     = '%' . $search . '%';
    $where[]  = '(s.student_id LIKE ? OR s.full_name LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($f_dept > 0) {
    $where[]  = 's.dept_id = ?';
    $params[] = $f_dept;
}
if (in_array($f_status, $valid_statuses, true)) {
    $where[]  = 's.status = ?';
    $params[] = $f_status;
}
if ($f_sem !== '') {
    $where[]  = 's.admitted_semester = ?';
    $params[] = $f_sem;
}
if (in_array($f_sem_type, $valid_sem_types, true)) {
    $where[]  = 's.semester_type = ?';
    $params[] = $f_sem_type;
}
if ($f_program > 0) {
    $where[]  = 's.program_id = ?';
    $params[] = $f_program;
}
if ($f_batch > 0) {
    $where[]  = 's.batch_id = ?';
    $params[] = $f_batch;
}
if (in_array($f_section, $valid_sections, true)) {
    $where[]  = 's.section = ?';
    $params[] = $f_section;
}
if (in_array($f_shift, $valid_shifts, true)) {
    $where[]  = 's.shift = ?';
    $params[] = $f_shift;
}
if (in_array($f_gender, $valid_genders, true)) {
    $where[]  = 's.sex = ?';
    $params[] = $f_gender;
}
if (in_array($f_blood, $valid_bloods, true)) {
    $where[]  = 's.blood_group = ?';
    $params[] = $f_blood;
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

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count_stmt = db()->prepare('SELECT COUNT(*) FROM students s' . $where_sql);
$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$sql = 'SELECT s.*,
               d.name AS dept_name,
               p.program_name,
               (SELECT COUNT(*)
                  FROM sfp_payments sp
                  JOIN acc_vouchers v ON v.id = sp.voucher_id
                 WHERE sp.student_id = s.id
                   AND v.is_deleted = 0) AS payment_count
        FROM students s
        JOIN dept_departments d ON d.id = s.dept_id
        LEFT JOIN dept_academic_programs p ON p.id = s.program_id'
     . $where_sql
     . ' ORDER BY LENGTH(s.student_id) ASC, s.student_id ASC LIMIT ' . $per_page . ' OFFSET ' . $offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
// Use the same WHERE clause as the main query to make stats reflect current filters
$stats_sql = 'SELECT status, COUNT(*) AS cnt FROM students s' . $where_sql . ' GROUP BY status';
$stats_stmt = db()->prepare($stats_sql);
$stats_stmt->execute($params);
$stats_rows = $stats_stmt->fetchAll();
$stats = array_column($stats_rows, 'cnt', 'status');
$total_students = array_sum($stats);

// ── Departments for filter ────────────────────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
// Restrict dropdown to only departments the user can access
if ($dept_scope !== null) {
    $departments = array_values(array_filter(
        $departments,
        fn($d) => in_array((int)$d['id'], $dept_scope, true)
    ));
}

// ── Programs for filter ───────────────────────────────────────────────────────
$all_programs = sm_program_data();
if ($dept_scope !== null) {
    $all_programs = array_values(array_filter(
        $all_programs,
        fn($p) => in_array((int)$p['dept_id'], $dept_scope, true)
    ));
}

// ── Batches for filter ────────────────────────────────────────────────────────
$batches = sm_batches();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Students</li>
        </ol>
    </nav>
    <?php if (sm_can_create()): ?>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/students/smart-upload.php" class="btn btn-outline-success" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-magic me-1"></i> Smart PDF Upload
        </a>
        <a href="<?= APP_URL ?>/students/bulk-upload.php" class="btn btn-outline-primary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-file-archive me-1"></i> Bulk Upload Files
        </a>
        <a href="<?= APP_URL ?>/students/bulk-photo-upload.php" class="btn btn-outline-warning" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-images me-1"></i> Bulk Upload Photos
        </a>
        <a href="<?= APP_URL ?>/students/legacy-photo-import.php" class="btn btn-outline-info" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-history me-1"></i> Legacy Photo Import
        </a>
        <a href="<?= APP_URL ?>/students/csv-import.php" class="btn btn-outline-success" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-file-csv me-1"></i> Bulk CSV Import
        </a>
        <a href="<?= APP_URL ?>/students/verify-list.php<?= $f_batch || $f_dept || $f_program || $f_status || $f_sem || $f_sem_type ? '?' . http_build_query(array_filter(['dept'=>$f_dept,'program'=>$f_program,'batch'=>$f_batch,'status'=>$f_status,'semester'=>$f_sem,'sem_type'=>$f_sem_type])) : '' ?>" class="btn btn-outline-warning" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-tasks me-1"></i> Verify List
        </a>
        <a href="<?= APP_URL ?>/students/portal-bulk-create.php<?= http_build_query(array_filter($_GET, fn($v) => $v !== '')) ? '?' . h(http_build_query(array_filter($_GET, fn($v) => $v !== ''))) : '' ?>" class="btn btn-outline-secondary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-user-plus me-1"></i> Bulk Portal Accounts
        </a>
        <a href="<?= APP_URL ?>/students/portal-login-report.php" class="btn btn-outline-dark" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-sign-in-alt me-1"></i> Login Report
        </a>
        <?php if (sm_can_delete()): ?>
        <a href="<?= APP_URL ?>/students/merge-duplicates.php" class="btn btn-outline-danger" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-compress-arrows-alt me-1"></i> Merge Duplicates
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/students/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-user-plus me-1"></i> Add Student
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#3a6fd8);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $total_students ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#28a745,#1d7a34);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $stats['Active'] ?? 0 ?></div>
                    <div class="stat-label">Active</div>
                </div>
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#17a2b8,#117a8b);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= $stats['Graduated'] ?? 0 ?></div>
                    <div class="stat-label">Graduated</div>
                </div>
                <div class="stat-icon"><i class="fas fa-graduation-cap"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#dc3545,#a71d2a);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= ($stats['Dropped'] ?? 0) + ($stats['Inactive'] ?? 0) ?></div>
                    <div class="stat-label">Dropped / Inactive</div>
                </div>
                <div class="stat-icon"><i class="fas fa-user-times"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="ID, name, email, phone…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Department</label>
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
                <label class="form-label fw-semibold" style="font-size:.8rem;">Program</label>
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
                <label class="form-label fw-semibold" style="font-size:.8rem;">Batch</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $f_batch == $b['id'] ? 'selected' : '' ?>>
                        <?= h($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <?php foreach ($valid_statuses as $s): ?>
                    <option value="<?= $s ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Semester</label>
                <input type="text" name="semester" class="form-control form-control-sm"
                       placeholder="e.g. Fall 2025" value="<?= h($f_sem) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Semester Type</label>
                <select name="sem_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="bi_semester" <?= $f_sem_type === 'bi_semester' ? 'selected' : '' ?>>Bi Semester</option>
                    <option value="trimester"   <?= $f_sem_type === 'trimester'   ? 'selected' : '' ?>>Trimester</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Section</label>
                <select name="section" class="form-select form-select-sm">
                    <option value="">All Sections</option>
                    <?php foreach ($valid_sections as $sec): ?>
                    <option value="<?= $sec ?>" <?= $f_section === $sec ? 'selected' : '' ?>><?= $sec ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Shift</label>
                <select name="shift" class="form-select form-select-sm">
                    <option value="">All Shifts</option>
                    <?php foreach ($valid_shifts as $sh): ?>
                    <option value="<?= $sh ?>" <?= $f_shift === $sh ? 'selected' : '' ?>><?= $sh ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Gender</label>
                <select name="gender" class="form-select form-select-sm">
                    <option value="">All Genders</option>
                    <?php foreach ($valid_genders as $g): ?>
                    <option value="<?= $g ?>" <?= $f_gender === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Blood Group</label>
                <select name="blood" class="form-select form-select-sm">
                    <option value="">All Blood Groups</option>
                    <?php foreach ($valid_bloods as $bg): ?>
                    <option value="<?= $bg ?>" <?= $f_blood === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:7px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:7px;">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<?php if ($is_staff): ?>
<!-- Bulk Quick Update -->
<form method="POST" action="<?= APP_URL ?>/students/bulk-update.php" id="bulk-update-form"
      onsubmit="return smBulkPrepare(this);">
    <?= csrf_field() ?>
    <input type="hidden" name="ret" value="<?= h(http_build_query($_GET)) ?>">
    <div id="bulk-ids-container"></div>
    <div class="card mb-3 border-primary" id="bulk-update-bar" style="display:none;">
        <div class="card-body py-3 px-4">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-auto">
                    <span class="fw-semibold"><i class="fas fa-bolt text-primary me-1"></i>Quick Update</span>
                    <span class="text-muted ms-1"><span id="bulk-count">0</span> selected</span>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">— keep —</option>
                        <?php foreach ($valid_statuses as $s): ?>
                        <option value="<?= $s ?>"><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">Shift</label>
                    <select name="shift" class="form-select form-select-sm">
                        <option value="">— keep —</option>
                        <?php foreach ($valid_shifts as $sh): ?>
                        <option value="<?= $sh ?>"><?= $sh ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">Section</label>
                    <select name="section" class="form-select form-select-sm">
                        <option value="">— keep —</option>
                        <?php foreach ($valid_sections as $sec): ?>
                        <option value="<?= $sec ?>"><?= $sec ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm" style="border-radius:7px;">
                        <i class="fas fa-check me-1"></i> Apply to Selected
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="bulk-clear" style="border-radius:7px;">
                        Clear
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>

<!-- Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-graduate me-2 text-muted"></i>Students</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $total_rows ?> result<?= $total_rows !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if ($is_staff): ?>
                        <th class="ps-4" style="width:34px;">
                            <input type="checkbox" class="form-check-input" id="bulk-select-all" aria-label="Select all students on this page" title="Select all on this page">
                        </th>
                        <th style="width:40px;">#</th>
                        <?php else: ?>
                        <th class="px-4" style="width:40px;">#</th>
                        <?php endif; ?>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Department / Program</th>
                        <th>Admitted</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="<?= $is_staff ? 9 : 8 ?>" class="text-center text-muted py-4">
                        No students found.
                        <?php if (sm_can_create()): ?>
                            <a href="<?= APP_URL ?>/students/create.php">Add the first one</a>.
                        <?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php $ret = http_build_query($_GET); ?>
                    <?php foreach ($students as $i => $s): ?>
                    <?php $upd_badge = sm_recently_updated_badge($s['updated_at'] ?? null, $s['created_at'] ?? null); ?>
                    <tr<?= $upd_badge !== '' ? ' class="table-warning" aria-label="Recently updated student record"' : '' ?>>
                        <?php if ($is_staff): ?>
                        <td class="ps-4">
                            <input type="checkbox" class="form-check-input bulk-row-check" value="<?= (int)$s['id'] ?>" aria-label="Select <?= h($s['full_name']) ?>">
                        </td>
                        <td><?= $offset + $i + 1 ?></td>
                        <?php else: ?>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <?php endif; ?>
                        <td><code class="text-primary"><?= h($s['student_id']) ?></code></td>
                        <td>
                            <div class="fw-medium"><?= h($s['full_name']) ?></div>
                            <?php if ($upd_badge !== ''): ?>
                            <div class="mt-1"><?= $upd_badge ?></div>
                            <?php endif; ?>
                            <?php if ($s['photo']): ?>
                            <small class="text-muted"><i class="fas fa-image me-1"></i>Photo on file</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= h($s['dept_name']) ?></div>
                            <?php if ($s['program_name']): ?>
                            <small class="text-muted"><?= h($s['program_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= h($s['admitted_semester']) ?>
                            <?php if (!empty($s['semester_type'])): ?>
                            <br><small class="text-muted"><?= h(sm_semester_type_label($s['semester_type'], true)) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['phone']): ?>
                            <div><i class="fas fa-phone fa-xs text-muted me-1"></i><?= h($s['phone']) ?></div>
                            <?php endif; ?>
                            <?php if ($s['email']): ?>
                            <div><i class="fas fa-envelope fa-xs text-muted me-1"></i><small><?= h($s['email']) ?></small></div>
                            <?php endif; ?>
                        </td>
                        <td><?= sm_status_badge($s['status']) ?></td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/students/view.php?<?= h(http_build_query(['id' => $s['id'], 'ret' => $ret])) ?>"
                                   class="btn btn-sm btn-outline-info" title="View" style="border-radius:7px;">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (sm_is_staff()): ?>
                                <a href="<?= APP_URL ?>/students/edit.php?<?= h(http_build_query(['id' => $s['id'], 'ret' => $ret])) ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (sm_can_delete()): ?>
                                <?php if ((int)($s['payment_count'] ?? 0) > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                        title="This student has recorded payments or vouchers and cannot be deleted." style="border-radius:7px;">
                                    <i class="fas fa-lock"></i>
                                </button>
                                <?php else: ?>
                                <form method="POST" action="<?= APP_URL ?>/students/delete.php"
                                      onsubmit="return confirm('Delete student &quot;<?= h($s['full_name']) ?>&quot;? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?>
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0 flex-wrap">
                <?php
                $qp    = $_GET;
                $range = 2;

                $qp['page'] = max(1, $page - 1);
                ?>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>" aria-label="Previous">&laquo;</a>
                </li>
                <?php
                for ($p = 1; $p <= $total_pages; $p++):
                    if ($p === 1 || $p === $total_pages || abs($p - $page) <= $range):
                        $qp['page'] = $p;
                        $active = $p === $page;
                ?>
                <li class="page-item <?= $active ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $p ?></a>
                </li>
                <?php elseif (abs($p - $page) === $range + 1): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; endfor; ?>
                <?php $qp['page'] = min($total_pages, $page + 1); ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>" aria-label="Next">&raquo;</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
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

// ── Bulk quick-update selection ───────────────────────────────────────────────
(function () {
    var bar     = document.getElementById('bulk-update-bar');
    var form    = document.getElementById('bulk-update-form');
    if (!bar || !form) return;

    var selectAll = document.getElementById('bulk-select-all');
    var countEl   = document.getElementById('bulk-count');
    var clearBtn  = document.getElementById('bulk-clear');

    // Persist selected student ids across pagination (page reloads) so the
    // marks are not lost when moving between pages.
    var STORAGE_KEY = 'sm_bulk_selected_ids';

    function loadSelected() {
        var set = {};
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            if (raw) {
                JSON.parse(raw).forEach(function (id) { set[String(id)] = true; });
            }
        } catch (e) { /* ignore storage/parse errors */ }
        return set;
    }
    function saveSelected(set) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(Object.keys(set)));
        } catch (e) { /* ignore storage errors */ }
    }

    var selected = loadSelected();

    function selectedCount() {
        return Object.keys(selected).length;
    }
    function rowChecks() {
        return Array.prototype.slice.call(document.querySelectorAll('.bulk-row-check'));
    }
    function refresh() {
        var count = selectedCount();
        countEl.textContent = count;
        bar.style.display = count > 0 ? '' : 'none';
        if (selectAll) {
            var all = rowChecks();
            var checkedOnPage = all.filter(function (c) { return c.checked; }).length;
            selectAll.checked = all.length > 0 && checkedOnPage === all.length;
            selectAll.indeterminate = checkedOnPage > 0 && checkedOnPage < all.length;
        }
    }

    // Restore checkbox state for the current page from the stored selection.
    rowChecks().forEach(function (c) {
        if (selected[c.value]) c.checked = true;
        c.addEventListener('change', function () {
            if (c.checked) { selected[c.value] = true; }
            else { delete selected[c.value]; }
            saveSelected(selected);
            refresh();
        });
    });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rowChecks().forEach(function (c) {
                c.checked = selectAll.checked;
                if (c.checked) { selected[c.value] = true; }
                else { delete selected[c.value]; }
            });
            saveSelected(selected);
            refresh();
        });
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            selected = {};
            saveSelected(selected);
            rowChecks().forEach(function (c) { c.checked = false; });
            if (selectAll) selectAll.checked = false;
            refresh();
        });
    }

    // Inject selected ids (from all pages) as hidden inputs on submit.
    window.smBulkPrepare = function (f) {
        var container = document.getElementById('bulk-ids-container');
        container.innerHTML = '';
        var ids = Object.keys(selected);
        if (ids.length === 0) {
            alert('Select at least one student first.');
            return false;
        }
        var status  = f.querySelector('[name="status"]').value;
        var shift   = f.querySelector('[name="shift"]').value;
        var section = f.querySelector('[name="section"]').value;
        if (!status && !shift && !section) {
            alert('Choose at least one field (Status, Shift or Section) to update.');
            return false;
        }
        ids.forEach(function (id) {
            var input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'ids[]';
            input.value = id;
            container.appendChild(input);
        });
        if (!confirm('Apply the quick update to ' + ids.length + ' selected student(s)?')) {
            return false;
        }
        // Clear the persisted selection once the update is submitted.
        try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
        return true;
    };

    refresh();
}());
</script>
