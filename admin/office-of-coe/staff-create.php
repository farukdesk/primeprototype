<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-coe', 'can_create');

$page_title = 'Add Staff Member';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name       = trim($_POST['name']       ?? '');
    $title      = trim($_POST['title']      ?? '');
    $email_1    = trim($_POST['email_1']    ?? '');
    $email_2    = trim($_POST['email_2']    ?? '');
    $phone      = trim($_POST['phone']      ?? '');
    $phone_2    = trim($_POST['phone_2']    ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Name is required.';

    $photo = '';
    if (!empty($_FILES['photo']['name'])) {
        $file  = $_FILES['photo'];
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if ($file['error'] !== UPLOAD_ERR_OK || !in_array($ext, $allowed_exts, true) || !in_array($mime, $allowed_mimes, true)) {
            $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            $dir = UPLOAD_DIR . '/office-of-coe';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $photo = bin2hex(random_bytes(12)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $photo)) {
                $errors[] = 'Failed to save photo.'; $photo = '';
            }
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO coe_staff (name, title, email_1, email_2, phone, phone_2, photo, sort_order, is_active)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$name, $title, $email_1, $email_2, $phone, $phone_2, $photo, $sort_order, $is_active]);
        flash_set('success', 'Staff member added.');
        redirect(APP_URL . '/office-of-coe/staff-index.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-coe/index.php">Office of COE</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-coe/staff-index.php">Staff Directory</a></li>
            <li class="breadcrumb-item active">Add Staff Member</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/office-of-coe/staff-index.php" class="btn btn-sm btn-light" style="border-radius:8px;">
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
        <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-muted"></i>Staff Member Details</h6></div>
        <div class="card-body p-4">

            <div class="mb-4">
                <label class="form-label fw-medium">Profile Photo</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div id="photo-preview-placeholder" style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:2px dashed #cbd5e1;">
                        <i class="fas fa-user" style="color:#94a3b8;font-size:1.6rem;"></i>
                    </div>
                    <div>
                        <input type="file" name="photo" id="photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp" style="max-width:280px;">
                        <div class="form-text">JPG, PNG, GIF, WebP. Recommended: square, min 200×200px.</div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-8"><label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?= h($_POST['name'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" min="0" value="<?= h($_POST['sort_order'] ?? '0') ?>"></div>
                <div class="col-12"><label class="form-label fw-medium">Title / Designation</label>
                    <input type="text" name="title" class="form-control" value="<?= h($_POST['title'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Primary Email</label>
                    <input type="email" name="email_1" class="form-control" value="<?= h($_POST['email_1'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Secondary Email</label>
                    <input type="email" name="email_2" class="form-control" value="<?= h($_POST['email_2'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= h($_POST['phone'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label fw-medium">Phone 2</label>
                    <input type="text" name="phone_2" class="form-control" value="<?= h($_POST['phone_2'] ?? '') ?>"></div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label fw-medium" for="is_active">Active (visible on public page)</label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save me-1"></i> Add Staff Member</button>
        <a href="<?= APP_URL ?>/office-of-coe/staff-index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
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
