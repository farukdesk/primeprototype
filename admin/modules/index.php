<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('modules');

$page_title = 'Modules';
$modules = db()->query(
    'SELECT * FROM modules ORDER BY sort_order, name'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Modules</li>
        </ol>
    </nav>
    <?php if (is_super_admin() || can_access('modules', 'can_create')): ?>
    <a href="<?= APP_URL ?>/modules/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Module
    </a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Module Name</th>
                        <th>Slug</th>
                        <th>Icon</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($modules)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No modules found.</td></tr>
                <?php else: ?>
                    <?php foreach ($modules as $i => $m): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <i class="<?= h($m['icon']) ?> me-2 text-muted"></i>
                            <strong><?= h($m['name']) ?></strong>
                        </td>
                        <td><code><?= h($m['slug']) ?></code></td>
                        <td><code style="font-size:.75rem;"><?= h($m['icon']) ?></code></td>
                        <td><?= (int)$m['sort_order'] ?></td>
                        <td>
                            <?php if ($m['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if (is_super_admin() || can_access('modules', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/modules/edit.php?id=<?= $m['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (is_super_admin() || can_access('modules', 'can_delete')): ?>
                                <form method="POST" action="<?= APP_URL ?>/modules/delete.php"
                                      onsubmit="return confirm('Delete this module? This will remove all access permissions for it.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
