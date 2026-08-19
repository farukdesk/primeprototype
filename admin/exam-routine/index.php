<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-routine');
require_once __DIR__ . '/helpers.php';

$page_title = 'Exam Routines';

$filter_exam = (int)($_GET['exam_id'] ?? 0);
$filter_dept = (int)($_GET['dept_id'] ?? 0);

$exams       = er_active_exams();
$departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC')->fetchAll();

$where  = [];
$params = [];
if ($filter_exam > 0) { $where[] = 'r.exam_id = ?'; $params[] = $filter_exam; }
if ($filter_dept > 0) { $where[] = 'r.dept_id = ?'; $params[] = $filter_dept; }
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$st = db()->prepare(
    "SELECT r.*, e.exam_name, e.exam_year,
            d.name AS dept_name, p.program_name, b.name AS batch_name,
            (SELECT COUNT(*) FROM exam_routine_items i WHERE i.routine_id = r.id)      AS item_count,
            (SELECT COALESCE(SUM(i.student_count),0) FROM exam_routine_items i WHERE i.routine_id = r.id) AS total_students,
            u.full_name AS created_by_name
       FROM exam_routines r
       JOIN ei_exams e                ON e.id = r.exam_id
       JOIN dept_departments d        ON d.id = r.dept_id
  LEFT JOIN dept_academic_programs p  ON p.id = r.program_id
  LEFT JOIN student_batches b         ON b.id = r.batch_id
  LEFT JOIN users u                   ON u.id = r.created_by
      $where_sql
      ORDER BY r.created_at DESC"
);
$st->execute($params);
$routines = $st->fetchAll();

$can_delete = is_super_admin() || can_access('exam-routine', 'can_delete');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Exam Routine</li>
        </ol>
    </nav>
    <?php if (is_super_admin() || can_access('exam-routine', 'can_create')): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/exam-routine/import.php" class="btn btn-outline-primary" style="border-radius:10px;">
            <i class="fas fa-file-csv me-1"></i> Import CSV
        </a>
        <a href="<?= APP_URL ?>/exam-routine/create.php" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> New Routine
        </a>
    </div>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-medium mb-1">Exam</label>
                <select name="exam_id" class="form-select form-select-sm">
                    <option value="">All exams</option>
                    <?php foreach ($exams as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $filter_exam === (int)$e['id'] ? 'selected' : '' ?>>
                        <?= h($e['exam_name']) ?><?= $e['exam_year'] ? ' – ' . h($e['exam_year']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-medium mb-1">Department</label>
                <select name="dept_id" class="form-select form-select-sm">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $filter_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-filter me-1"></i> Filter</button>
                <a href="<?= APP_URL ?>/exam-routine/index.php" class="btn btn-sm btn-light" style="border-radius:8px;">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if ($can_delete): ?>
<!-- Bulk-delete form: row checkboxes reference it via the HTML form attribute,
     so the per-row delete forms inside the table stay valid (no nesting). -->
<form id="bulkDeleteForm" method="POST" action="<?= APP_URL ?>/exam-routine/delete.php"
      onsubmit="return erBulkConfirm();">
    <?= csrf_field() ?>
</form>
<?php endif; ?>

<div class="card" style="border-radius:12px;">
    <?php if ($can_delete): ?>
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center py-2 px-4">
        <small class="text-muted">Select routines to delete them in bulk.</small>
        <button type="submit" form="bulkDeleteForm" id="erBulkBtn" class="btn btn-sm btn-outline-danger"
                style="border-radius:8px;" disabled>
            <i class="fas fa-trash me-1"></i> Delete Selected
        </button>
    </div>
    <?php endif; ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if ($can_delete): ?>
                        <th class="ps-4" style="width:36px;">
                            <input type="checkbox" class="form-check-input" id="erCheckAll" title="Select all">
                        </th>
                        <?php endif; ?>
                        <th class="<?= $can_delete ? '' : 'ps-4' ?>">Exam</th>
                        <th>Department / Program</th>
                        <th>Class</th>
                        <th class="text-center">Subjects</th>
                        <th class="text-center">Students</th>
                        <th>Created</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($routines)): ?>
                    <tr><td colspan="<?= $can_delete ? 8 : 7 ?>" class="text-center text-muted py-5">
                        No exam routines yet.
                        <?php if (is_super_admin() || can_access('exam-routine', 'can_create')): ?>
                        <a href="<?= APP_URL ?>/exam-routine/create.php">Create the first one</a>.
                        <?php endif; ?>
                    </td></tr>
                <?php else: foreach ($routines as $r): ?>
                    <tr>
                        <?php if ($can_delete): ?>
                        <td class="ps-4">
                            <input type="checkbox" class="form-check-input er-check"
                                   name="ids[]" value="<?= (int)$r['id'] ?>" form="bulkDeleteForm">
                        </td>
                        <?php endif; ?>
                        <td class="<?= $can_delete ? '' : 'ps-4' ?>">
                            <a href="<?= APP_URL ?>/exam-routine/view.php?id=<?= $r['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= h($r['exam_name']) ?><?= $r['exam_year'] ? ' – ' . h($r['exam_year']) : '' ?>
                            </a>
                        </td>
                        <td>
                            <?= h($r['dept_name']) ?>
                            <?php if ($r['program_name']): ?><div class="small text-muted"><?= h($r['program_name']) ?></div><?php endif; ?>
                        </td>
                        <td class="small"><?= h(er_context_label($r)) ?: '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= (int)$r['item_count'] ?></span></td>
                        <td class="text-center"><?= (int)$r['total_students'] ?></td>
                        <td class="small text-muted">
                            <?= h(date('d M Y', strtotime($r['created_at']))) ?>
                            <?php if ($r['created_by_name']): ?><br><?= h($r['created_by_name']) ?><?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <a href="<?= APP_URL ?>/exam-routine/view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-light" title="View"><i class="fas fa-eye"></i></a>
                            <a href="<?= APP_URL ?>/exam-routine/print.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-light" title="Print"><i class="fas fa-print"></i></a>
                            <?php if (is_super_admin() || can_access('exam-routine', 'can_edit')): ?>
                            <a href="<?= APP_URL ?>/exam-routine/create.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-light" title="Edit"><i class="fas fa-pen"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <form method="POST" action="<?= APP_URL ?>/exam-routine/delete.php" class="d-inline"
                                  onsubmit="return confirm('Delete this routine and all of its rows?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button class="btn btn-sm btn-light text-danger" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($can_delete): ?>
<script>
(function () {
    var all   = document.getElementById('erCheckAll');
    var btn   = document.getElementById('erBulkBtn');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.er-check'));

    function refresh() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        btn.disabled = n === 0;
        btn.innerHTML = '<i class="fas fa-trash me-1"></i> Delete Selected' + (n ? ' (' + n + ')' : '');
        if (all) {
            all.checked = boxes.length > 0 && n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
        }
    }

    if (all) {
        all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
            refresh();
        });
    }
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    refresh();
})();

function erBulkConfirm() {
    var n = document.querySelectorAll('.er-check:checked').length;
    if (n === 0) { alert('Select at least one routine.'); return false; }
    return confirm('Delete ' + n + ' selected routine(s) and all of their rows? This cannot be undone.');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
