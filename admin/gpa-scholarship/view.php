<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gpa-scholarship');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$config = gs_get_config_with_relations($id);
if (!$config) {
    flash_set('error', 'Setup not found.');
    redirect(APP_URL . '/gpa-scholarship/index.php');
}

if (!can_access_dept((int)$config['dept_id']) && !is_super_admin()) {
    flash_set('error', 'Access denied for this setup scope.');
    redirect(APP_URL . '/gpa-scholarship/index.php');
}

$page_title = 'GPA Scholarship Preview';

$eval = gs_evaluate_config($config);
$rows = $eval['rows'];
$meta = $eval['meta'];
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'preview') {
        gs_save_preview_snapshot($config, $rows);
        flash_set('success', 'Preview snapshot saved to audit log.');
        redirect(APP_URL . '/gpa-scholarship/view.php?id=' . $id);
    }

    if ($action === 'apply') {
        if (!gs_can_edit()) {
            flash_set('error', 'You do not have permission to apply scholarships.');
            redirect(APP_URL . '/gpa-scholarship/view.php?id=' . $id);
        }

        $summary = gs_apply_evaluations($config, $rows, (int)auth_user()['id']);
        flash_set('success', 'Application run complete: applied ' . (int)$summary['applied'] . ', updated ' . (int)$summary['updated'] . ', reversed ' . (int)$summary['reversed'] . ', skipped ' . (int)$summary['skipped'] . ', failed ' . (int)$summary['failed'] . '.');
        redirect(APP_URL . '/gpa-scholarship/view.php?id=' . $id);
    }
}

$gpa_tiers = gs_get_tiers((int)$config['id'], 'gpa');
$att_tiers = gs_get_tiers((int)$config['id'], 'attendance');

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$total = count($rows);
$pages = max(1, (int)ceil($total / $per_page));
$page = min($page, $pages);
$offset = ($page - 1) * $per_page;
$rows_page = array_slice($rows, $offset, $per_page);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0"><?= h($config['name']) ?></h1>
        <div class="small text-muted">Exam: <?= h($config['exam_title']) ?> · Target semester #<?= (int)$config['target_semester_number'] ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/gpa-scholarship/index.php" class="btn btn-outline-secondary btn-sm">Back</a>
        <?php if (gs_can_edit()): ?>
        <a href="<?= APP_URL ?>/gpa-scholarship/edit.php?id=<?= (int)$config['id'] ?>" class="btn btn-outline-primary btn-sm">Edit</a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<?php if (!empty($meta['error'])): ?>
