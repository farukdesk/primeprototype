<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'About Section';
$errors     = [];

const ABOUT_IMG_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const ABOUT_IMG_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

// Load current settings
$rows = db()->query('SELECT setting_key, setting_value FROM cms_about_settings')->fetchAll();
$settings = [];
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Handle main_image file upload
    $new_image = null;
    if (!empty($_FILES['main_image_file']['name'])) {
        $f = $_FILES['main_image_file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload failed (code ' . $f['error'] . ').';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ABOUT_IMG_EXTS, true)) {
                $errors[] = 'Image: unsupported format. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, ABOUT_IMG_MIMES, true)) {
                    $errors[] = 'Image: MIME type not allowed.';
                } else {
                    $dir = UPLOAD_DIR . '/about';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $new_image = bin2hex(random_bytes(12)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $new_image)) {
                        $errors[] = 'Failed to save image. Check server permissions.';
                        $new_image = null;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        $fields = [
            'description',
            'about_section_subtitle', 'about_section_title', 'about_section_title_accent',
            'badge_number', 'badge_text',
            'list_item_1', 'list_item_2', 'list_item_3', 'list_item_4', 'list_item_5',
            'apply_url', 'contact_url',
        ];

        $stmt = db()->prepare(
            'INSERT INTO cms_about_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach ($fields as $key) {
            $value = trim($_POST[$key] ?? '');
            $stmt->execute([$key, $value ?: null]);
            $settings[$key] = $value;
        }

        // Only update main_image if a new file was uploaded
        if ($new_image !== null) {
            $stmt->execute(['main_image', $new_image]);
            $settings['main_image'] = $new_image;
        }

        flash_set('success', 'About section settings saved.');
        redirect(APP_URL . '/cms/about/index.php');
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">About Section</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>About Section Settings</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <h6 class="fw-semibold mb-3 text-muted border-bottom pb-2">Section Heading</h6>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Section Sub-label</label>
                    <input type="text" name="about_section_subtitle" class="form-control"
                           value="<?= h($settings['about_section_subtitle'] ?? 'About the University') ?>" maxlength="100">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium">Section Title</label>
                    <input type="text" name="about_section_title" class="form-control"
                           value="<?= h($settings['about_section_title'] ?? 'Shaping Leaders Since') ?>" maxlength="255">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Title Accent</label>
                    <input type="text" name="about_section_title_accent" class="form-control"
                           value="<?= h($settings['about_section_title_accent'] ?? '1993') ?>" maxlength="50">
                    <div class="form-text">e.g. the founding year "1993"</div>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-medium">Description Paragraph</label>
                <textarea name="description" class="form-control" rows="3"><?= h($settings['description'] ?? '') ?></textarea>
            </div>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">About Image &amp; Badge</h6>

            <div class="mb-3">
                <label class="form-label fw-medium">Main Image URL</label>
                <input type="text" name="main_image" class="form-control"
                       value="<?= h($settings['main_image'] ?? '') ?>"
                       placeholder="https://… or relative path" maxlength="500">
                <div class="form-text">URL of the about section image.</div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label fw-medium">Badge Number</label>
                    <input type="text" name="badge_number" class="form-control"
                           value="<?= h($settings['badge_number'] ?? '32+') ?>" maxlength="20">
                </div>
                <div class="col-md-9">
                    <label class="form-label fw-medium">Badge Text</label>
                    <input type="text" name="badge_text" class="form-control"
                           value="<?= h($settings['badge_text'] ?? 'Years of Excellence') ?>" maxlength="100">
                </div>
            </div>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Bullet Points (About section list)</h6>

            <?php for ($i = 1; $i <= 5; $i++): ?>
            <div class="mb-3">
                <label class="form-label fw-medium">List Item <?= $i ?></label>
                <input type="text" name="list_item_<?= $i ?>" class="form-control"
                       value="<?= h($settings["list_item_{$i}"] ?? '') ?>" maxlength="255">
            </div>
            <?php endfor; ?>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">CTA Button URLs</h6>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Apply URL</label>
                    <input type="text" name="apply_url" class="form-control"
                           value="<?= h($settings['apply_url'] ?? 'admission.php') ?>" maxlength="500">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Contact URL</label>
                    <input type="text" name="contact_url" class="form-control"
                           value="<?= h($settings['contact_url'] ?? 'contact.php') ?>" maxlength="500">
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
