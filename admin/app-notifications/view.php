<?php
/**
 * App Notification – View a sent notification
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('app-notifications');
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
$n  = $id ? apn_find($id) : null;
if (!$n) {
    flash_set('error', 'Notification not found.');
    redirect(APP_URL . '/app-notifications/index.php');
}

$page_title = 'Notification – ' . $n['title'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/app-notifications/index.php">App Notification</a></li>
            <li class="breadcrumb-item active"><?= h($n['title']) ?></li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/app-notifications/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php flash_show(); ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between">
            <h1 class="h4 mb-2"><?= h($n['title']) ?></h1>
            <?= apn_status_badge($n['status']) ?>
        </div>
        <p class="text-muted small mb-3">
            Sent by <?= h($n['sender_name'] ?? '—') ?> on
            <?= h(date('d M Y, H:i', strtotime($n['created_at']))) ?>
        </p>

        <div class="mb-4" style="white-space:pre-wrap;"><?= h($n['body']) ?></div>

        <?php if (!empty($n['url'])): ?>
        <p class="mb-4">
            <i class="fas fa-link me-1 text-muted"></i>
            <a href="<?= h($n['url']) ?>" target="_blank" rel="noopener"><?= h($n['url']) ?></a>
        </p>
        <?php endif; ?>

        <div class="row text-center g-3">
            <div class="col">
                <div class="border rounded py-3">
                    <div class="h4 mb-0"><?= (int)$n['total_tokens'] ?></div>
                    <div class="text-muted small">Targeted</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded py-3">
                    <div class="h4 mb-0 text-success"><?= (int)$n['sent_count'] ?></div>
                    <div class="text-muted small">Delivered</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded py-3">
                    <div class="h4 mb-0 text-danger"><?= (int)$n['failed_count'] ?></div>
                    <div class="text-muted small">Failed</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
