<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../includes/captcha-helpers.php';

// Only super users or users with system settings access can manage CAPTCHA
if (!$_SESSION['is_super']) {
    flash_set('error', 'You do not have permission to access this page.');
    redirect(APP_URL . '/index.php');
}

$page_title = 'Global CAPTCHA Settings';
$errors     = [];

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

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
        $db = db();
        
        // Update or insert settings
        $stmt = $db->prepare("
            INSERT INTO global_settings (`key`, `value`)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
        ");
        
        $stmt->execute(['captcha_enabled', $captcha_enabled]);
        $stmt->execute(['captcha_site_key', $captcha_site_key]);
        $stmt->execute(['captcha_secret_key', $captcha_secret_key]);
        
        flash_set('success', 'CAPTCHA settings saved successfully.');
        redirect(APP_URL . '/settings/captcha.php');
    }
}

// Use helper function instead of duplicating
$captcha_enabled    = captcha_get_setting('captcha_enabled', '0');
$captcha_site_key   = captcha_get_setting('captcha_site_key', '');
$captcha_secret_key = captcha_get_setting('captcha_secret_key', '');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Global CAPTCHA Settings</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">

        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-shield-alt me-2 text-muted"></i>Google reCAPTCHA Settings
                </h6>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="captcha_enabled"
                                   id="captcha_enabled" value="1" <?= $captcha_enabled === '1' ? 'checked' : '' ?>
                                   onchange="toggleCaptchaFields(this.checked)">
                            <label class="form-check-label fw-medium" for="captcha_enabled">
                                Enable CAPTCHA on All Public Forms
                            </label>
                        </div>
                        <div class="form-text mt-1">
                            When enabled, all public forms (apply-now, contact, certificate-verification, 
                            student-enrollment-status, faculty-register, job applications) will require 
                            CAPTCHA verification.
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
                                Public key for displaying the reCAPTCHA widget on forms.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-medium">
                                reCAPTCHA Secret Key
                                <span class="text-danger">*</span>
                            </label>
                            <input type="password" name="captcha_secret_key" class="form-control"
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
                    <a href="<?= APP_URL ?>/index.php" class="btn btn-light ms-2" style="border-radius:8px;">
                        Cancel
                    </a>
                </form>
            </div>
        </div>

    </div>

    <div class="col-lg-4">
        <div class="card border-info" style="border-radius:12px;">
            <div class="card-header py-3 px-4 bg-info bg-opacity-10">
                <h6 class="mb-0 fw-semibold text-info">
                    <i class="fas fa-info-circle me-2"></i>About CAPTCHA Protection
                </h6>
            </div>
            <div class="card-body p-4" style="font-size:.875rem;">
                <p class="mb-2"><strong>What is protected?</strong> All public submission forms including:</p>
                <ul class="mb-3">
                    <li>Apply Now (Lead applications)</li>
                    <li>Contact Form</li>
                    <li>Certificate Verification</li>
                    <li>Student Enrollment Status</li>
                    <li>Faculty Registration</li>
                    <li>Job Applications</li>
                </ul>
                <p class="mb-2"><strong>Why CAPTCHA?</strong> Prevents automated bots from flooding your website with spam submissions.</p>
                <p class="mb-2"><strong>User Impact:</strong> Users need to check a simple "I'm not a robot" box before submitting forms.</p>
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
