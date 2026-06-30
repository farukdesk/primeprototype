<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('semester-drop');
require_once __DIR__ . '/helpers.php';

$page_title = 'Semester Drop / Dropout';
$db         = db();

// ── Filters ─────────────────────────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$f_status  = trim($_GET['status'] ?? '');
$f_type    = trim($_GET['type'] ?? '');
$f_kind    = trim($_GET['kind'] ?? '');
$f_dept    = (int)($_GET['dept'] ?? 0);
$f_program = (int)($_GET['program'] ?? 0);
$f_batch   = (int)($_GET['batch'] ?? 0);

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(s.full_name LIKE ? OR s.student_id LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($f_status === 'active' || $f_status === 'cancelled') {
    $where[]  = 'd.status = ?';
    $params[] = $f_status;
}
if ($f_kind === 'drop' || $f_kind === 'dropout') {
    $where[]  = 'd.kind = ?';
    $params[] = $f_kind;
}
if ($f_type === 'bi' || $f_type === 'tri') {
    $where[]  = 'd.semester_type = ?';
    $params[] = $f_type;
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

$where_sql = implode(' AND ', $where);

// ── Pagination ──────────────────────────────────────────────────────────────
$per_page = 25;
$page     = max(1, (int)($_GET['page'] ?? 1));

$cnt_stmt = $db->prepare(
    "SELECT COUNT(*)
       FROM semester_drops d
       JOIN students s ON s.id = d.student_id
      WHERE $where_sql"
);
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$page  = min($page, $pages);
$off   = ($page - 1) * $per_page;

$stmt = $db->prepare(
    "SELECT d.*, s.full_name AS student_name, s.student_id AS student_sid,
            dep.name AS dept_name, prog.program_name, s.batch AS batch_label,
            cb.full_name AS created_by_name
       FROM semester_drops d
       JOIN students s ON s.id = d.student_id
       LEFT JOIN dept_departments dep ON dep.id = s.dept_id
       LEFT JOIN dept_academic_programs prog ON prog.id = s.program_id
       LEFT JOIN users cb ON cb.id = d.created_by
      WHERE $where_sql
      ORDER BY d.created_at DESC
      LIMIT $per_page OFFSET $off"
);
$stmt->execute($params);
$drops = $stmt->fetchAll();

$today = date('Y-m-d');

// ── Filter option data ──────────────────────────────────────────────────────
$departments = $db->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
$programs = $db->query(
    'SELECT id, program_name, dept_id FROM dept_academic_programs ORDER BY program_name ASC'
)->fetchAll();
$batches = $db->query(
    'SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC'
)->fetchAll();

$qs = ['q' => $search, 'status' => $f_status, 'type' => $f_type, 'kind' => $f_kind,
       'dept' => $f_dept ?: '', 'program' => $f_program ?: '', 'batch' => $f_batch ?: ''];
$has_filter = $search !== '' || $f_status !== '' || $f_type !== '' || $f_kind !== ''
    || $f_dept > 0 || $f_program > 0 || $f_batch > 0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-pause-circle me-2 text-warning"></i>Semester Drop / Dropout</h1>
        <p class="text-muted mb-0 small">Semester drops pause monthly dues; dropouts freeze the account entirely.</p>
    </div>
    <?php if (sd_can_create()): ?>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/semester-drop/create.php" class="btn btn-warning btn-sm">
            <i class="fas fa-plus me-1"></i> New Semester Drop
        </a>
        <a href="<?= APP_URL ?>/semester-drop/create-dropout.php" class="btn btn-dark btn-sm">
            <i class="fas fa-user-slash me-1"></i> Add Dropout Student
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
                <label class="form-label fw-semibold small mb-1">Search student</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Student name or ID…"
                       value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Record</label>
                <select name="kind" class="form-select form-select-sm">
                    <option value="">All Records</option>
                    <option value="drop"    <?= $f_kind === 'drop'    ? 'selected' : '' ?>>Semester Drop</option>
                    <option value="dropout" <?= $f_kind === 'dropout' ? 'selected' : '' ?>>Dropout</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">Department</label>
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dep): ?>
                    <option value="<?= (int)$dep['id'] ?>" <?= $f_dept === (int)$dep['id'] ? 'selected' : '' ?>>
                        <?= h($dep['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">Program</label>
                <select name="program" class="form-select form-select-sm">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $prog): ?>
                    <option value="<?= (int)$prog['id'] ?>"
                            <?= $f_program === (int)$prog['id'] ? 'selected' : '' ?>>
                        <?= h($prog['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Batch</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= h($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="bi"  <?= $f_type === 'bi'  ? 'selected' : '' ?>>Bi-semester (6 mo)</option>
                    <option value="tri" <?= $f_type === 'tri' ? 'selected' : '' ?>>Tri-semester (4 mo)</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="active"    <?= $f_status === 'active'    ? 'selected' : '' ?>>Active</option>
                    <option value="cancelled" <?= $f_status === 'cancelled' ? 'selected' : '' ?>>Cancelled / Re-instated</option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button class="btn btn-primary btn-sm flex-fill" type="submit"><i class="fas fa-search me-1"></i>Filter</button>
                <?php if ($has_filter): ?>
                <a href="<?= APP_URL ?>/semester-drop/index.php" class="btn btn-outline-secondary btn-sm flex-fill">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Table ── -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($drops)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-pause-circle fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No records found.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Department / Program</th>
                        <th>Batch</th>
                        <th>Record</th>
                        <th>Effective</th>
                        <th>Status</th>
                        <th>Recorded by</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($drops as $d): ?>
                <?php
                    $is_dropout = ($d['kind'] ?? 'drop') === 'dropout';
                    $is_current = !$is_dropout && $d['status'] === 'active'
                        && $d['drop_start'] <= $today && $today <= $d['drop_end'];
                ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$d['student_id'] ?>"
                           class="fw-semibold text-decoration-none">
                            <?= h($d['student_name']) ?>
                        </a><br>
                        <small class="text-muted"><?= h($d['student_sid']) ?></small>
                    </td>
                    <td>
                        <div><?= h($d['dept_name'] ?? '—') ?></div>
                        <?php if (!empty($d['program_name'])): ?>
                        <small class="text-muted"><?= h($d['program_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-muted"><?= h($d['batch_label'] ?? '—') ?></small></td>
                    <td>
                        <?php if ($is_dropout): ?>
                        <span class="badge bg-dark"><i class="fas fa-user-slash me-1"></i>Dropout</span>
                        <?php else: ?>
                        <span class="badge bg-info text-dark"><?= h(sd_type_label($d['semester_type'])) ?></span>
                        <small class="text-muted d-block"><?= (int)$d['block_months'] ?> months</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= h(date('d M Y', strtotime($d['drop_start']))) ?>
                        <?php if (!$is_dropout): ?>
                        <small class="text-muted d-block">→ <?= h(date('d M Y', strtotime($d['drop_end']))) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($is_dropout): ?>
                            <?php if ($d['status'] === 'active'): ?>
                            <span class="badge bg-dark"><i class="fas fa-snowflake me-1"></i>Frozen</span>
                            <?php else: ?>
                            <span class="badge bg-success"><i class="fas fa-undo me-1"></i>Re-instated</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= sd_status_badge($d['status']) ?>
                            <?php if ($is_current): ?>
                            <span class="badge bg-warning text-dark"><i class="fas fa-circle me-1" style="font-size:.5rem;"></i>On break now</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><small class="text-muted"><?= h($d['created_by_name'] ?? '—') ?></small></td>
                    <td class="text-end">
                        <a href="<?= APP_URL ?>/semester-drop/view.php?id=<?= (int)$d['id'] ?>"
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-eye me-1"></i>View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
            <small class="text-muted">Page <?= $page ?> of <?= $pages ?> &middot; <?= $total ?> total</small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link"
                           href="?<?= http_build_query(array_merge($qs, ['page' => $p])) ?>">
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
