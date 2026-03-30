<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('users');

$page_title = 'Users';

$filter_group = (int)($_GET['group_id'] ?? 0);
$search       = trim($_GET['search'] ?? '');

$where  = [];
$params = [];

if ($filter_group) {
    $where[]  = 'u.group_id = ?';
    $params[] = $filter_group;
}
if ($search !== '') {
    $where[]  = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like]);
}

$sql = 'SELECT u.*, g.name AS group_name, g.is_super
        FROM users u
        JOIN user_groups g ON g.id = u.group_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY u.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$groups = db()->query('SELECT id, name FROM user_groups ORDER BY name')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Users</li>
        </ol>
    </nav>
    <?php if (is_super_admin() || can_access('users', 'can_create')): ?>
    <a href="<?= APP_URL ?>/users/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-user-plus me-1"></i> New User
    </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control" style="max-width:260px;border-radius:10px;"
                   placeholder="Search name, username, email…" value="<?= h($search) ?>">
            <select name="group_id" class="form-select" style="max-width:220px;border-radius:10px;">
                <option value="">All Groups</option>
                <?php foreach ($groups as $g): ?>
                <option value="<?= $g['id'] ?>" <?= $filter_group == $g['id'] ? 'selected' : '' ?>>
                    <?= h($g['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-primary" style="border-radius:10px;"><i class="fas fa-search me-1"></i>Filter</button>
            <?php if ($search || $filter_group): ?>
            <a href="<?= APP_URL ?>/users/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Group</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No users found.</td></tr>
                <?php else: ?>
                    <?php $me = auth_user(); ?>
                    <?php foreach ($users as $i => $u): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:34px;height:34px;border-radius:50%;background:#4f8ef7;color:#fff;
                                    display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:600;flex-shrink:0;">
                                    <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <?= h($u['full_name']) ?>
                                    <?php if ($u['id'] == $me['id']): ?>
                                    <span class="badge bg-primary ms-1" style="font-size:.65rem;">You</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><code><?= h($u['username']) ?></code></td>
                        <td><?= h($u['email']) ?></td>
                        <td>
                            <?php if ($u['is_super']): ?>
                                <span class="badge badge-super"><?= h($u['group_name']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-primary bg-opacity-10 text-primary"><?= h($u['group_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $u['last_login']
                                ? date('M d, Y H:i', strtotime($u['last_login']))
                                : '<span class="text-muted">Never</span>' ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if (is_super_admin() || can_access('users', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/users/edit.php?id=<?= $u['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ((is_super_admin() || can_access('users', 'can_delete')) && $u['id'] != $me['id']): ?>
                                <form method="POST" action="<?= APP_URL ?>/users/delete.php"
                                      onsubmit="return confirm('Delete user ' + <?= json_encode($u['full_name']) ?> + '?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
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
