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

    // CAPTCHA settings
    $captcha_enabled    = isset($_POST['captcha_enabled']) ? '1' : '0';
    $captcha_site_key   = trim($_POST['captcha_site_key'] ?? '');
    $captcha_secret_key = trim($_POST['captcha_secret_key'] ?? '');

    // Validate CAPTCHA keys if enabled
    if ($captcha_enabled === '1') {
        if (empty($captcha_site_key)) {
            $errors[] = 'reCAPTCHA Site Key is required when CAPTCHA is enabled.';
        }
        if (empty($captcha_secret_key)) {
            $errors[] = 'reCAPTCHA Secret Key is required when CAPTCHA is enabled.';
        }
    }

    if (empty($errors)) {
        st_set_setting('notify_emails', implode(',', $email_list));
        st_set_setting('captcha_enabled', $captcha_enabled);
        st_set_setting('captcha_site_key', $captcha_site_key);
        st_set_setting('captcha_secret_key', $captcha_secret_key);
        flash_set('success', 'Settings saved successfully.');
        redirect(APP_URL . '/support-tickets/settings.php');
    }
}

$current_emails         = st_get_setting('notify_emails', 'dd.it@primeuniversity.ac.bd,belayet@primeuniversity.ac.bd');
$captcha_enabled        = st_get_setting('captcha_enabled', '0');
$captcha_site_key       = st_get_setting('captcha_site_key', '');
$captcha_secret_key     = st_get_setting('captcha_secret_key', '');

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

        <div class="card mb-4" style="border-radius:12px;">
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

                    <hr class="my-4">

                    <h6 class="fw-semibold mb-3">
                        <i class="fas fa-shield-alt me-2 text-muted"></i>Anti-Spam Protection (Google reCAPTCHA)
                    </h6>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="captcha_enabled"
                                   id="captcha_enabled" value="1" <?= $captcha_enabled === '1' ? 'checked' : '' ?>
                                   onchange="toggleCaptchaFields(this.checked)">
                            <label class="form-check-label fw-medium" for="captcha_enabled">
                                Enable CAPTCHA on Public Ticket Submission
                            </label>
                        </div>
                        <div class="form-text mt-1">
                            Protect against automated spam by requiring CAPTCHA verification before tickets can be submitted.
                        </div>
                    </div>

                    <div id="captcha_fields" style="<?= $captcha_enabled === '1' ? '' : 'display:none;' ?>">
                        <div class="mb-3">
                            <label class="form-label fw-medium">
                                reCAPTCHA Site Key
                                <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="captcha_site_key" class="form-control"
                                   value="<?= h($captcha_site_key) ?>"
                                   placeholder="6Lxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                   style="border-radius:8px;font-family:monospace;">
                            <div class="form-text mt-1">
                                Public key for displaying the reCAPTCHA widget.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-medium">
                                reCAPTCHA Secret Key
                                <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="captcha_secret_key" class="form-control"
                                   value="<?= h($captcha_secret_key) ?>"
                                   placeholder="6Lxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                   style="border-radius:8px;font-family:monospace;">
                            <div class="form-text mt-1">
                                Secret key for server-side verification. Keep this confidential!
                            </div>
                        </div>

                        <div class="alert alert-info" style="border-radius:8px;">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Get your reCAPTCHA keys:</strong>
                            <ol class="mb-0 mt-2">
                                <li>Visit <a href="https://www.google.com/recaptcha/admin" target="_blank" class="alert-link">Google reCAPTCHA Admin Console</a></li>
                                <li>Choose <strong>reCAPTCHA v2</strong> → <strong>"I'm not a robot" Checkbox</strong></li>
                                <li>Add domain: <code>primeuniversity.ac.bd</code></li>
                                <li>Copy the Site Key and Secret Key here</li>
                            </ol>
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
        <div class="card border-info mb-3" style="border-radius:12px;">
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

        <div class="card border-warning" style="border-radius:12px;">
            <div class="card-header py-3 px-4 bg-warning bg-opacity-10">
                <h6 class="mb-0 fw-semibold text-warning">
                    <i class="fas fa-shield-alt me-2"></i>About CAPTCHA Protection
                </h6>
            </div>
            <div class="card-body p-4" style="font-size:.875rem;">
                <p class="mb-2"><strong>Why CAPTCHA?</strong> Prevents automated bots from flooding your support system with spam tickets.</p>
                <p class="mb-2"><strong>User Impact:</strong> Users need to check a simple "I'm not a robot" box before submitting.</p>
                <p class="mb-2"><strong>Admin Only:</strong> CAPTCHA only applies to public submissions. Admin users are not affected.</p>
                <p class="mb-0"><strong>Privacy:</strong> Google reCAPTCHA may collect user data. Review Google's privacy policy for details.</p>
            </div>
        </div>
    </div>
</div>

<script>
'use strict';
function toggleCaptchaFields(enabled) {
    const captchaFields = document.getElementById('captcha_fields');
    if (captchaFields) {
        captchaFields.style.display = enabled ? '' : 'none';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
