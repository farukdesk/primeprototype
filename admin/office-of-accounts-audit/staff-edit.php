<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-accounts-audit', 'can_edit');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect(APP_URL . '/office-of-accounts-audit/staff-index.php'); }

$st = null;
try {
    $stmt = db()->prepare('SELECT * FROM aa_staff WHERE id = ?');
    $stmt->execute([$id]);
    $st = $stmt->fetch();
} catch (Throwable $e) {}
if (!$st) { flash_set('error', 'Staff member not found.'); redirect(APP_URL . '/office-of-accounts-audit/staff-index.php'); }

$page_title = 'Edit Staff Member';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name       = trim($_POST['name']       ?? '');
    $title      = trim($_POST['title']      ?? '');
    $email_1    = trim($_POST['email_1']    ?? '');
    $email_2    = trim($_POST['email_2']    ?? '');
    $phone      = trim($_POST['phone']      ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Name is required.';

    $photo = $st['photo'];
    if (!empty($_FILES['photo']['name'])) {
        $file  = $_FILES['photo'];
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if ($file['error'] !== UPLOAD_ERR_OK || !in_array($ext, $allowed_exts, true) || !in_array($mime, $allowed_mimes, true)) {
            $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            $dir = UPLOAD_DIR . '/office-of-accounts-audit';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $newname = bin2hex(random_bytes(12)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $newname)) {
                $errors[] = 'Failed to save photo.';
            } else {
                if ($photo) { $old = $dir . '/' . $photo; if (is_file($old)) @unlink($old); }
                $photo = $newname;
            }
        }
    }
    if (isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
        if ($photo) { $old = UPLOAD_DIR . '/office-of-accounts-audit/' . $photo; if (is_file($old)) @unlink($old); }
        $photo = '';
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE aa_staff SET name=?, title=?, email_1=?, email_2=?, phone=?, photo=?, sort_order=?, is_active=? WHERE id=?'
        )->execute([$name, $title, $email_1, $email_2, $phone, $photo, $sort_order, $is_active, $id]);
        flash_set('success', 'Staff member updated.');
        redirect(APP_URL . '/office-of-accounts-audit/staff-index.php');
    }

    $st = array_merge($st, compact('name','title','email_1','email_2','phone','sort_order','is_active','photo'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-accounts-audit/index.php">Office of Accounts &amp; Audit</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-accounts-audit/staff-index.php">Staff Directory</a></li>
            <li class="breadcrumb-item active">Edit Staff Member</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/office-of-accounts-audit/staff-index.php" class="btn btn-sm btn-light" style="border-radius:8px;">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php flash_show(); ?>
<?php if ($errors): ?>
<div class="alert alert-danger" style="border-radius:10px;"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center"><div class="col-lg-8">
<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-user-edit me-2 text-muted"></i>Edit Staff Member</h6></div>
        <div class="card-body p-4">

            <div class="mb-4">
                <label class="form-label fw-medium">Profile Photo</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <?php if (!empty($st['photo'])): ?>
                    <img src="<?= UPLOAD_URL ?>/office-of-accounts-audit/<?= h($st['photo']) ?>" id="photo-preview"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #e8eaf0;" alt="Staff Photo">
                    <?php else: ?>
                    <div id="photo-preview-placeholder" style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:2px dashed #cbd5e1;">
                        <i class="fas fa-user" style="color:#94a3b8;font-size:1.6rem;"></i></div>
                    <?php endif; ?>
                    <div>
                        <input type="file" name="photo" id="photo" class="form-control mb-2" accept="image/jpeg,image/png,image/gif,image/webp" style="max-width:280px;">
                        <div class="form-text mb-2">JPG, PNG, GIF, WebP. Recommended: square, min 200×200px.</div>
                        <?php if (!empty($st['photo'])): ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="remove_photo" name="remove_photo" value="1">
                            <label class="form-check-label text-danger" for="remove_photo" style="font-size:.83rem;">Remove current photo</label></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-8"><label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?= h($st['name']) ?>"></div>
                <div class="col-md-4"><label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" min="0" value="<?= h((string)$st['sort_order']) ?>"></div>
                <div class="col-12"><label class="form-label fw-medium">Title / Designation</label>
                    <input type="text" name="title" class="form-control" value="<?= h($st['title']) ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Primary Email</label>
                    <input type="email" name="email_1" class="form-control" value="<?= h($st['email_1']) ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Secondary Email</label>
                    <input type="email" name="email_2" class="form-control" value="<?= h($st['email_2']) ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= h($st['phone']) ?>"></div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $st['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_active">Active (visible on public page)</label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save me-1"></i> Save Changes</button>
        <a href="<?= APP_URL ?>/office-of-accounts-audit/staff-index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>
</div></div>

<script>
document.getElementById('photo')?.addEventListener('change', function () {
    const file = this.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        let img = document.getElementById('photo-preview');
        if (!img) { img = document.createElement('img'); img.id = 'photo-preview';
            img.style.cssText = 'width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:top center;border:3px solid #e8eaf0;';
            const p = document.getElementById('photo-preview-placeholder'); if (p) p.replaceWith(img); }
        img.src = e.target.result;
    }; reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
