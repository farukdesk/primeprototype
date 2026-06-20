<?php
/**
 * Workflow Chains – Admin List
 */
require_once __DIR__ . '/../../includes/auth.php';
if (!is_super_admin() && !can_access('results-chains')) {
    flash_set('error', 'Access denied.'); redirect(APP_URL . '/results/index.php');
}

$page_title = 'Workflow Chains';

try {
    $chains = db()->query(
        "SELECT c.*,
                d.name        AS dept_name,
                p.program_name,
                (SELECT COUNT(*) FROM wf_chain_steps s WHERE s.chain_id = c.id) AS step_count,
                (SELECT COUNT(*) FROM result_mark_sheets ms WHERE ms.chain_id = c.id) AS sheet_count
         FROM wf_chains c
         LEFT JOIN dept_departments d           ON d.id = c.dept_id
         LEFT JOIN dept_academic_programs p     ON p.id = c.program_id
         ORDER BY c.is_active DESC, c.dept_id ASC, c.name ASC"
    )->fetchAll();
} catch (Throwable $e) {
    $chains = [];
    flash_set('error', 'Workflow tables not yet created. Please run <code>results-workflow-migration.sql</code>.');
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item active">Workflow Chains</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/results/chains/create.php" class="btn btn-primary" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> New Chain
    </a>
</div>

<?php flash_show(); ?>

<div class="alert alert-info" style="border-radius:10px;">
    <i class="fas fa-info-circle me-2"></i>
    <strong>Workflow Chains</strong> define who approves mark sheets and in what order.
    Each chain is scoped to a <strong>department</strong> (optionally a program) and contains
    ordered <strong>steps</strong> — each step assigned to a user group.
    The <strong>entry step</strong> is who creates/submits; the <strong>final step</strong> publishes.
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-sitemap me-2 text-muted"></i>All Chains</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Chain Name</th>
                        <th>Scope (Dept / Program)</th>
                        <th class="text-center">Steps</th>
                        <th class="text-center">Sheets</th>
                        <th class="text-center">Active</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($chains)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">
                        <i class="fas fa-sitemap fa-2x mb-2 d-block text-muted"></i>
                        No workflow chains defined yet.
                        <a href="<?= APP_URL ?>/results/chains/create.php">Create the first chain</a>.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($chains as $i => $c): ?>
                    <tr class="<?= $c['is_active'] ? '' : 'text-muted' ?>">
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-medium"><?= h($c['name']) ?></div>
                            <?php if ($c['description']): ?>
                            <small class="text-muted"><?= h(mb_substr($c['description'], 0, 70)) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c['dept_name']): ?>
                            <div><?= h($c['dept_name']) ?></div>
                            <?php if ($c['program_name']): ?>
                            <small class="text-muted"><?= h($c['program_name']) ?></small>
                            <?php else: ?>
                            <small class="text-muted fst-italic">All Programs</small>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="badge bg-info text-dark">Global (All Depts)</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary bg-opacity-10 text-primary"><?= (int)$c['step_count'] ?> steps</span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= (int)$c['sheet_count'] ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($c['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/results/chains/edit.php?id=<?= $c['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ((int)$c['sheet_count'] === 0): ?>
                                <form method="POST" action="<?= APP_URL ?>/results/chains/delete.php"
                                      onsubmit="return confirm('Delete chain &quot;<?= h($c['name']) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete">
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
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
