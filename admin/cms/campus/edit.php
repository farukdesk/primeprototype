<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$id     = (int)($_GET['id'] ?? 0);
$item   = null;
$errors = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM cms_campus_items WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch();
}
if (!$item) {
    flash_set('error', 'Campus item not found.');
    redirect(APP_URL . '/cms/campus/index.php');
}

clear_old();
$page_title = 'Edit Campus Item';

const CAMPUS_EXTS_ED  = ['jpg','jpeg','png','gif','webp'];
const CAMPUS_MIMES_ED = ['image/jpeg','image/png','image/gif','image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title      = trim($_POST['title']      ?? '');
    $link_url   = trim($_POST['link_url']   ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;
    $image      = $item['image'];

    if ($title === '') {
        $errors[] = 'Title is required.';
    }

    if (!empty($_FILES['image']['name'])) {
        $f = $_FILES['image'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload failed (code ' . $f['error'] . ').';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, CAMPUS_EXTS_ED, true)) {
                $errors[] = 'Image: unsupported format. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, CAMPUS_MIMES_ED, true)) {
                    $errors[] = 'Image: MIME type not allowed.';
                } else {
                    $dir = UPLOAD_DIR . '/campus';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $new_name = bin2hex(random_bytes(12)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $new_name)) {
                        $errors[] = 'Failed to save image. Check server permissions.';
                    } else {
                        if ($item['image']) {
                            $old_path = $dir . '/' . $item['image'];
                            if (file_exists($old_path)) @unlink($old_path);
                        }
                        $image = $new_name;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE cms_campus_items
             SET title=?, image=?, link_url=?, sort_order=?, is_active=?
             WHERE id=?'
        )->execute([$title, $image, $link_url ?: null, $sort_order, $is_active, $id]);

        flash_set('success', 'Campus item updated successfully.');
        redirect(APP_URL . '/cms/campus/index.php');
    }

    $item = array_merge($item, compact('title', 'link_url', 'sort_order', 'is_active'));
    $item['image'] = $image;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/campus/index.php">Campus Life</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Campus Item</h6>
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

            <div class="mb-3">
                <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control"
                       value="<?= h($item['title']) ?>" maxlength="255" required>
            </div>

            <?php if ($item['image']): ?>
            <div class="mb-3">
                <label class="form-label fw-medium">Current Image</label>
                <div>
                    <img src="<?= UPLOAD_URL ?>/campus/<?= h($item['image']) ?>"
                         alt="" style="max-height:180px;border-radius:8px;object-fit:cover;"
                         onerror="this.style.opacity='.3'">
                </div>
            </div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label fw-medium"><?= $item['image'] ? 'Replace Image' : 'Image' ?></label>
                <input type="file" name="image" class="form-control" id="imageInput"
                       accept=".jpg,.jpeg,.png,.gif,.webp">
                <?php if ($item['image']): ?>
                <div class="form-text">Leave blank to keep current image.</div>
                <?php endif; ?>
                <div id="imgPreviewWrap" class="mt-2" style="display:none;">
                    <img id="imgPreview" src="" alt="New preview"
                         style="max-height:180px;border-radius:8px;object-fit:contain;">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Link URL</label>
                <input type="text" name="link_url" class="form-control"
                       value="<?= h($item['link_url'] ?? '') ?>" maxlength="500">
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= (int)$item['sort_order'] ?>" min="0">
                </div>
                <div class="col-md-8 d-flex align-items-end mb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               value="1" <?= $item['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Item
                </button>
                <a href="<?= APP_URL ?>/cms/campus/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
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
