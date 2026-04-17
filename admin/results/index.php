<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('results');
require_once __DIR__ . '/helpers.php';

$page_title = 'Result Management';

// ── Filters ───────────────────────────────────────────────────────────────────
$f_dept    = (int)($_GET['dept_id']  ?? 0);
$f_program = (int)($_GET['program_id'] ?? 0);
$f_batch   = trim($_GET['batch'] ?? '');
$f_pub     = $_GET['published'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 20;

$where  = [];
$params = [];

if ($f_dept > 0)    { $where[] = 'e.dept_id = ?';    $params[] = $f_dept; }
if ($f_program > 0) { $where[] = 'e.program_id = ?'; $params[] = $f_program; }
if ($f_batch !== '') { $where[] = 'e.batch = ?';     $params[] = $f_batch; }
if ($f_pub === '1') { $where[] = 'e.is_published = 1'; }
if ($f_pub === '0') { $where[] = 'e.is_published = 0'; }

// Apply department scope
$dept_scope = get_dept_scope();
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $where[] = '0 = 1';
    } else {
        $phs     = implode(',', array_fill(0, count($dept_scope), '?'));
        $where[] = "e.dept_id IN ($phs)";
        array_push($params, ...$dept_scope);
    }
}

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count_stmt = db()->prepare('SELECT COUNT(*) FROM result_exams e' . $where_sql);
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = db()->prepare(
    'SELECT e.*,
            d.name AS dept_name,
            p.program_name,
            (SELECT COUNT(*) FROM result_subjects rs WHERE rs.exam_id = e.id) AS subject_count,
            (SELECT COUNT(DISTINCT rg.student_sid) FROM result_grades rg WHERE rg.exam_id = e.id) AS student_count
     FROM result_exams e
     JOIN dept_departments d ON d.id = e.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = e.program_id'
    . $where_sql
    . ' ORDER BY e.created_at DESC LIMIT ' . $per_page . ' OFFSET ' . $offset
);
$stmt->execute($params);
$exams = $stmt->fetchAll();

// Dept list for filter
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
if ($dept_scope !== null) {
    $departments = array_values(array_filter(
        $departments,
        fn($d) => in_array((int)$d['id'], $dept_scope, true)
    ));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Results</li>
        </ol>
    </nav>
    <?php if (rm_can_create()): ?>
    <a href="<?= APP_URL ?>/results/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Result Exam
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Department</label>
                <select name="dept_id" id="f_dept" class="form-select form-select-sm">
                    <option value="">All Depts</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Batch</label>
                <input type="text" name="batch" class="form-control form-control-sm"
                       placeholder="e.g. 52nd" value="<?= h($f_batch) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Status</label>
                <select name="published" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="1" <?= $f_pub === '1' ? 'selected' : '' ?>>Published</option>
                    <option value="0" <?= $f_pub === '0' ? 'selected' : '' ?>>Draft</option>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:7px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/results/index.php" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:7px;">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-chart-bar me-2 text-muted"></i>Result Exams</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $total_rows ?> result<?= $total_rows !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Exam Title</th>
                        <th>Department / Program</th>
                        <th>Batch</th>
                        <th class="text-center">Subjects</th>
                        <th class="text-center">Students</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($exams)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">
                        No result exams found.
                        <?php if (rm_can_create()): ?>
                            <a href="<?= APP_URL ?>/results/create.php">Create the first one</a>.
                        <?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($exams as $i => $ex): ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="fw-medium"><?= h($ex['exam_title']) ?></div>
                            <?php if ($ex['exam_level']): ?>
                            <small class="text-muted"><?= h($ex['exam_level']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= h($ex['dept_name']) ?></div>
                            <?php if ($ex['program_name']): ?>
                            <small class="text-muted"><?= h($ex['program_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($ex['batch'] ?? '—') ?></td>
                        <td class="text-center">
                            <span class="badge bg-info text-dark"><?= (int)$ex['subject_count'] ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= (int)$ex['student_count'] ?></span>
                        </td>
                        <td>
                            <?php if ($ex['is_published']): ?>
                            <span class="badge bg-success">Published</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/results/view.php?id=<?= $ex['id'] ?>"
                                   class="btn btn-sm btn-outline-info" title="View / Enter Grades" style="border-radius:7px;">
                                    <i class="fas fa-table"></i>
                                </a>
                                <a href="<?= APP_URL ?>/results/print.php?id=<?= $ex['id'] ?>"
                                   target="_blank"
                                   class="btn btn-sm btn-outline-secondary" title="Print" style="border-radius:7px;">
                                    <i class="fas fa-print"></i>
                                </a>
                                <?php if (rm_is_staff()): ?>
                                <a href="<?= APP_URL ?>/results/edit.php?id=<?= $ex['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (rm_can_delete()): ?>
                                <form method="POST" action="<?= APP_URL ?>/results/delete.php"
                                      onsubmit="return confirm('Delete result exam &quot;<?= h($ex['exam_title']) ?>&quot;? All subjects and grades will be removed.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $ex['id'] ?>">
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
