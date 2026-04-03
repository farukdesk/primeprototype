<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Header Settings';
$errors     = [];
$success    = false;

// Load current settings into associative array
$rows = db()->query('SELECT setting_key, setting_value FROM cms_header_settings')->fetchAll();
$settings = [];
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $fields = [
        'phone', 'email',
        'student_portal_url', 'student_portal_text',
        'find_result_url',    'find_result_text',
        'facebook_url', 'twitter_url', 'instagram_url', 'linkedin_url',
        'old_website_url',
    ];

    $stmt = db()->prepare(
        'INSERT INTO cms_header_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($fields as $key) {
        $value = trim($_POST[$key] ?? '');
        $stmt->execute([$key, $value ?: null]);
        $settings[$key] = $value;
    }

    // Handle checkbox separately (unchecked checkboxes are not submitted)
    $enabled = isset($_POST['old_website_enabled']) ? '1' : '0';
    $stmt->execute(['old_website_enabled', $enabled]);
    $settings['old_website_enabled'] = $enabled;

    flash_set('success', 'Header settings saved.');
    redirect(APP_URL . '/cms/header/index.php');
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Header Settings</li>
        </ol>
    </nav>
</div>

<?php if ($msg = flash_get('success')): ?>
<div class="alert alert-success"><?= h($msg) ?></div>
<?php endif; ?>

<div class="row">
<div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-heading me-2 text-muted"></i>Header Top Bar Settings</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <h6 class="fw-semibold mb-3 text-muted border-bottom pb-2">Contact Info</h6>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone Number</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= h($settings['phone'] ?? '') ?>" placeholder="01710996196" maxlength="30">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email Address</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= h($settings['email'] ?? '') ?>" placeholder="info@primeuniversity.edu.bd" maxlength="191">
                </div>
            </div>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Quick Links</h6>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Student Portal – Text</label>
                    <input type="text" name="student_portal_text" class="form-control"
                           value="<?= h($settings['student_portal_text'] ?? 'Student Portal') ?>" maxlength="60">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Student Portal – URL</label>
                    <input type="text" name="student_portal_url" class="form-control"
                           value="<?= h($settings['student_portal_url'] ?? '#') ?>" placeholder="https://" maxlength="500">
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Find Result – Text</label>
                    <input type="text" name="find_result_text" class="form-control"
                           value="<?= h($settings['find_result_text'] ?? 'Find Result') ?>" maxlength="60">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Find Result – URL</label>
                    <input type="text" name="find_result_url" class="form-control"
                           value="<?= h($settings['find_result_url'] ?? '#') ?>" placeholder="https://" maxlength="500">
                </div>
            </div>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Social Media</h6>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-medium"><i class="fab fa-facebook me-1"></i> Facebook URL</label>
                    <input type="text" name="facebook_url" class="form-control"
                           value="<?= h($settings['facebook_url'] ?? '#') ?>" placeholder="https://facebook.com/…" maxlength="500">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium"><i class="fab fa-twitter me-1"></i> Twitter / X URL</label>
                    <input type="text" name="twitter_url" class="form-control"
                           value="<?= h($settings['twitter_url'] ?? '#') ?>" placeholder="https://x.com/…" maxlength="500">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium"><i class="fab fa-instagram me-1"></i> Instagram URL</label>
                    <input type="text" name="instagram_url" class="form-control"
                           value="<?= h($settings['instagram_url'] ?? '#') ?>" placeholder="https://instagram.com/…" maxlength="500">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium"><i class="fab fa-linkedin me-1"></i> LinkedIn URL</label>
                    <input type="text" name="linkedin_url" class="form-control"
                           value="<?= h($settings['linkedin_url'] ?? '#') ?>" placeholder="https://linkedin.com/…" maxlength="500">
                </div>
            </div>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Old Website Button</h6>

            <div class="row g-3 mb-4">
                <div class="col-md-8">
                    <label class="form-label fw-medium"><i class="fas fa-link me-1"></i> Old Website URL</label>
                    <input type="text" name="old_website_url" class="form-control"
                           value="<?= h($settings['old_website_url'] ?? '') ?>" placeholder="https://old.primeuniversity.edu.bd" maxlength="500">
                    <div class="form-text">The URL that the "Old Website" button in the navigation will point to.</div>
                </div>
                <div class="col-md-4 d-flex align-items-center pt-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="old_website_enabled" id="old_website_enabled" role="switch"
                               <?= !empty($settings['old_website_enabled']) && $settings['old_website_enabled'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="old_website_enabled">Enable Button</label>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                <i class="fas fa-save me-1"></i> Save Settings
            </button>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
