<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$id     = (int)($_GET['id'] ?? 0);
$slider = null;
$errors = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM cms_sliders WHERE id = ?');
    $stmt->execute([$id]);
    $slider = $stmt->fetch();
}
if (!$slider) {
    flash_set('error', 'Slide not found.');
    redirect(APP_URL . '/cms/sliders/index.php');
}

clear_old();
$page_title = 'Edit Slide';

const SLIDER_EXTS_ED  = ['jpg','jpeg','png','gif','webp'];
const SLIDER_MIMES_ED = ['image/jpeg','image/png','image/gif','image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title      = trim($_POST['title']      ?? '');
    $subtitle   = trim($_POST['subtitle']   ?? '');
    $link_url   = trim($_POST['link_url']   ?? '');
    $link_text  = trim($_POST['link_text']  ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    $image = $slider['image'];

    if (!empty($_FILES['image']['name'])) {
        $f = $_FILES['image'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload failed (code ' . $f['error'] . ').';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, SLIDER_EXTS_ED, true)) {
                $errors[] = 'Image: unsupported format. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, SLIDER_MIMES_ED, true)) {
                    $errors[] = 'Image: MIME type not allowed.';
                } else {
                    $dir = UPLOAD_DIR . '/sliders';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $new_name = bin2hex(random_bytes(12)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $new_name)) {
                        $errors[] = 'Failed to save image. Check server permissions.';
                    } else {
                        // Delete old image
                        $old_path = $dir . '/' . $image;
                        if (file_exists($old_path)) @unlink($old_path);
                        $image = $new_name;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE cms_sliders
             SET title=?, subtitle=?, image=?, link_url=?, link_text=?, sort_order=?, is_active=?
             WHERE id=?'
        )->execute([
            $title ?: null, $subtitle ?: null, $image,
            $link_url ?: null, $link_text ?: null,
            $sort_order, $is_active, $id,
        ]);

        flash_set('success', 'Slide updated successfully.');
        redirect(APP_URL . '/cms/sliders/index.php');
    }

    $slider = array_merge($slider, compact('title','subtitle','link_url','link_text','sort_order','is_active'));
    $slider['image'] = $image;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/sliders/index.php">Sliders</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Slide</h6>
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

            <!-- Current image -->
            <div class="mb-3">
                <label class="form-label fw-medium">Current Image</label>
                <div>
                    <img src="<?= UPLOAD_URL ?>/sliders/<?= h($slider['image']) ?>"
                         alt="" style="max-height:180px;border-radius:8px;object-fit:cover;"
                         onerror="this.style.opacity='.3'">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Replace Image</label>
                <input type="file" name="image" class="form-control" id="imageInput"
                       accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="form-text">Leave blank to keep current image.</div>
                <div id="imgPreviewWrap" class="mt-2" style="display:none;">
                    <img id="imgPreview" src="" alt="New preview"
                         style="max-height:180px;border-radius:8px;object-fit:contain;">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Title</label>
                <input type="text" name="title" class="form-control" value="<?= h($slider['title'] ?? '') ?>"
                       maxlength="255">
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Subtitle / Description</label>
                <textarea name="subtitle" class="form-control" rows="2"
                          maxlength="500"><?= h($slider['subtitle'] ?? '') ?></textarea>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">Link URL</label>
                    <input type="text" name="link_url" class="form-control"
                           value="<?= h($slider['link_url'] ?? '') ?>" maxlength="500">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Button Text</label>
                    <input type="text" name="link_text" class="form-control"
                           value="<?= h($slider['link_text'] ?? '') ?>" maxlength="150">
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= (int)$slider['sort_order'] ?>" min="0">
                </div>
                <div class="col-md-8 d-flex align-items-end mb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               value="1" <?= $slider['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Slide
                </button>
                <a href="<?= APP_URL ?>/cms/sliders/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
document.getElementById('imageInput').addEventListener('change', function () {
    var wrap    = document.getElementById('imgPreviewWrap');
    var preview = document.getElementById('imgPreview');
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
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
