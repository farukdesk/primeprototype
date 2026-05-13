<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('spring-result');
require_once __DIR__ . '/helpers.php';

$page_title = 'Spring Result';

try {
    $results = db()->query(
        'SELECT r.*, (SELECT COUNT(*) FROM sr_result_entries e WHERE e.result_id = r.id) AS entry_count
         FROM sr_results r
         ORDER BY r.created_at DESC
         LIMIT 500'
    )->fetchAll();
} catch (Throwable $e) {
    $results = [];
    flash_set('error', 'Run <code>spring-result.sql</code> to create the required tables.');
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Spring Result</li>
        </ol>
    </nav>
    <?php if (sr_can_create()): ?>
    <a href="<?= APP_URL ?>/spring-result/create.php" class="btn btn-success" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Result
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-poll me-2 text-muted"></i>All Results</h6>
        <span class="badge bg-secondary"><?= count($results) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Title</th>
                        <th>Semester</th>
                        <th class="text-center">Entries</th>
                        <th class="text-center">Status</th>
                        <th>Created</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($results)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                        No results yet.
                        <?php if (sr_can_create()): ?>
                        <a href="<?= APP_URL ?>/spring-result/create.php">Create one now</a>.
                        <?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($results as $i => $r): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/spring-result/view.php?id=<?= $r['id'] ?>" class="fw-medium text-decoration-none">
                                <?= h($r['title']) ?>
                            </a>
                            <?php if ($r['description']): ?>
                            <div class="text-muted small"><?= h(mb_substr($r['description'], 0, 80)) ?><?= mb_strlen($r['description']) > 80 ? '…' : '' ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= $r['semester'] ? h($r['semester']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= (int)$r['entry_count'] ?></span></td>
                        <td class="text-center"><?= sr_status_badge((int)$r['is_published']) ?></td>
                        <td class="text-muted small"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/spring-result/view.php?id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="View / Manage" style="border-radius:7px;">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="<?= APP_URL ?>/spring-result/grade-sheet.php?result_id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-outline-success" title="Grade Sheet PDF" target="_blank" style="border-radius:7px;">
                                    <i class="fas fa-file-pdf"></i>
                                </a>
                                <?php if (sr_can_edit()): ?>
                                <a href="<?= APP_URL ?>/spring-result/edit.php?id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (sr_can_delete()): ?>
                                <a href="<?= APP_URL ?>/spring-result/delete.php?id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;"
                                   onclick="return confirm('Delete this result and all its entries?')">
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
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
