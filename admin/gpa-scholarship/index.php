<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gpa-scholarship');

$page_title = 'GPA Scholarship';

$where = ['1=1'];
$params = [];

if (!empty($_GET['exam_id'])) {
    $where[] = 'c.exam_id = ?';
    $params[] = (int)$_GET['exam_id'];
}
if (isset($_GET['active']) && $_GET['active'] !== '') {
    $where[] = 'c.is_active = ?';
    $params[] = (int)($_GET['active'] === '1');
}

$scope = get_dept_scope();
if (!is_super_admin() && $scope !== null) {
    if (empty($scope)) {
        $where[] = '1=0';
    } else {
        $ph = implode(',', array_fill(0, count($scope), '?'));
        $where[] = "c.dept_id IN ($ph)";
        foreach ($scope as $sid) $params[] = (int)$sid;
    }
}

$sql = 'SELECT c.*,
              d.name AS dept_name,
              p.program_name,
              b.name AS batch_name,
              (SELECT COUNT(*) FROM gpa_scholarship_tiers t WHERE t.config_id = c.id AND t.metric = "gpa") AS gpa_tier_count,
              (SELECT COUNT(*) FROM gpa_scholarship_tiers t WHERE t.config_id = c.id AND t.metric = "attendance") AS attendance_tier_count,
              (SELECT COUNT(*) FROM gpa_scholarship_awards a WHERE a.config_id = c.id AND a.status = "applied") AS applied_count,
              (SELECT COUNT(*) FROM gpa_scholarship_awards a WHERE a.config_id = c.id AND a.status = "failed") AS failed_count
        FROM gpa_scholarship_configs c
        LEFT JOIN dept_departments d ON d.id = c.dept_id
        LEFT JOIN dept_academic_programs p ON p.id = c.program_id
        LEFT JOIN student_batches b ON b.id = c.batch_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY c.id DESC';

$st = db()->prepare($sql);
$st->execute($params);
$configs = $st->fetchAll();
foreach ($configs as &$cfg) {
    $exam = gs_get_exam((int)$cfg['exam_id']);
    $cfg['exam_title'] = $exam ? gs_format_exam_title($exam) : ('Exam #' . (int)$cfg['exam_id']);
}
unset($cfg);
$exam_options = gs_exam_options();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-award me-2 text-primary"></i>GPA Scholarship</h1>
        <div class="text-muted small">Configurable merit scholarship from invigilation exam GPA and attendance.</div>
    </div>
    <?php if (gs_can_create()): ?>
    <a href="<?= APP_URL ?>/gpa-scholarship/create.php" class="btn btn-primary btn-sm">
        <i class="fas fa-plus me-1"></i> New Setup
    </a>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Exam</label>
                <select name="exam_id" class="form-select form-select-sm">
                    <option value="">All invigilation exams</option>
                    <?php foreach ($exam_options as $ex): ?>
                    <option value="<?= (int)$ex['id'] ?>" <?= (int)($_GET['exam_id'] ?? 0) === (int)$ex['id'] ? 'selected' : '' ?>>
                        <?= h($ex['exam_title']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="active" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="1" <?= ($_GET['active'] ?? '') === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= ($_GET['active'] ?? '') === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button class="btn btn-primary btn-sm w-100"><i class="fas fa-filter"></i></button>
                <a href="<?= APP_URL ?>/gpa-scholarship/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Setup</th>
                    <th>Scope</th>
                    <th>Rules</th>
                    <th>Applied</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($configs)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No GPA scholarship setup found.</td></tr>
            <?php else: foreach ($configs as $i => $c): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <div class="fw-semibold"><?= h($c['name']) ?></div>
                        <div class="small text-muted">Exam: <?= h($c['exam_title']) ?> · Target semester #<?= (int)$c['target_semester_number'] ?></div>
                    </td>
                    <td>
                        <div><?= h($c['dept_name'] ?? '—') ?></div>
                        <div class="small text-muted"><?= h($c['program_name'] ?? 'All programs') ?> · <?= h($c['batch_name'] ?? 'All batches') ?></div>
                    </td>
                    <td>
                        <div class="small">GPA tiers: <strong><?= (int)$c['gpa_tier_count'] ?></strong> · Attendance tiers: <strong><?= (int)$c['attendance_tier_count'] ?></strong></div>
                        <div class="small text-muted">Mode: <?= h(gs_mode_options()[$c['combine_mode']] ?? $c['combine_mode']) ?></div>
                    </td>
                    <td>
                        <span class="badge bg-success"><?= (int)$c['applied_count'] ?></span>
                        <?php if ((int)$c['failed_count'] > 0): ?>
                        <span class="badge bg-danger"><?= (int)$c['failed_count'] ?> failed</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$c['is_active'] === 1): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <a href="<?= APP_URL ?>/gpa-scholarship/view.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">Preview</a>
                        <?php if (gs_can_edit()): ?>
                        <a href="<?= APP_URL ?>/gpa-scholarship/edit.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                        <?php endif; ?>
                        <?php if (gs_can_delete() && (int)$c['is_active'] === 1): ?>
                        <form method="post" action="<?= APP_URL ?>/gpa-scholarship/deactivate.php" class="d-inline" onsubmit="return confirm('Deactivate this setup?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger">Deactivate</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
