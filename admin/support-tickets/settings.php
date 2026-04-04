<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

require_access('support-tickets', 'can_edit');

$page_title = 'IT Support Settings';
$errors     = [];

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $raw_emails = trim($_POST['notify_emails'] ?? '');
    $email_list = array_values(array_filter(array_map('trim', explode(',', $raw_emails))));
    $bad = [];
    foreach ($email_list as $em) {
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $bad[] = h($em);
        }
    }
    if ($bad) {
        $errors[] = 'Invalid email address(es): ' . implode(', ', $bad);
    }

    if (empty($errors)) {
        st_set_setting('notify_emails', implode(',', $email_list));
        flash_set('success', 'Settings saved successfully.');
        redirect(APP_URL . '/support-tickets/settings.php');
    }
}

$current_emails = st_get_setting('notify_emails', 'dd.it@primeuniversity.ac.bd,belayet@primeuniversity.ac.bd');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/support-tickets/index.php">IT Support</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">

        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-envelope me-2 text-muted"></i>Email Notification Settings
                </h6>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="mb-4">
                        <label class="form-label fw-medium">
                            Notification Recipients
                            <span class="text-danger">*</span>
                        </label>
                        <textarea name="notify_emails" class="form-control" rows="4"
                                  placeholder="dd.it@primeuniversity.ac.bd, belayet@primeuniversity.ac.bd"
                                  style="border-radius:8px;font-family:monospace;"><?= h($current_emails) ?></textarea>
                        <div class="form-text mt-1">
                            Enter email addresses separated by commas. These addresses will receive notifications when:
                            <ul class="mt-1 mb-0">
                                <li>A new ticket is created (by any user or publicly)</li>
                                <li>A comment is added to any ticket</li>
                                <li>A ticket status changes</li>
                            </ul>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="border-radius:8px;">
                        <i class="fas fa-save me-1"></i> Save Settings
                    </button>
                    <a href="<?= APP_URL ?>/support-tickets/index.php" class="btn btn-light ms-2" style="border-radius:8px;">
                        Cancel
                    </a>
                </form>
            </div>
        </div>

    </div>

    <div class="col-lg-5">
        <div class="card border-info" style="border-radius:12px;">
            <div class="card-header py-3 px-4 bg-info bg-opacity-10">
                <h6 class="mb-0 fw-semibold text-info">
                    <i class="fas fa-info-circle me-2"></i>About Notifications
                </h6>
            </div>
            <div class="card-body p-4" style="font-size:.875rem;">
                <p class="mb-2"><strong>Ticket Created:</strong> Admin notification emails + ticket creator confirmation.</p>
                <p class="mb-2"><strong>Comment Added:</strong> Admin emails + ticket creator + assigned staff receive a notification.</p>
                <p class="mb-2"><strong>Status Changed:</strong> Admin emails + ticket creator/submitter receive an update.</p>
                <p class="mb-2"><strong>@Mention:</strong> Typing <code>@username</code> in a comment notifies that user directly.</p>
                <p class="mb-0"><strong>Public Tickets:</strong> Guest submitters receive a confirmation email with their ticket number.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
