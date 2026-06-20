<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Popup Settings';

// ── Upload helpers ────────────────────────────────────────────────────────────
define('POPUP_UPLOAD_SUBDIR', 'popup');
define('POPUP_MAX_SIZE',      5242880);  // 5 MB
define('POPUP_ALLOWED_EXTS',  ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('POPUP_ALLOWED_MIMES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

/**
 * Attempt to upload a popup image.
 * Returns the stored filename on success, or an error code string on failure:
 *   'size'  – file exceeds size limit
 *   'type'  – disallowed extension or MIME type
 *   'error' – any other upload/move failure
 */
function popup_upload_image(array $file): string|false
{
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) return false;
    if ($file['error'] !== UPLOAD_ERR_OK)        return false;
    if ($file['size'] > POPUP_MAX_SIZE)           return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, POPUP_ALLOWED_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, POPUP_ALLOWED_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/' . POPUP_UPLOAD_SUBDIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;

    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) return false;

    return $filename;
}

function popup_delete_image(?string $filename): void
{
    if (!$filename) return;
    $path = UPLOAD_DIR . '/' . POPUP_UPLOAD_SUBDIR . '/' . $filename;
    if (is_file($path)) unlink($path);
}

// ── Load current settings ─────────────────────────────────────────────────────
$rows = db()->query('SELECT setting_key, setting_value FROM popup_settings')->fetchAll();
$s    = [];
foreach ($rows as $r) {
    $s[$r['setting_key']] = $r['setting_value'];
}

