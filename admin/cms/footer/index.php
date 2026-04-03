<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Footer Settings';
$errors     = [];
$success    = false;

if (!defined('LOGO_EXTS')) {
    define('LOGO_EXTS',  ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
    define('LOGO_MIMES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']);
}

// Load current settings into associative array
$rows = db()->query('SELECT setting_key, setting_value FROM cms_footer_settings')->fetchAll();
$settings = [];
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $fields = [
        'about_text', 'cta_text', 'cta_url',
        'col2_title',
        'col2_link_1_text', 'col2_link_1_url',
        'col2_link_2_text', 'col2_link_2_url',
        'col2_link_3_text', 'col2_link_3_url',
        'col2_link_4_text', 'col2_link_4_url',
        'col2_link_5_text', 'col2_link_5_url',
        'col3_title',
        'col3_link_1_text', 'col3_link_1_url',
        'col3_link_2_text', 'col3_link_2_url',
        'col3_link_3_text', 'col3_link_3_url',
        'col3_link_4_text', 'col3_link_4_url',
        'col3_link_5_text', 'col3_link_5_url',
        'contact_phone', 'contact_email',
        'contact_address', 'contact_address_url',
        'copyright_text',
    ];

    $stmt = db()->prepare(
        'INSERT INTO cms_footer_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($fields as $key) {
        $value = trim($_POST[$key] ?? '');
        $stmt->execute([$key, $value ?: null]);
        $settings[$key] = $value;
    }

    // Handle footer logo upload
    if (!empty($_FILES['logo_footer']['name'])) {
        $f = $_FILES['logo_footer'];
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
                    if (!empty($settings['logo_footer'])) {
                        $old = $dir . '/' . $settings['logo_footer'];
                        if (is_file($old)) unlink($old);
                    }
                    $filename = 'footer_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $filename)) {
                        $errors[] = 'Failed to save logo. Check server permissions.';
                    } else {
                        $stmt->execute(['logo_footer', $filename]);
                        $settings['logo_footer'] = $filename;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        flash_set('success', 'Footer settings saved.');
        redirect(APP_URL . '/cms/footer/index.php');
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Footer Settings</li>
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

<form method="POST" enctype="multipart/form-data" novalidate>
<?= csrf_field() ?>

<div class="row g-4">

    <!-- Footer Logo -->
    <div class="col-12">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Footer Logo</h6>
            </div>
            <div class="card-body p-4">
                <div class="row align-items-center g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-medium">Footer Logo <span class="text-muted fw-normal small">(displayed on the dark footer background)</span></label>
                        <?php if (!empty($settings['logo_footer'])): ?>
                        <div class="mb-2 p-2 border rounded d-inline-block" style="background:#1e293b;">
                            <img src="<?= UPLOAD_URL ?>/logos/<?= h($settings['logo_footer']) ?>"
                                 alt="Current Footer Logo" style="max-height:60px;max-width:200px;object-fit:contain;">
                            <div class="text-muted mt-1" style="font-size:.75rem;color:#adb5bd!important;">Current logo</div>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="logo_footer" class="form-control" id="logoFooterInput"
                               accept=".jpg,.jpeg,.png,.gif,.webp,.svg">
                        <div class="form-text">JPG, PNG, GIF, WebP or SVG. A white/light version is recommended for the dark footer.</div>
                        <div id="logoFooterPreviewWrap" class="mt-2" style="display:none;background:#1e293b;padding:8px;border-radius:6px;">
                            <img id="logoFooterPreview" src="" alt="Preview"
                                 style="max-height:60px;max-width:200px;object-fit:contain;">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>About Column</h6>
            </div>
            <div class="card-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-medium">About / Tagline Text</label>
                    <textarea name="about_text" class="form-control" rows="3" maxlength="500"><?= h($settings['about_text'] ?? '') ?></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label fw-medium">CTA Button Text</label>
                        <input type="text" name="cta_text" class="form-control"
                               value="<?= h($settings['cta_text'] ?? 'Contact Us') ?>" maxlength="60">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label fw-medium">CTA Button URL</label>
                        <input type="text" name="cta_url" class="form-control"
                               value="<?= h($settings['cta_url'] ?? 'contact.php') ?>" placeholder="contact.php" maxlength="500">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Info -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-phone me-2 text-muted"></i>Contact Info Column</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-medium">Phone</label>
                        <input type="text" name="contact_phone" class="form-control"
                               value="<?= h($settings['contact_phone'] ?? '') ?>" placeholder="+880-1710-996196" maxlength="60">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-medium">Email</label>
                        <input type="email" name="contact_email" class="form-control"
                               value="<?= h($settings['contact_email'] ?? '') ?>" placeholder="info@primeuniversity.edu.bd" maxlength="191">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium">Address Text</label>
                    <input type="text" name="contact_address" class="form-control"
                           value="<?= h($settings['contact_address'] ?? '') ?>" placeholder="114/116, Mazar Rd, Dhaka-1216" maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-medium">Address Map URL</label>
                    <input type="text" name="contact_address_url" class="form-control"
                           value="<?= h($settings['contact_address_url'] ?? '#') ?>" placeholder="https://maps.google.com/…" maxlength="500">
                </div>
                <div class="mb-0">
                    <label class="form-label fw-medium">Copyright Name</label>
                    <input type="text" name="copyright_text" class="form-control"
                           value="<?= h($settings['copyright_text'] ?? 'Prime University') ?>" placeholder="Prime University" maxlength="100">
                    <div class="form-text">Appears as: Copyright © [year] <strong>[this text]</strong>. All Rights Reserved.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Column 2 – Quick Links -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-link me-2 text-muted"></i>Link Column 2</h6>
            </div>
            <div class="card-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-medium">Column Heading</label>
                    <input type="text" name="col2_title" class="form-control"
                           value="<?= h($settings['col2_title'] ?? 'Quick Links') ?>" maxlength="80">
                </div>
                <h6 class="fw-semibold mb-3 mt-2 text-muted border-bottom pb-2 small text-uppercase">Links (up to 5)</h6>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="row g-2 mb-2">
                    <div class="col-5">
                        <input type="text" name="col2_link_<?= $i ?>_text" class="form-control form-control-sm"
                               value="<?= h($settings["col2_link_{$i}_text"] ?? '') ?>"
                               placeholder="Link <?= $i ?> text" maxlength="80">
                    </div>
                    <div class="col-7">
                        <input type="text" name="col2_link_<?= $i ?>_url" class="form-control form-control-sm"
                               value="<?= h($settings["col2_link_{$i}_url"] ?? '') ?>"
                               placeholder="URL or page.php" maxlength="500">
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- Column 3 – Student Services -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-link me-2 text-muted"></i>Link Column 3</h6>
            </div>
            <div class="card-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-medium">Column Heading</label>
                    <input type="text" name="col3_title" class="form-control"
                           value="<?= h($settings['col3_title'] ?? 'Student Services') ?>" maxlength="80">
                </div>
                <h6 class="fw-semibold mb-3 mt-2 text-muted border-bottom pb-2 small text-uppercase">Links (up to 5)</h6>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="row g-2 mb-2">
                    <div class="col-5">
                        <input type="text" name="col3_link_<?= $i ?>_text" class="form-control form-control-sm"
                               value="<?= h($settings["col3_link_{$i}_text"] ?? '') ?>"
                               placeholder="Link <?= $i ?> text" maxlength="80">
                    </div>
                    <div class="col-7">
                        <input type="text" name="col3_link_<?= $i ?>_url" class="form-control form-control-sm"
                               value="<?= h($settings["col3_link_{$i}_url"] ?? '') ?>"
                               placeholder="URL or page.php" maxlength="500">
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

</div><!-- /.row -->

<div class="mt-4">
    <button type="submit" class="btn btn-primary" style="border-radius:10px;">
        <i class="fas fa-save me-1"></i> Save Footer Settings
    </button>
</div>

</form>

<script>
document.getElementById('logoFooterInput').addEventListener('change', function () {
    var wrap    = document.getElementById('logoFooterPreviewWrap');
    var preview = document.getElementById('logoFooterPreview');
    if (this.files && this.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) { preview.src = e.target.result; wrap.style.display = 'block'; };
        reader.readAsDataURL(this.files[0]);
    } else {
        wrap.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
