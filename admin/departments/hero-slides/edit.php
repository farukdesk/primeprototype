<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-hero-slides', 'can_edit');

$id      = (int)($_GET['id'] ?? 0);
$dept_id = (int)($_GET['dept_id'] ?? 0);
$errors  = [];

$slide = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM dept_hero_slides WHERE id = ?');
    $st->execute([$id]);
    $slide = $st->fetch();
}
if (!$slide) { flash_set('error', 'Slide not found.'); redirect(APP_URL . '/departments/index.php'); }

$dept_id = $dept_id ?: (int)$slide['dept_id'];

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }
require_access_dept($dept_id);

$page_title = 'Edit Hero Slide – ' . $dept['name'];
clear_old();

const HERO_SLIDE_EXTS_ED  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const HERO_SLIDE_MIMES_ED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $caption    = trim($_POST['caption']    ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;
    $image      = $slide['image'];

    if (!empty($_FILES['image']['name'])) {
        $f = $_FILES['image'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload failed (code ' . $f['error'] . ').';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, HERO_SLIDE_EXTS_ED, true)) {
                $errors[] = 'Unsupported format. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, HERO_SLIDE_MIMES_ED, true)) {
                    $errors[] = 'Image MIME type not allowed.';
                } else {
                    $dir = UPLOAD_DIR . '/departments';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $new_name = bin2hex(random_bytes(12)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $new_name)) {
                        $errors[] = 'Failed to save image. Check server permissions.';
                    } else {
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
            'UPDATE dept_hero_slides SET image=?, caption=?, sort_order=?, is_active=? WHERE id=?'
        )->execute([$image, $caption ?: null, $sort_order, $is_active, $id]);

        flash_set('success', 'Hero slide updated.');
        redirect(APP_URL . '/departments/hero-slides/index.php?dept_id=' . $dept_id);
    }

    $slide = array_merge($slide, compact('caption', 'sort_order', 'is_active'));
    $slide['image'] = $image;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/hero-slides/index.php?dept_id=<?= $dept_id ?>">Hero Slides</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-6">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-images me-2 text-muted"></i>Edit Hero Slide</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label fw-medium">Image</label>
                <div class="mb-2">
                    <img src="<?= UPLOAD_URL ?>/departments/<?= h($slide['image']) ?>"
                         alt="Current slide" id="heroEditPreview"
                         style="max-height:160px;border-radius:10px;object-fit:cover;display:block;">
                </div>
                <input type="file" name="image" class="form-control" style="border-radius:10px;"
                       accept=".jpg,.jpeg,.png,.gif,.webp" id="heroEditInput">
                <div class="form-text">Leave empty to keep current image. JPG, PNG, GIF, WebP.</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Caption <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" name="caption" class="form-control" style="border-radius:10px;"
                       value="<?= h($slide['caption'] ?? '') ?>" maxlength="300" placeholder="Short image caption">
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-5">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= (int)$slide['sort_order'] ?>" min="0">
                </div>
                <div class="col-md-7 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $slide['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Slide
                </button>
                <a href="<?= APP_URL ?>/departments/hero-slides/index.php?dept_id=<?= $dept_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
document.getElementById('heroEditInput').addEventListener('change', function () {
    var preview = document.getElementById('heroEditPreview');
    if (this.files && this.files[0]) {
        var reader = new FileReader();
        reader.onload = function (e) { preview.src = e.target.result; };
        reader.readAsDataURL(this.files[0]);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