$errors = [];

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $is_active     = isset($_POST['is_active']) ? '1' : '0';
    $popup_type    = in_array($_POST['popup_type'] ?? '', ['text', 'image'], true)
                     ? $_POST['popup_type'] : 'text';
    $title         = trim($_POST['title']         ?? '');
    $content       = trim($_POST['content']       ?? '');
    $image_alt     = trim($_POST['image_alt']     ?? '');
    $image_link    = trim($_POST['image_link']    ?? '');
    $btn_text      = trim($_POST['btn_text']      ?? '');
    $btn_url       = trim($_POST['btn_url']       ?? '');
    $btn_target    = ($_POST['btn_target'] ?? '_self') === '_blank' ? '_blank' : '_self';
    $delay_seconds = max(0, (int)($_POST['delay_seconds'] ?? 1));
    $expire_hours  = max(1, (int)($_POST['expire_hours']  ?? 12));

    // Handle image upload
    $current_image = $s['image'] ?? null;
    $new_image     = $current_image;

    if (!empty($_FILES['image']['name'])) {
        $uploaded = popup_upload_image($_FILES['image']);
        if ($uploaded) {
            popup_delete_image($current_image);
            $new_image = $uploaded;
        } else {
            $errors[] = 'Image upload failed. Use JPG/PNG/GIF/WebP under 5 MB.';
        }
    }

    // Handle image removal
    if (isset($_POST['remove_image']) && $new_image) {
        popup_delete_image($new_image);
        $new_image = null;
    }

    if (empty($errors)) {
        $stmt = db()->prepare(
            'INSERT INTO popup_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $fields = [
            'is_active'     => $is_active,
            'popup_type'    => $popup_type,
            'title'         => $title ?: null,
            'content'       => $content ?: null,
            'image'         => $new_image,
            'image_alt'     => $image_alt ?: null,
            'image_link'    => $image_link ?: null,
            'btn_text'      => $btn_text ?: null,
            'btn_url'       => $btn_url ?: null,
            'btn_target'    => $btn_target,
            'delay_seconds' => (string)$delay_seconds,
            'expire_hours'  => (string)$expire_hours,
        ];
        foreach ($fields as $key => $value) {
            $stmt->execute([$key, $value]);
            $s[$key] = $value;
        }

        flash_set('success', 'Popup settings saved successfully.');
        redirect(APP_URL . '/cms/popup/index.php');
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Popup Settings</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php flash_show(); ?>

<div class="row g-4">

<!-- ── Left: Main settings ── -->
<div class="col-lg-8">

<form id="main-popup-form" method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <!-- Enable / Disable card -->
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-body p-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div>
                <h6 class="fw-semibold mb-1"><i class="fas fa-toggle-on me-2 text-muted"></i>Enable Popup</h6>
                <p class="text-muted mb-0" style="font-size:.85rem;">
                    When enabled, the popup is shown to visitors on their first visit (then again after the reset interval).
                </p>
            </div>
            <div class="form-check form-switch" style="min-width:60px;">
                <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                       style="width:3rem;height:1.5rem;cursor:pointer;"
                       <?= ($s['is_active'] ?? '0') === '1' ? 'checked' : '' ?>>
            </div>
        </div>
    </div>

    <!-- Popup type -->
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-layer-group me-2 text-muted"></i>Popup Type</h6>
        </div>
        <div class="card-body p-4">
            <div class="d-flex gap-3 flex-wrap">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="popup_type" id="type_text"
                           value="text" <?= ($s['popup_type'] ?? 'text') === 'text' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-medium" for="type_text">
                        <i class="fas fa-align-left me-1 text-primary"></i> Text / HTML
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="popup_type" id="type_image"
                           value="image" <?= ($s['popup_type'] ?? 'text') === 'image' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-medium" for="type_image">
                        <i class="fas fa-image me-1 text-success"></i> Image
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Title (both types) -->
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-heading me-2 text-muted"></i>Popup Title</h6>
        </div>
        <div class="card-body p-4">
            <input type="text" name="title" class="form-control" maxlength="255"
                   placeholder="e.g. Welcome to Prime University"
                   value="<?= h($s['title'] ?? '') ?>">
            <div class="form-text">Displayed at the top of the popup. Leave blank to hide the title bar.</div>
        </div>
    </div>

    <!-- Text content (shown only for text type) -->
    <div class="card mb-4" id="section-text" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-align-left me-2 text-muted"></i>Text Content</h6>
        </div>
        <div class="card-body p-4">
            <textarea name="content" id="popup_content" class="form-control" rows="6"
                      placeholder="Enter the popup body text or HTML…"><?= h($s['content'] ?? '') ?></textarea>
            <div class="form-text">Basic HTML is allowed (paragraphs, bold, links, etc.).</div>
        </div>
    </div>

    <!-- Image settings (shown only for image type) -->
    <div class="card mb-4" id="section-image" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Popup Image</h6>
        </div>
        <div class="card-body p-4">

            <?php if (!empty($s['image'])): ?>
            <div class="mb-3">
                <p class="form-label fw-medium mb-2">Current image:</p>
                <img src="<?= UPLOAD_URL ?>/<?= POPUP_UPLOAD_SUBDIR ?>/<?= h($s['image']) ?>"
                     alt="popup image"
                     style="max-width:100%;max-height:240px;border-radius:8px;border:1px solid #e0e0e0;">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="remove_image" id="remove_image">
                    <label class="form-check-label text-danger" for="remove_image">Remove current image</label>
                </div>
            </div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label fw-medium">Upload new image</label>
                <input type="file" name="image" class="form-control" accept="image/*">
                <div class="form-text">JPG, PNG, GIF or WebP — max 5 MB. Recommended width: 800px.</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Image alt text</label>
                <input type="text" name="image_alt" class="form-control" maxlength="255"
                       value="<?= h($s['image_alt'] ?? '') ?>"
                       placeholder="Brief description of the image">
            </div>

            <div class="mb-0">
                <label class="form-label fw-medium">Image click URL <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" name="image_link" class="form-control" maxlength="500"
                       value="<?= h($s['image_link'] ?? '') ?>"
                       placeholder="https://example.com/page">
                <div class="form-text">If set, the image becomes a clickable link.</div>
            </div>

        </div>
    </div>

    <!-- Action button -->
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-mouse-pointer me-2 text-muted"></i>Action Button <span class="fw-normal text-muted">(optional)</span></h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Button Text</label>
                    <input type="text" name="btn_text" class="form-control" maxlength="100"
                           value="<?= h($s['btn_text'] ?? '') ?>"
                           placeholder="e.g. Apply Now">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium">Button URL</label>
                    <input type="text" name="btn_url" class="form-control" maxlength="500"
                           value="<?= h($s['btn_url'] ?? '') ?>"
                           placeholder="admission.php or https://…">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Open in</label>
                    <select name="btn_target" class="form-select">
                        <option value="_self"  <?= ($s['btn_target'] ?? '_self') === '_self'  ? 'selected' : '' ?>>Same tab</option>
                        <option value="_blank" <?= ($s['btn_target'] ?? '_self') === '_blank' ? 'selected' : '' ?>>New tab</option>
                    </select>
                </div>
            </div>
            <div class="form-text mt-1">Leave Button Text blank to hide the action button.</div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save Settings
        </button>
    </div>

</form>

</div><!-- /col-lg-8 -->

<!-- ── Right: Behaviour settings + preview info ── -->
<div class="col-lg-4">

    <!-- Behaviour settings (inputs linked to main-popup-form via form= attribute) -->
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-sliders-h me-2 text-muted"></i>Behaviour</h6>
        </div>
        <div class="card-body p-4">
            <div class="mb-3">
                <label class="form-label fw-medium">Appearance delay (seconds)</label>
                <input type="number" name="delay_seconds" class="form-control" min="0" max="30"
                       form="main-popup-form"
                       value="<?= h($s['delay_seconds'] ?? '1') ?>">
                <div class="form-text">How many seconds after page load before the popup appears.</div>
            </div>
            <div class="mb-0">
                <label class="form-label fw-medium">Reset interval (hours)</label>
                <input type="number" name="expire_hours" class="form-control" min="1" max="720"
                       form="main-popup-form"
                       value="<?= h($s['expire_hours'] ?? '12') ?>">
                <div class="form-text">After this many hours the popup will be shown again to the same visitor.</div>
            </div>
        </div>
    </div>

    <!-- Status overview card -->
    <div class="card" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Status Overview</h6>
        </div>
        <div class="card-body p-4">
            <div class="d-flex justify-content-between mb-2" style="font-size:.875rem;">
                <span class="text-muted">Status</span>
                <span class="badge <?= ($s['is_active'] ?? '0') === '1' ? 'bg-success' : 'bg-secondary' ?>">
                    <?= ($s['is_active'] ?? '0') === '1' ? 'Active' : 'Disabled' ?>
                </span>
            </div>
            <div class="d-flex justify-content-between mb-2" style="font-size:.875rem;">
                <span class="text-muted">Type</span>
                <span class="fw-medium text-capitalize"><?= h($s['popup_type'] ?? 'text') ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2" style="font-size:.875rem;">
                <span class="text-muted">Delay</span>
                <span class="fw-medium"><?= (int)($s['delay_seconds'] ?? 1) ?>s</span>
            </div>
            <div class="d-flex justify-content-between" style="font-size:.875rem;">
                <span class="text-muted">Resets after</span>
                <span class="fw-medium"><?= (int)($s['expire_hours'] ?? 12) ?> hours</span>
            </div>
            <?php if (!empty($s['image'])): ?>
            <hr class="my-3">
            <div style="font-size:.8rem;color:#888;">
                <i class="fas fa-image me-1"></i>Image stored: <?= h($s['image']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /col-lg-4 -->

</div><!-- /row -->

<script>
(function () {
    // Toggle text / image sections based on popup type selection
    var radios   = document.querySelectorAll('input[name="popup_type"]');
    var secText  = document.getElementById('section-text');
    var secImage = document.getElementById('section-image');

    function toggleSections() {
        var val = document.querySelector('input[name="popup_type"]:checked').value;
        secText.style.display  = val === 'text'  ? '' : 'none';
        secImage.style.display = val === 'image' ? '' : 'none';
    }

    radios.forEach(function (r) { r.addEventListener('change', toggleSections); });
    toggleSections();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
