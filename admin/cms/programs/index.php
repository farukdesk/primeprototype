<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Programs';

$programs = db()->query(
    'SELECT * FROM cms_programs ORDER BY sort_order, id'
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Programs</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/cms/programs/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Program
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Link URL</th>
                        <th>Link Text</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($programs)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No programs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($programs as $idx => $prog): ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td><strong><?= h($prog['title']) ?></strong></td>
                        <td style="max-width:260px;">
                            <span style="font-size:.82rem;color:#64748b;">
                                <?= h(mb_strimwidth($prog['description'] ?? '', 0, 90, '…')) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($prog['link_url']): ?>
                            <code style="font-size:.78rem;"><?= h($prog['link_url']) ?></code>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($prog['link_text'] ?? '') ?></td>
                        <td><?= (int)$prog['sort_order'] ?></td>
                        <td>
                            <?= $prog['is_active']
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Inactive</span>' ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/cms/programs/edit.php?id=<?= $prog['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/cms/programs/delete.php"
                                      onsubmit="return confirm('Delete \"<?= h(addslashes($prog['title'])) ?>\"?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $prog['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
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
