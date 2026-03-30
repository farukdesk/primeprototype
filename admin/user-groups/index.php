<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('user-groups');

$page_title = 'User Groups';
$groups = db()->query(
    'SELECT g.*, COUNT(u.id) AS user_count
     FROM user_groups g
     LEFT JOIN users u ON u.group_id = g.id
     GROUP BY g.id
     ORDER BY g.is_super DESC, g.name'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">User Groups</li>
        </ol>
    </nav>
    <?php if (is_super_admin() || can_access('user-groups', 'can_create')): ?>
    <a href="<?= APP_URL ?>/user-groups/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Group
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
                        <th>Group Name</th>
                        <th>Description</th>
                        <th>Users</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($groups)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No groups found.</td></tr>
                <?php else: ?>
                    <?php foreach ($groups as $i => $g): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td><strong><?= h($g['name']) ?></strong></td>
                        <td><?= h($g['description'] ?: '—') ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/users/index.php?group_id=<?= $g['id'] ?>" class="badge bg-secondary text-decoration-none">
                                <?= (int)$g['user_count'] ?> users
                            </a>
                        </td>
                        <td>
                            <?php if ($g['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($g['is_super']): ?>
                                <span class="badge badge-super">Super Admin</span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark">Standard</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/access/index.php?group_id=<?= $g['id'] ?>"
                                   class="btn btn-sm btn-outline-info" title="Manage Access" style="border-radius:7px;">
                                    <i class="fas fa-shield-alt"></i>
                                </a>
                                <?php if (is_super_admin() || can_access('user-groups', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/user-groups/edit.php?id=<?= $g['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ((is_super_admin() || can_access('user-groups', 'can_delete')) && !$g['is_super']): ?>
                                <form method="POST" action="<?= APP_URL ?>/user-groups/delete.php"
                                      onsubmit="return confirm('Delete this group? Users in this group must be reassigned first.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
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
