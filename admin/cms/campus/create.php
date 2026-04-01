<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Add Campus Item';
$errors     = [];
clear_old();

const CAMPUS_EXTS  = ['jpg','jpeg','png','gif','webp'];
const CAMPUS_MIMES = ['image/jpeg','image/png','image/gif','image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title      = trim($_POST['title']      ?? '');
    $link_url   = trim($_POST['link_url']   ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '') {
        $errors[] = 'Title is required.';
    }

    $image = null;
    if (!empty($_FILES['image']['name'])) {
        $f = $_FILES['image'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload failed (code ' . $f['error'] . ').';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, CAMPUS_EXTS, true)) {
                $errors[] = 'Image: unsupported format. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, CAMPUS_MIMES, true)) {
                    $errors[] = 'Image: MIME type not allowed.';
                } else {
                    $dir = UPLOAD_DIR . '/campus';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $image = bin2hex(random_bytes(12)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $image)) {
                        $errors[] = 'Failed to save image. Check server permissions.';
                        $image = null;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO cms_campus_items (title, image, link_url, sort_order, is_active)
             VALUES (?,?,?,?,?)'
        )->execute([$title, $image, $link_url ?: null, $sort_order, $is_active]);

        flash_set('success', 'Campus item added successfully.');
        redirect(APP_URL . '/cms/campus/index.php');
    }

    save_old(compact('title', 'link_url', 'sort_order'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/campus/index.php">Campus Life</a></li>
            <li class="breadcrumb-item active">Add Campus Item</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-university me-2 text-muted"></i>New Campus Item</h6>
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
                <input type="text" name="title" class="form-control" value="<?= old('title') ?>"
                       placeholder="e.g. Student Life" maxlength="255" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Image</label>
                <input type="file" name="image" class="form-control" id="imageInput"
                       accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="form-text">JPG, PNG, GIF, WebP. Recommended 600×400 px.</div>
                <div id="imgPreviewWrap" class="mt-2" style="display:none;">
                    <img id="imgPreview" src="" alt="Preview"
                         style="max-height:180px;border-radius:8px;object-fit:contain;">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Link URL</label>
                <input type="text" name="link_url" class="form-control" value="<?= old('link_url') ?>"
                       placeholder="about-us-v3.html or https://…" maxlength="500">
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= old('sort_order', '0') ?>" min="0">
                </div>
                <div class="col-md-8 d-flex align-items-end mb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Item
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
