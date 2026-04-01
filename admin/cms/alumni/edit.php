<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$id     = (int)($_GET['id'] ?? 0);
$al     = null;
$errors = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM cms_alumni WHERE id = ?');
    $stmt->execute([$id]);
    $al = $stmt->fetch();
}
if (!$al) {
    flash_set('error', 'Alumni record not found.');
    redirect(APP_URL . '/cms/alumni/index.php');
}

clear_old();
$page_title = 'Edit Alumni';

const ALUMNI_EXTS_ED  = ['jpg','jpeg','png','gif','webp'];
const ALUMNI_MIMES_ED = ['image/jpeg','image/png','image/gif','image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name         = trim($_POST['name']         ?? '');
    $designation  = trim($_POST['designation']  ?? '');
    $organization = trim($_POST['organization'] ?? '');
    $sort_order   = (int)($_POST['sort_order']  ?? 0);
    $is_active    = isset($_POST['is_active']) ? 1 : 0;
    $photo        = $al['photo'];

    if ($name === '') {
        $errors[] = 'Name is required.';
    }

    if (!empty($_FILES['photo']['name'])) {
        $f = $_FILES['photo'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Photo upload failed (code ' . $f['error'] . ').';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ALUMNI_EXTS_ED, true)) {
                $errors[] = 'Photo: unsupported format. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, ALUMNI_MIMES_ED, true)) {
                    $errors[] = 'Photo: MIME type not allowed.';
                } else {
                    $dir = UPLOAD_DIR . '/alumni';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $new_name = bin2hex(random_bytes(12)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $new_name)) {
                        $errors[] = 'Failed to save photo. Check server permissions.';
                    } else {
                        if ($al['photo']) {
                            $old_path = $dir . '/' . $al['photo'];
                            if (file_exists($old_path)) @unlink($old_path);
                        }
                        $photo = $new_name;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE cms_alumni
             SET name=?, designation=?, organization=?, photo=?, sort_order=?, is_active=?
             WHERE id=?'
        )->execute([$name, $designation ?: null, $organization ?: null, $photo, $sort_order, $is_active, $id]);

        flash_set('success', 'Alumni updated successfully.');
        redirect(APP_URL . '/cms/alumni/index.php');
    }

    $al = array_merge($al, compact('name', 'designation', 'organization', 'sort_order', 'is_active'));
    $al['photo'] = $photo;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/alumni/index.php">Notable Alumni</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Alumni</h6>
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
                <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control"
                       value="<?= h($al['name']) ?>" maxlength="255" required>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Designation</label>
                    <input type="text" name="designation" class="form-control"
                           value="<?= h($al['designation'] ?? '') ?>" maxlength="255">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Organization</label>
                    <input type="text" name="organization" class="form-control"
                           value="<?= h($al['organization'] ?? '') ?>" maxlength="255">
                </div>
            </div>

            <?php if ($al['photo']): ?>
            <div class="mb-3">
                <label class="form-label fw-medium">Current Photo</label>
                <div>
                    <img src="<?= UPLOAD_URL ?>/alumni/<?= h($al['photo']) ?>"
                         alt="" style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid #002147;"
                         onerror="this.style.opacity='.3'">
                </div>
            </div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label fw-medium"><?= $al['photo'] ? 'Replace Photo' : 'Photo' ?></label>
                <input type="file" name="photo" class="form-control" id="photoInput"
                       accept=".jpg,.jpeg,.png,.gif,.webp">
                <?php if ($al['photo']): ?>
                <div class="form-text">Leave blank to keep current photo.</div>
                <?php endif; ?>
                <div id="photoPreviewWrap" class="mt-2" style="display:none;">
                    <img id="photoPreview" src="" alt="New preview"
                         style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid #002147;">
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= (int)$al['sort_order'] ?>" min="0">
                </div>
                <div class="col-md-8 d-flex align-items-end mb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               value="1" <?= $al['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Alumni
                </button>
                <a href="<?= APP_URL ?>/cms/alumni/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
document.getElementById('photoInput').addEventListener('change', function () {
    var wrap    = document.getElementById('photoPreviewWrap');
    var preview = document.getElementById('photoPreview');
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
