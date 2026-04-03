<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'About Section';
$errors     = [];

// Load current settings
$rows = db()->query('SELECT setting_key, setting_value FROM cms_about_settings')->fetchAll();
$settings = [];
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Handle main_image file upload (takes priority over text URL field)
    $main_image_value = trim($_POST['main_image'] ?? $settings['main_image'] ?? '');
    if (!empty($_FILES['main_image_file']['name'])) {
        $f          = $_FILES['main_image_file'];
        $img_exts   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $img_mimes  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload failed (code ' . $f['error'] . ').';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $img_exts, true)) {
                $errors[] = 'Image: unsupported format. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, $img_mimes, true)) {
                    $errors[] = 'Image: MIME type not allowed.';
                } else {
                    $dir = UPLOAD_DIR . '/about';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname = bin2hex(random_bytes(12)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) {
                        $errors[] = 'Failed to save image. Check server write permissions.';
                    } else {
                        // Delete the old uploaded image if it was stored in our directory
                        $old_val = $settings['main_image'] ?? '';
                        if ($old_val && strpos($old_val, '/uploads/about/') !== false) {
                            $old_path = UPLOAD_DIR . '/about/' . basename($old_val);
                            if (file_exists($old_path)) unlink($old_path);
                        }
                        $main_image_value = UPLOAD_URL . '/about/' . $fname;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        $fields = [
            'subtitle', 'title', 'description', 'youtube_url', 'view_program_url',
            'mission_1_title', 'mission_2_title',
            'stat_1_number', 'stat_1_label',
            'stat_2_number', 'stat_2_label',
            'stat_3_number', 'stat_3_label',
            // v2 fields
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

        // Save main_image separately (may have been set from file upload)
        $stmt->execute(['main_image', $main_image_value ?: null]);
        $settings['main_image'] = $main_image_value;

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
        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <h6 class="fw-semibold mb-3 text-muted border-bottom pb-2">Section Heading</h6>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Subtitle Label</label>
                    <input type="text" name="subtitle" class="form-control"
                           value="<?= h($settings['subtitle'] ?? 'About us') ?>" maxlength="100">
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-medium">Main Title</label>
                    <input type="text" name="title" class="form-control"
                           value="<?= h($settings['title'] ?? '') ?>" maxlength="255">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Description Paragraph</label>
                <textarea name="description" class="form-control" rows="3"><?= h($settings['description'] ?? '') ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">YouTube Embed URL</label>
                <input type="text" name="youtube_url" class="form-control"
                       value="<?= h($settings['youtube_url'] ?? '') ?>"
                       placeholder="https://www.youtube.com/embed/VIDEO_ID" maxlength="500">
                <div class="form-text">Use the embed URL format, e.g. https://www.youtube.com/embed/0Cx-Xk5i6SM</div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-medium">"View Our Program" Button URL</label>
                <input type="text" name="view_program_url" class="form-control"
                       value="<?= h($settings['view_program_url'] ?? '#') ?>" maxlength="500">
            </div>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Mission Statement Items</h6>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Mission Item 1 Title</label>
                    <input type="text" name="mission_1_title" class="form-control"
                           value="<?= h($settings['mission_1_title'] ?? 'University Mission Statement') ?>" maxlength="150">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Mission Item 2 Title</label>
                    <input type="text" name="mission_2_title" class="form-control"
                           value="<?= h($settings['mission_2_title'] ?? 'University Mission Statement') ?>" maxlength="150">
                </div>
            </div>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Stats Counter</h6>

            <div class="row g-3 mb-4">
                <div class="col-md-2">
                    <label class="form-label fw-medium">Stat 1 – Number</label>
                    <input type="text" name="stat_1_number" class="form-control"
                           value="<?= h($settings['stat_1_number'] ?? '1') ?>" maxlength="20">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Stat 1 – Label</label>
                    <input type="text" name="stat_1_label" class="form-control"
                           value="<?= h($settings['stat_1_label'] ?? 'Modern Smart Campus') ?>" maxlength="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-medium">Stat 2 – Number</label>
                    <input type="text" name="stat_2_number" class="form-control"
                           value="<?= h($settings['stat_2_number'] ?? '4+') ?>" maxlength="20">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Stat 2 – Label</label>
                    <input type="text" name="stat_2_label" class="form-control"
                           value="<?= h($settings['stat_2_label'] ?? 'Hostel Facilities') ?>" maxlength="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-medium">Stat 3 – Number</label>
                    <input type="text" name="stat_3_number" class="form-control"
                           value="<?= h($settings['stat_3_number'] ?? '20+') ?>" maxlength="20">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Stat 3 – Label</label>
                    <input type="text" name="stat_3_label" class="form-control"
                           value="<?= h($settings['stat_3_label'] ?? 'Mentorship Programs') ?>" maxlength="100">
                </div>
            </div>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Section Heading (v2)</h6>

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
                    <div class="form-text">e.g. the year "1993"</div>
                </div>
            </div>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">About Image &amp; Badge</h6>

            <div class="mb-3">
                <label class="form-label fw-medium">Main Image</label>
                <?php if (!empty($settings['main_image'])): ?>
                <div class="mb-2">
                    <img src="<?= h($settings['main_image']) ?>" alt="Current about image"
                         style="max-height:180px;border-radius:8px;object-fit:cover;"
                         onerror="this.style.opacity='.3'">
                </div>
                <?php endif; ?>
                <input type="file" name="main_image_file" class="form-control mb-2" id="aboutImgInput"
                       accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="form-text">Upload an image (JPG, PNG, GIF, WebP). Leave blank to keep the current image.</div>
                <div id="aboutImgPreviewWrap" class="mt-2" style="display:none;">
                    <img id="aboutImgPreview" src="" alt="New image preview"
                         style="max-height:180px;border-radius:8px;object-fit:contain;">
                </div>
                <div class="mt-2">
                    <label class="form-label fw-medium">Or enter image URL</label>
                    <input type="text" name="main_image" class="form-control"
                           value="<?= h($settings['main_image'] ?? '') ?>"
                           placeholder="https://… or relative path" maxlength="500">
                    <div class="form-text">If a file is uploaded above, this field is ignored.</div>
                </div>
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

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Bullet Points (Why Choose Us list)</h6>

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
<script>
var aboutImgInput = document.getElementById('aboutImgInput');
if (aboutImgInput) {
    aboutImgInput.addEventListener('change', function () {
        var wrap    = document.getElementById('aboutImgPreviewWrap');
        var preview = document.getElementById('aboutImgPreview');
        if (this.files && this.files[0]) {
            var reader = new FileReader();
            reader.onload = function (e) {
                preview.src = e.target.result;
                wrap.style.display = '';
            };
            reader.readAsDataURL(this.files[0]);
        } else {
            wrap.style.display = 'none';
        }
    });
}
</script>
