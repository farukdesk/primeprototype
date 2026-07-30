<?php
/**
 * App Notification – Index (list + compose)
 * =========================================
 * Compose a push notification and view previously sent notifications.
 * Publishing here delivers a push notification to every student that has the
 * Android app installed and a registered device token.
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('app-notifications');
require_once __DIR__ . '/helpers.php';

$page_title        = 'App Notification';
$can_send          = can_access('app-notifications', 'can_create');
$configured        = apn_fcm_is_configured();
$device_count      = apn_device_count();
$user_device_count = apn_user_device_count();
$groups            = apn_group_options();
$users             = apn_user_options();
$notifications     = apn_list(100);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-mobile-alt me-2 text-primary"></i>App Notification</h1>
        <p class="text-muted small mb-0">Send a push notification to students using the mobile app.</p>
    </div>
    <a href="<?= APP_URL ?>/app-notifications/settings.php" class="btn btn-outline-secondary">
        <i class="fas fa-cog me-1"></i> FCM Settings
    </a>
</div>

<?php flash_show(); ?>

<?php if (!$configured): ?>
<div class="alert alert-warning d-flex align-items-center gap-3" style="border-radius:10px;">
    <i class="fas fa-exclamation-triangle fa-lg"></i>
    <div>
        <strong>Firebase Cloud Messaging is not configured.</strong>
        Push notifications cannot be delivered until you add a service-account credential.
        <a href="<?= APP_URL ?>/app-notifications/settings.php" class="alert-link">Configure now</a>.
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Compose -->
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-paper-plane me-2 text-primary"></i>Compose Notification</h2>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3 text-muted small">
                    <i class="fas fa-users"></i>
                    <span><strong><?= (int)$device_count ?></strong> registered device<?= $device_count === 1 ? '' : 's' ?> will receive this notification.</span>
                </div>

                <?php if ($can_send): ?>
                <form method="post" action="<?= APP_URL ?>/app-notifications/send.php"
                      onsubmit="return confirm('Send this push notification to all installed app users?');">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" maxlength="150" required
                               value="<?= old('title') ?>" placeholder="e.g. Semester Result Published">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea name="body" class="form-control" rows="4" required
                                  placeholder="Write the notification message…"><?= old('body') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Link URL <span class="text-muted">(optional)</span></label>
                        <input type="url" name="url" class="form-control"
                               value="<?= old('url') ?>" placeholder="https://…">
                        <div class="form-text">Opened in the app when the student taps the notification.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" <?= $configured ? '' : 'disabled' ?>>
                        <i class="fas fa-paper-plane me-1"></i> Publish &amp; Send Push
                    </button>
                </form>
                <?php else: ?>
                <div class="text-muted small">You do not have permission to send notifications.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- History -->
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-history me-2 text-primary"></i>Sent Notifications</h2>
            </div>
            <div class="card-body p-0">
                <?php if (empty($notifications)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-bell-slash fa-3x mb-3 opacity-25"></i>
                    <p class="mb-0">No notifications have been sent yet.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Title</th>
                                <th>Delivery</th>
                                <th>Status</th>
                                <th>Sent By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($notifications as $n): ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/app-notifications/view.php?id=<?= (int)$n['id'] ?>"
                                   class="fw-semibold text-decoration-none"><?= h($n['title']) ?></a>
                            </td>
                            <td>
                                <span class="text-success fw-semibold"><?= (int)$n['sent_count'] ?></span>
                                <?php if ((int)$n['failed_count'] > 0): ?>
                                / <span class="text-danger"><?= (int)$n['failed_count'] ?> failed</span>
                                <?php endif; ?>
                                <span class="text-muted small">of <?= (int)$n['total_tokens'] ?></span>
                            </td>
                            <td><?= apn_status_badge($n['status']) ?></td>
                            <td><?= h($n['sender_name'] ?? '—') ?></td>
                            <td><span title="<?= h($n['created_at']) ?>"><?= h(date('d M Y H:i', strtotime($n['created_at']))) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
