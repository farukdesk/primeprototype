<?php
/**
 * Notifications: full list for the logged-in user (bell icon → "View all").
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../includes/notifications.php';

$page_title = 'Notifications';
$user = auth_user();
$uid  = (int)$user['id'];

$per_page = 30;
$page_no  = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page_no - 1) * $per_page;

$items = [];
$total = 0;
try {
    $cnt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
    $cnt->execute([$uid]);
    $total = (int)$cnt->fetchColumn();
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int)$per_page . ' OFFSET ' . (int)$offset);
    $stmt->execute([$uid]);
    $items = $stmt->fetchAll();
} catch (Throwable $e) {
    // notifications table not installed yet – run admin/notifications.sql
}
$unread = notif_unread_count($uid);
$pages  = max(1, (int)ceil($total / $per_page));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Notifications</li>
        </ol>
    </nav>
    <?php if ($unread > 0): ?>
    <form method="POST" action="<?= APP_URL ?>/notifications/mark-read.php" class="m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="all" value="1">
        <button class="btn btn-outline-primary btn-sm" style="border-radius:8px;"><i class="fas fa-check-double me-1"></i> Mark all as read</button>
    </form>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-bell me-2 text-primary"></i>Notifications</h6>
        <?php if ($unread > 0): ?><span class="badge bg-danger rounded-pill"><?= $unread ?> unread</span><?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($items)): ?>
        <div class="text-center text-muted py-5"><i class="far fa-bell-slash fa-2x mb-2 d-block"></i>No notifications yet.</div>
        <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($items as $n): ?>
            <a href="<?= APP_URL ?>/notifications/read.php?id=<?= (int)$n['id'] ?>"
               class="list-group-item list-group-item-action d-flex gap-3 align-items-start px-4 py-3"
               style="<?= $n['is_read'] ? '' : 'background:#eef4ff;' ?>">
                <i class="fas fa-circle mt-2" style="font-size:.5rem;color:<?= $n['is_read'] ? '#ccc' : '#4f8ef7' ?>;"></i>
                <span style="min-width:0;flex:1;">
                    <span class="d-block fw-semibold" style="font-size:.9rem;"><?= h($n['title']) ?></span>
                    <?php if (!empty($n['body'])): ?>
                    <span class="d-block text-muted" style="font-size:.83rem;"><?= h($n['body']) ?></span>
                    <?php endif; ?>
                </span>
                <span class="text-muted text-nowrap" style="font-size:.75rem;"><?= h(notif_time_ago($n['created_at'])) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2 px-4">
        <span class="text-muted small">Page <?= $page_no ?> of <?= $pages ?> (<?= $total ?> total)</span>
        <div class="btn-group">
            <a class="btn btn-sm btn-outline-secondary <?= $page_no <= 1 ? 'disabled' : '' ?>" href="?page=<?= $page_no - 1 ?>">&laquo; Prev</a>
            <a class="btn btn-sm btn-outline-secondary <?= $page_no >= $pages ? 'disabled' : '' ?>" href="?page=<?= $page_no + 1 ?>">Next &raquo;</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
