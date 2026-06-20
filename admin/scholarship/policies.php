<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('scholarship-policies');

$page_title = 'Scholarship Policies';
$db         = db();

$policies = $db->query(
    'SELECT p.*,
            (SELECT COUNT(*) FROM sc_tiers t WHERE t.policy_id = p.id) AS tier_count,
            (SELECT COUNT(*) FROM sc_awards a WHERE a.policy_id = p.id AND a.status = \'active\') AS award_count,
            u.username AS created_by_name
     FROM sc_policies p
     LEFT JOIN users u ON u.id = p.created_by
     ORDER BY p.sort_order, p.name'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-cog me-2 text-secondary"></i>Scholarship Policies</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/scholarship/index.php">Scholarships</a></li>
            <li class="breadcrumb-item active">Policies</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <?php if (sc_can_create()): ?>
        <a href="<?= APP_URL ?>/scholarship/policy-create.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i> Add Policy
        </a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Policy Name</th>
                        <th>Type</th>
                        <th>Tiers</th>
                        <th>Active Awards</th>
                        <th>Status</th>
                        <th>Sort</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($policies)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-5">
                        <i class="fas fa-cog fa-2x mb-2 d-block opacity-25"></i>
                        No policies yet. <a href="<?= APP_URL ?>/scholarship/policy-create.php">Create one now</a>.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($policies as $i => $pol): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($pol['name']) ?></div>
                            <?php if ($pol['description']): ?>
                            <small class="text-muted"><?= h(mb_substr($pol['description'], 0, 80)) ?><?= mb_strlen($pol['description']) > 80 ? '…' : '' ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= sc_type_badge($pol['type']) ?></td>
                        <td>
                            <?php if ((int)$pol['tier_count'] > 0): ?>
                            <span class="badge bg-info text-dark"><?= (int)$pol['tier_count'] ?> tier<?= $pol['tier_count'] != 1 ? 's' : '' ?></span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$pol['award_count'] > 0): ?>
                            <a href="<?= APP_URL ?>/scholarship/index.php?policy=<?= $pol['id'] ?>&status=active" class="badge bg-success text-decoration-none">
                                <?= (int)$pol['award_count'] ?> award<?= $pol['award_count'] != 1 ? 's' : '' ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted small">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pol['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$pol['sort_order'] ?></td>
                        <td>
                            <?php if (sc_can_edit()): ?>
                            <a href="<?= APP_URL ?>/scholarship/policy-edit.php?id=<?= $pol['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (sc_can_delete()): ?>
                            <form method="post" action="<?= APP_URL ?>/scholarship/policy-delete.php" class="d-inline"
                                  onsubmit="return confirm('Delete policy \'<?= h(addslashes($pol['name'])) ?>\'? This will also delete all its tiers.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $pol['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
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
