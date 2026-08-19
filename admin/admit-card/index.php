<?php
/**
 * Admit Card – Admin Index
 * Lists all admit card batches with search/filter.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card');
require_once __DIR__ . '/helpers.php';

$page_title = 'Admit Cards';

$f_search  = trim($_GET['search'] ?? '');
$f_student = trim($_GET['student_id'] ?? '');
$per_page  = 25;
$cur_page  = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($cur_page - 1) * $per_page;

$db = db();
$where  = '1=1';
$params = [];

// ── Find a student's admit cards by their Student ID (includes inactive cards) ──
$f_student_row = null;
if ($f_student !== '') {
    $st = $db->prepare(
        'SELECT id, student_id, full_name, status, dept_id, program_id, batch_id
         FROM students WHERE student_id = ? LIMIT 1'
    );
    $st->execute([$f_student]);
    $f_student_row = $st->fetch();

    if ($f_student_row) {
        $sid = (int)$f_student_row['id'];

        // Optional routine link column (see admin/admit-card-routine-link.sql)
        $has_routine_col = false;
        try { $db->query('SELECT routine_id FROM ac_admit_cards LIMIT 1'); $has_routine_col = true; } catch (Throwable $e) {}

        // Cards matching the student's dept + program (+ batch when the card is batch-specific)
        $cond    = '(ac.dept_id = ? AND ac.program_id = ? AND (ac.batch_id IS NULL OR ac.batch_id = ?))';
        $cparams = [(int)$f_student_row['dept_id'], (int)$f_student_row['program_id'], (int)($f_student_row['batch_id'] ?? 0)];

        // Routine-linked cards where the student is registered in a routine course
        if ($has_routine_col) {
            $cond .= ' OR (ac.routine_id IS NOT NULL AND ac.routine_id IN (
                            SELECT i.routine_id
                              FROM exam_routine_items i
                              JOIN co_registrations r ON r.offer_subject_id = i.offer_subject_id
                             WHERE r.student_id = ?))';
            $cparams[] = $sid;
        }

        // Cards where the student has an admin override
        $cond .= ' OR ac.id IN (SELECT admit_card_id FROM ac_student_overrides WHERE student_id = ?)';
        $cparams[] = $sid;

        $where .= " AND ($cond)";
        $params = array_merge($params, $cparams);
    } else {
        $where .= ' AND 1=0'; // unknown student → no results
    }
}
if ($f_search !== '') {
    $where .= ' AND (ac.exam_name LIKE ? OR ac.semester LIKE ? OR d.name LIKE ? OR p.program_name LIKE ?';
    $like = '%' . $f_search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
    if (ctype_digit($f_search)) {
        // A purely numeric search also matches the card ID
        $where .= ' OR ac.id = ?';
        $params[] = (int)$f_search;
    }
    $where .= ')';
}

$cnt_stmt = $db->prepare(
    "SELECT COUNT(*) FROM ac_admit_cards ac
     JOIN dept_departments d ON d.id = ac.dept_id
     JOIN dept_academic_programs p ON p.id = ac.program_id
     WHERE $where"
);
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pages = (int)ceil($total / $per_page);

$rows_stmt = $db->prepare(
    "SELECT ac.*,
            d.name AS dept_name,
            p.program_name,
            b.name AS batch_name_db,
            u.full_name AS created_by_name,
            (SELECT COUNT(*) FROM ac_admit_card_courses cc WHERE cc.admit_card_id = ac.id) AS course_count
     FROM ac_admit_cards ac
     JOIN dept_departments d ON d.id = ac.dept_id
     JOIN dept_academic_programs p ON p.id = ac.program_id
     LEFT JOIN student_batches b ON b.id = ac.batch_id
     LEFT JOIN users u ON u.id = ac.created_by
     WHERE $where
     ORDER BY ac.created_at DESC
     LIMIT $per_page OFFSET $offset"
);
$rows_stmt->execute($params);
$rows = $rows_stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-primary"></i>Admit Cards</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Admit Cards</li>
        </ol></nav>
    </div>
    <?php if (ac_can_create()): ?>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/admit-card/bulk-import.php" class="btn btn-outline-primary" style="border-radius:10px;">
            <i class="fas fa-file-csv me-1"></i> Bulk Import CSV
        </a>
        <a href="<?= APP_URL ?>/admit-card/create.php" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> New Admit Card
        </a>
    </div>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<!-- Search -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control" placeholder="Search exam name, semester, dept…"
                       value="<?= h($f_search) ?>">
            </div>
            <div class="col-md-3">
                <input type="text" name="student_id" class="form-control" placeholder="Student ID (e.g. 123-456-789)"
                       value="<?= h($f_student) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary"><i class="fas fa-search me-1"></i>Search</button>
                <?php if ($f_search !== '' || $f_student !== ''): ?>
                    <a href="?" class="btn btn-outline-secondary ms-1">Clear</a>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($f_student !== ''): ?>
            <?php if ($f_student_row): ?>
            <div class="alert alert-info small mt-3 mb-0">
                Showing all admit cards (including <strong>inactive</strong> ones) for
                <strong><?= h($f_student_row['full_name']) ?></strong>
                (ID: <strong><?= h($f_student_row['student_id']) ?></strong>,
                Status: <?= h($f_student_row['status']) ?>).
            </div>
            <?php else: ?>
            <div class="alert alert-warning small mt-3 mb-0">
                No student found with ID “<?= h($f_student) ?>”.
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Exam Name</th>
                        <th>Semester</th>
                        <th>Dept / Program</th>
                        <th>Batch</th>
                        <th>Courses</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-5">No admit cards found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="px-4"><?= (int)$row['id'] ?></td>
                        <td class="fw-semibold"><?= h($row['exam_name']) ?></td>
                        <td><?= h($row['semester']) ?></td>
                        <td>
                            <div><?= h($row['dept_name']) ?></div>
                            <small class="text-muted"><?= h($row['program_name']) ?></small>
                        </td>
                        <td><?= h($row['batch_label'] ?? ($row['batch_name_db'] ?? '—')) ?></td>
                        <td><span class="badge bg-secondary"><?= (int)$row['course_count'] ?></span></td>
                        <td>
                            <?php if ($row['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/admit-card/view.php?id=<?= $row['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (ac_can_edit()): ?>
                                <a href="<?= APP_URL ?>/admit-card/edit.php?id=<?= $row['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (ac_can_delete()): ?>
                                <a href="<?= APP_URL ?>/admit-card/delete.php?id=<?= $row['id'] ?>"
                                   class="btn btn-sm btn-outline-danger" title="Delete"
                                   onclick="return confirm('Delete this admit card? This cannot be undone.')">
                                    <i class="fas fa-trash"></i>
                                </a>
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
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between">
        <small class="text-muted">Showing page <?= $cur_page ?> of <?= $pages ?> (<?= $total ?> records)</small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                <li class="page-item <?= $p === $cur_page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($f_search) ?><?= $f_student !== '' ? '&student_id=' . urlencode($f_student) : '' ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
