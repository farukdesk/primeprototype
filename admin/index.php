<?php
require_once __DIR__ . '/includes/auth.php';
auth_check();

// Redirect users who do not have dashboard access to their appropriate area.
if (!is_super_admin() && !can_access('dashboard')) {
    if (can_access('faculty-profile')) {
        redirect(APP_URL . '/faculty-profiles/my-profile.php');
    }
    // Generic fallback: show a minimal "no access" page rather than looping.
    $page_title = 'No Access';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-warning"><i class="fas fa-lock me-2"></i>You do not have access to the dashboard. Please contact an administrator.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$page_title = 'Dashboard';

// Fetch stats
$db = db();
$stats = [
    'users'       => $db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'groups'      => $db->query('SELECT COUNT(*) FROM user_groups')->fetchColumn(),
    'modules'     => $db->query('SELECT COUNT(*) FROM modules WHERE is_active = 1')->fetchColumn(),
    'active'      => $db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn(),
];

// Recent users
$recent_users = $db->query(
    'SELECT u.full_name, u.username, u.email, u.created_at, g.name AS group_name
     FROM users u
     JOIN user_groups g ON g.id = u.group_id
     ORDER BY u.created_at DESC
     LIMIT 5'
)->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Stats row ── -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#4f8ef7,#2d63e8)">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= (int)$stats['users'] ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#11c48d,#0a9971)">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= (int)$stats['active'] ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#f5a623,#d4870a)">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= (int)$stats['groups'] ?></div>
                    <div class="stat-label">User Groups</div>
                </div>
                <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#e74c6e,#c42f52)">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= (int)$stats['modules'] ?></div>
                    <div class="stat-label">Active Modules</div>
                </div>
                <div class="stat-icon"><i class="fas fa-cubes"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Recent users ── -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-clock text-muted me-2"></i>Recently Registered Users</h6>
        <a href="<?= APP_URL ?>/users/index.php" class="btn btn-sm btn-outline-primary" style="border-radius:8px;font-size:.8rem;">
            View All
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Group</th>
                        <th>Registered</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recent_users)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No users yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent_users as $u): ?>
                    <tr>
                        <td class="px-4">
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:32px;height:32px;border-radius:50%;background:#4f8ef7;color:#fff;
                                    display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:600;">
                                    <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                </div>
                                <?= h($u['full_name']) ?>
                            </div>
                        </td>
                        <td><code><?= h($u['username']) ?></code></td>
                        <td><?= h($u['email']) ?></td>
                        <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($u['group_name']) ?></span></td>
                        <td><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