<div class="alert alert-danger"><?= h($meta['error']) ?></div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Evaluated</div><div class="h4 mb-0"><?= (int)$meta['total'] ?></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Eligible</div><div class="h4 text-success mb-0"><?= (int)$meta['eligible'] ?></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Excluded</div><div class="h4 text-danger mb-0"><?= (int)$meta['excluded'] ?></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm p-3"><div class="small text-muted">Mode</div><div class="small fw-semibold"><?= h(gs_mode_options()[$config['combine_mode']] ?? $config['combine_mode']) ?></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="small text-muted">Scope</div>
                <div class="fw-semibold"><?= h($config['dept_name'] ?? '—') ?> · <?= h($config['program_name'] ?? 'All programs') ?> · <?= h($config['batch_name'] ?? 'All batches') ?></div>
            </div>
            <div class="col-md-6">
                <div class="small text-muted">Filters</div>
                <div>
                    <?= (int)$config['require_merit_payment_type'] ? '<span class="badge bg-primary">Merit payment required</span>' : '<span class="badge bg-secondary">Any payment type</span>' ?>
                    <?= (int)$config['require_gpa_tier'] ? '<span class="badge bg-info text-dark">GPA tier required</span>' : '' ?>
                    <?= (int)$config['require_attendance_tier'] ? '<span class="badge bg-warning text-dark">Attendance tier required</span>' : '' ?>
                </div>
            </div>
        </div>

        <hr>
        <div class="row">
            <div class="col-md-6">
                <h6 class="mb-2">GPA tiers</h6>
                <ul class="small mb-0">
                    <?php foreach ($gpa_tiers as $t): ?>
                    <li><?= h($t['label'] ?: 'Tier') ?>: <?= number_format((float)$t['min_value'], 2) ?>–<?= number_format((float)$t['max_value'], 2) ?> → <strong><?= number_format((float)$t['scholarship_percent'], 2) ?>%</strong> (prio <?= (int)$t['priority'] ?>)</li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="col-md-6">
                <h6 class="mb-2">Attendance tiers</h6>
                <?php if (empty($att_tiers)): ?>
                    <div class="small text-muted">No attendance tiers configured.</div>
                <?php else: ?>
                <ul class="small mb-0">
                    <?php foreach ($att_tiers as $t): ?>
                    <li><?= h($t['label'] ?: 'Tier') ?>: <?= number_format((float)$t['min_value'], 2) ?>–<?= number_format((float)$t['max_value'], 2) ?>% → <strong><?= number_format((float)$t['scholarship_percent'], 2) ?>%</strong> (prio <?= (int)$t['priority'] ?>)</li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-3 d-flex gap-2">
            <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <button type="submit" name="action" value="preview" class="btn btn-outline-primary btn-sm">Save Preview Snapshot</button>
            </form>
            <?php if (gs_can_edit()): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Apply/reapply this GPA scholarship setup now? This updates only module-owned scholarship rows for target semester.');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <button type="submit" name="action" value="apply" class="btn btn-success btn-sm">Apply / Reapply</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Preview</strong>
        <span class="small text-muted">Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> of <?= $total ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Student</th>
                    <th>Payment</th>
                    <th>GPA</th>
                    <th>Attendance</th>
                    <th>Matched GPA tier</th>
                    <th>Matched Attendance tier</th>
                    <th>Final %</th>
                    <th>Amount</th>
                    <th>Outcome</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows_page)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No students found in scope.</td></tr>
            <?php else: foreach ($rows_page as $r): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= h($r['student_name']) ?></div>
                        <div class="small text-muted"><?= h($r['student_id']) ?> · sf#<?= $r['target_sf_id'] ? (int)$r['target_sf_id'] : '—' ?></div>
                    </td>
                    <td><span class="badge <?= ($r['payment_type'] ?? 'merit') === 'merit' ? 'bg-primary' : 'bg-secondary' ?>"><?= h($r['payment_type'] ?? 'n/a') ?></span></td>
                    <td><?= $r['semester_gpa'] !== null ? number_format((float)$r['semester_gpa'], 2) : '—' ?></td>
                    <td><?= $r['attendance_pct'] !== null ? number_format((float)$r['attendance_pct'], 2) . '%' : '—' ?></td>
                    <td><?= $r['gpa_tier'] ? h(($r['gpa_tier']['label'] ?: 'Tier') . ' (' . number_format((float)$r['gpa_tier']['scholarship_percent'], 2) . '%)') : '—' ?></td>
                    <td><?= $r['attendance_tier'] ? h(($r['attendance_tier']['label'] ?: 'Tier') . ' (' . number_format((float)$r['attendance_tier']['scholarship_percent'], 2) . '%)') : '—' ?></td>
                    <td class="fw-semibold"><?= $r['final_percent'] !== null ? number_format((float)$r['final_percent'], 2) . '%' : '—' ?></td>
                    <td><?= $r['final_amount'] !== null ? number_format((float)$r['final_amount'], 2) : '—' ?></td>
                    <td>
                        <?php if ($r['eligible']): ?>
                            <span class="badge bg-success">Eligible</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Excluded</span>
                            <div class="small text-muted"><?= h($r['exclusion_reason']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white">
        <nav>
            <ul class="pagination pagination-sm mb-0 justify-content-center">
                <?php for ($pg = 1; $pg <= $pages; $pg++): ?>
                <li class="page-item <?= $pg === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?id=<?= (int)$id ?>&page=<?= $pg ?>"><?= $pg ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
