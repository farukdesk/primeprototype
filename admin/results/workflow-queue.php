<?php
/**
 * Workflow Queue – generic approver dashboard.
 * Shows ALL pending sheets where the current user's group matches the current step.
 * Works for any role in any chain — no hard-coding.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/workflow-helpers.php';

auth_check();

$page_title = 'Workflow Queue';

$sheets = wf_get_approver_queue();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item active">Workflow Queue</li>
        </ol>
    </nav>
    <span class="badge bg-primary" style="font-size:.85rem; border-radius:8px; padding:.45rem .9rem;">
        <?= count($sheets) ?> sheet<?= count($sheets) !== 1 ? 's' : '' ?> awaiting your action
    </span>
</div>

<?php flash_show(); ?>

<?php if (empty($sheets)): ?>
<div class="card text-center py-5" style="border-radius:12px;">
    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
    <h5 class="text-muted">All clear!</h5>
    <p class="text-muted mb-0">No mark sheets are currently waiting for your approval.</p>
</div>
<?php else: ?>

<?php
// Group sheets by step label for visual clarity
$by_step = [];
foreach ($sheets as $s) {
    $key = ($s['current_step_label'] ?? 'Pending') . ' — ' . ($s['current_group_name'] ?? '');
    $by_step[$key][] = $s;
}
?>

<?php foreach ($by_step as $step_key => $group): ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-tasks me-2 text-muted"></i>
            Awaiting: <span class="text-primary"><?= h($step_key) ?></span>
        </h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($group) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Subject</th>
                        <th>Department / Program</th>
                        <th>Semester</th>
                        <th>Submitted By</th>
                        <th class="text-center">Students</th>
                        <th>Chain</th>
                        <th>Last Updated</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($group as $i => $s): ?>
                <tr>
                    <td class="px-4"><?= $i + 1 ?></td>
                    <td>
                        <div class="fw-medium"><?= h($s['subject_title']) ?></div>
                        <?php if ($s['subject_code']): ?>
                        <small class="text-muted"><?= h($s['subject_code']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?= h($s['dept_name']) ?></div>
                        <?php if ($s['program_name']): ?>
                        <small class="text-muted"><?= h($s['program_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= h($s['semester']) ?></td>
                    <td><?= h($s['creator_name'] ?? '—') ?></td>
                    <td class="text-center">
                        <span class="badge bg-secondary"><?= (int)$s['student_count'] ?></span>
                    </td>
                    <td><small class="text-muted"><?= h($s['chain_name'] ?? '—') ?></small></td>
                    <td><small class="text-muted"><?= $s['updated_at'] ? date('d M Y', strtotime($s['updated_at'])) : '—' ?></small></td>
                    <td class="text-end pe-4">
                        <a href="<?= APP_URL ?>/results/workflow-review.php?id=<?= $s['id'] ?>"
                           class="btn btn-sm btn-primary" style="border-radius:8px;">
                            <i class="fas fa-tasks me-1"></i> Review
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
