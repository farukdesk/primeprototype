<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Header Settings';
$errors     = [];
$success    = false;

if (!defined('LOGO_EXTS')) {
    define('LOGO_EXTS',  ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
    define('LOGO_MIMES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']);
}

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

    // Handle header logo upload
    if (!empty($_FILES['logo_header']['name'])) {
        $f = $_FILES['logo_header'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Logo upload failed (code ' . $f['error'] . ').';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, LOGO_EXTS, true)) {
                $errors[] = 'Logo: unsupported format. Allowed: JPG, PNG, GIF, WebP, SVG.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, LOGO_MIMES, true)) {
                    $errors[] = 'Logo: MIME type not allowed.';
                } else {
                    $dir = UPLOAD_DIR . '/logos';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    // Delete old logo file if it exists
                    if (!empty($settings['logo_header'])) {
                        $old = $dir . '/' . $settings['logo_header'];
                        if (is_file($old)) unlink($old);
                    }
                    $filename = 'header_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $filename)) {
                        $errors[] = 'Failed to save logo. Check server permissions.';
                    } else {
                        $stmt->execute(['logo_header', $filename]);
                        $settings['logo_header'] = $filename;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        flash_set('success', 'Header settings saved.');
        redirect(APP_URL . '/cms/header/index.php');
    }
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

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row">
<div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-heading me-2 text-muted"></i>Header Top Bar Settings</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <h6 class="fw-semibold mb-3 text-muted border-bottom pb-2">Site Logo (Header)</h6>

            <div class="mb-4">
                <label class="form-label fw-medium">Header Logo <span class="text-muted fw-normal small">(displayed in the navigation bar)</span></label>
                <?php if (!empty($settings['logo_header'])): ?>
                <div class="mb-2 p-2 border rounded d-inline-block" style="background:#f8f9fa;">
                    <img src="<?= UPLOAD_URL ?>/logos/<?= h($settings['logo_header']) ?>"
                         alt="Current Header Logo" style="max-height:60px;max-width:200px;object-fit:contain;">
                    <div class="text-muted mt-1" style="font-size:.75rem;">Current logo</div>
                </div>
                <?php endif; ?>
                <input type="file" name="logo_header" class="form-control" id="logoHeaderInput"
                       accept=".jpg,.jpeg,.png,.gif,.webp,.svg">
                <div class="form-text">JPG, PNG, GIF, WebP or SVG. Upload a new file to replace the current logo.</div>
                <div id="logoHeaderPreviewWrap" class="mt-2" style="display:none;">
                    <img id="logoHeaderPreview" src="" alt="Preview"
                         style="max-height:60px;max-width:200px;border-radius:6px;object-fit:contain;">
                </div>
            </div>

            <h6 class="fw-semibold mb-3 text-muted border-bottom pb-2">Contact Info</h6>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone Number</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= h($settings['phone'] ?? '') ?>" placeholder="01969-955566" maxlength="30">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email Address</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= h($settings['email'] ?? '') ?>" placeholder="info@primeuniversity.ac.bd" maxlength="191">
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

            <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                <i class="fas fa-save me-1"></i> Save Settings
            </button>
        </form>
    </div>
</div>
</div>
</div>

<script>
document.getElementById('logoHeaderInput').addEventListener('change', function () {
    var wrap    = document.getElementById('logoHeaderPreviewWrap');
    var preview = document.getElementById('logoHeaderPreview');
    if (this.files && this.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) { preview.src = e.target.result; wrap.style.display = ''; };
        reader.readAsDataURL(this.files[0]);
    } else {
        wrap.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
