<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

$page_title = 'Student Management';
$user       = auth_user();
$is_staff   = sm_is_staff();

// ── Filters ───────────────────────────────────────────────────────────────────
$search   = trim($_GET['search']   ?? '');
$f_dept   = (int)($_GET['dept']    ?? 0);
$f_status = $_GET['status']        ?? '';
$f_sem    = trim($_GET['semester'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$valid_statuses = ['Active', 'Inactive', 'Graduated', 'Dropped'];

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

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count_stmt = db()->prepare('SELECT COUNT(*) FROM students s' . $where_sql);
$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$sql = 'SELECT s.*,
               d.name AS dept_name,
               p.program_name
        FROM students s
        JOIN dept_departments d ON d.id = s.dept_id
        LEFT JOIN dept_academic_programs p ON p.id = s.program_id'
     . $where_sql
     . ' ORDER BY s.created_at DESC LIMIT ' . $per_page . ' OFFSET ' . $offset;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats_rows = db()->query(
    'SELECT status, COUNT(*) AS cnt FROM students GROUP BY status'
)->fetchAll();
$stats = array_column($stats_rows, 'cnt', 'status');
$total_students = array_sum($stats);

// ── Departments for filter ────────────────────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

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
    <a href="<?= APP_URL ?>/students/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-user-plus me-1"></i> Add Student
    </a>
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
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="ID, name, email, phone…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Department</label>
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All Depts</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
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
                        <th class="px-4" style="width:40px;">#</th>
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
                    <tr><td colspan="8" class="text-center text-muted py-4">
                        No students found.
                        <?php if (sm_can_create()): ?>
                            <a href="<?= APP_URL ?>/students/create.php">Add the first one</a>.
                        <?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($students as $i => $s): ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td><code class="text-primary"><?= h($s['student_id']) ?></code></td>
                        <td>
                            <div class="fw-medium"><?= h($s['full_name']) ?></div>
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
                        <td><?= h($s['admitted_semester']) ?></td>
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
                                <a href="<?= APP_URL ?>/students/view.php?id=<?= $s['id'] ?>"
                                   class="btn btn-sm btn-outline-info" title="View" style="border-radius:7px;">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (sm_is_staff()): ?>
                                <a href="<?= APP_URL ?>/students/edit.php?id=<?= $s['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (sm_can_delete()): ?>
                                <form method="POST" action="<?= APP_URL ?>/students/delete.php"
                                      onsubmit="return confirm('Delete student &quot;<?= h(addslashes($s['full_name'])) ?>&quot;? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
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
    <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?>
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $qp = $_GET;
                for ($p = 1; $p <= $total_pages; $p++):
                    $qp['page'] = $p;
                    $active = $p === $page;
                ?>
                <li class="page-item <?= $active ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
