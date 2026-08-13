<?php
/**
 * Bulk Sync Student IDs
 *
 * Backfills users.student_sid from the linked student record
 * (students.portal_user_id) for all portal accounts in one click.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('users', 'can_edit');
require_once __DIR__ . '/../change-log/helpers.php';

if (!is_super_admin()) {
    flash_set('error', 'Only super admins can sync Student IDs.');
    redirect(APP_URL . '/users/index.php');
}

$page_title = 'Sync Student IDs';

// Portal accounts whose users.student_sid is missing or differs from the
// linked student record (students.portal_user_id).
// Note: both sides of the comparison are forced to the same collation because
// users.student_sid and students.student_id may use different utf8mb4
// collations (general_ci vs unicode_ci), which would otherwise raise
// error 1267 "Illegal mix of collations".
$pending_sql =
    'SELECT u.id AS user_id, u.username, u.full_name, u.student_sid,
            s.student_id
     FROM students s
     JOIN users u ON u.id = s.portal_user_id
     WHERE u.student_sid IS NULL
        OR u.student_sid COLLATE utf8mb4_unicode_ci <> s.student_id COLLATE utf8mb4_unicode_ci
     ORDER BY s.student_id ASC';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_all') {
    csrf_check();

    $rows   = db()->query($pending_sql)->fetchAll();
    $upd    = db()->prepare('UPDATE users SET student_sid = ? WHERE id = ?');
    $synced = 0;

    foreach ($rows as $r) {
        $upd->execute([$r['student_id'], (int)$r['user_id']]);
        log_change('users', 'UPDATE', (int)$r['user_id'], $r['username'],
            'student_sid', (string)$r['student_sid'], $r['student_id'],
            'Student ID bulk-synced from linked student record');
        $synced++;
    }

    flash_set('success', 'Synced Student IDs for <strong>' . $synced . '</strong> user account' . ($synced === 1 ? '' : 's') . '.');
    redirect(APP_URL . '/users/sync-student-ids.php');
}

$pending       = db()->query($pending_sql)->fetchAll();
$pending_count = count($pending);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/users/index.php">Users</a></li>
            <li class="breadcrumb-item active">Sync Student IDs</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/students/portal-bulk-create.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-user-plus me-1"></i> Bulk Create Portal Accounts
    </a>
</div>

<?php flash_show(); ?>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-sync-alt me-2 text-muted"></i>Portal Accounts With Missing / Outdated Student ID
        </h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $pending_count ?> to sync</span>
    </div>
    <div class="card-body p-4">
        <?php if ($pending_count === 0): ?>
        <p class="text-muted mb-0">
            <i class="fas fa-check-circle text-success me-1"></i>
            All portal accounts are already in sync with their linked student records.
        </p>
        <?php else: ?>
        <div class="alert alert-info mb-3" style="border-radius:10px;">
            <i class="fas fa-info-circle me-1"></i>
            <strong><?= $pending_count ?></strong> portal account<?= $pending_count !== 1 ? 's' : '' ?>
            <?= $pending_count !== 1 ? 'have' : 'has' ?> a linked student record but a missing or outdated
            Student ID. Syncing copies the student's ID into the user account
            (<code>users.student_sid</code>) so it is linked on the user edit page and fee records.
        </div>

        <form method="POST" action="<?= APP_URL ?>/users/sync-student-ids.php" class="mb-4"
              onsubmit="return confirm('Sync Student IDs for <?= $pending_count ?> account<?= $pending_count !== 1 ? 's' : '' ?>?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_all">
            <button type="submit" class="btn btn-success" style="border-radius:10px;">
                <i class="fas fa-sync-alt me-1"></i> Sync All <?= $pending_count ?> Account<?= $pending_count !== 1 ? 's' : '' ?> Now
            </button>
            <a href="<?= APP_URL ?>/users/index.php" class="btn btn-light ms-2" style="border-radius:10px;">Cancel</a>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Current Student ID on User</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $i => $r): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><code class="text-primary"><?= h($r['student_id']) ?></code></td>
                    <td><?= h($r['full_name']) ?></td>
                    <td>
                        <a href="<?= APP_URL ?>/users/edit.php?id=<?= (int)$r['user_id'] ?>"><code><?= h($r['username']) ?></code></a>
                    </td>
                    <td>
                        <?php if ($r['student_sid'] === null || $r['student_sid'] === ''): ?>
                        <span class="badge bg-danger bg-opacity-10 text-danger">missing</span>
                        <?php else: ?>
                        <code><?= h($r['student_sid']) ?></code>
                        <span class="badge bg-warning bg-opacity-10 text-warning">outdated</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
