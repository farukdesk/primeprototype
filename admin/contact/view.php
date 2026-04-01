<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('contact');
require_once __DIR__ . '/helpers.php';

$id  = (int)($_GET['id'] ?? 0);
$msg = contact_get_message($id);

if (!$msg) {
    flash_set('error', 'Message not found.');
    redirect(APP_URL . '/contact/index.php');
}

// Mark as read if not already
if (!$msg['is_read']) {
    db()->prepare('UPDATE contact_messages SET is_read = 1 WHERE id = ?')->execute([$id]);
    $msg['is_read'] = 1;
}

$page_title = 'Message from ' . h($msg['name']);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/contact/index.php">Contact Messages</a></li>
            <li class="breadcrumb-item active">Message #<?= $msg['id'] ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/contact/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <?php if (is_super_admin() || can_access('contact', 'can_delete')): ?>
        <form method="POST" action="<?= APP_URL ?>/contact/delete.php" class="d-inline"
              onsubmit="return confirm('Permanently delete this message?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $msg['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" style="border-radius:8px;">
                <i class="fas fa-trash me-1"></i> Delete
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- Message content -->
    <div class="col-lg-8">
        <div class="card" style="border-radius:14px;border:none;box-shadow:0 4px 24px rgba(0,0,0,.06);">
            <div class="card-body p-4">
                <h5 style="color:#1a2e5a;font-weight:700;margin-bottom:4px;"><?= h($msg['subject']) ?></h5>
                <p style="font-size:.82rem;color:#9ca3af;margin-bottom:24px;">
                    Received on <?= $msg['created_at'] ? date('l, d F Y \a\t h:i A', strtotime($msg['created_at'])) : 'Unknown date' ?>
                </p>
                <hr style="border-color:#f0f0f0;">
                <div style="font-size:.95rem;color:#374151;line-height:1.8;white-space:pre-wrap;margin-top:20px;"><?= h($msg['message']) ?></div>
            </div>
        </div>
    </div>

    <!-- Sender details -->
    <div class="col-lg-4">
        <div class="card" style="border-radius:14px;border:none;box-shadow:0 4px 24px rgba(0,0,0,.06);">
            <div class="card-body p-4">
                <h6 style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:20px;">
                    Sender Details
                </h6>

                <div class="d-flex align-items-center gap-3 mb-4">
                    <div style="width:48px;height:48px;border-radius:14px;background:#eef2ff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#2563eb;font-weight:700;flex-shrink:0;">
                        <?= strtoupper(mb_substr($msg['name'] ?? '?', 0, 1)) ?>
                    </div>
                    <div>
                        <div style="font-weight:700;color:#1a2e5a;"><?= h($msg['name']) ?></div>
                        <div style="font-size:.82rem;color:#6b7280;">Contact Enquiry</div>
                    </div>
                </div>

                <div style="font-size:.875rem;color:#374151;">
                    <div class="d-flex gap-2 mb-3">
                        <i class="fas fa-envelope mt-1" style="color:#2563eb;flex-shrink:0;"></i>
                        <a href="mailto:<?= h($msg['email']) ?>" style="color:#2563eb;text-decoration:none;word-break:break-all;">
                            <?= h($msg['email']) ?>
                        </a>
                    </div>
                    <?php if ($msg['phone']): ?>
                    <div class="d-flex gap-2 mb-3">
                        <i class="fas fa-phone-alt mt-1" style="color:#059669;flex-shrink:0;"></i>
                        <a href="tel:<?= h($msg['phone']) ?>" style="color:#374151;text-decoration:none;">
                            <?= h($msg['phone']) ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex gap-2">
                        <i class="fas fa-calendar-alt mt-1" style="color:#d97706;flex-shrink:0;"></i>
                        <span><?= $msg['created_at'] ? date('d M Y, h:i A', strtotime($msg['created_at'])) : 'Unknown date' ?></span>
                    </div>
                </div>

                <hr style="border-color:#f0f0f0;margin:20px 0;">

                <a href="mailto:<?= h($msg['email']) ?>?subject=Re: <?= rawurlencode($msg['subject']) ?>"
                   class="btn btn-primary w-100" style="border-radius:10px;font-size:.875rem;">
                    <i class="fas fa-reply me-2"></i> Reply via Email
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
