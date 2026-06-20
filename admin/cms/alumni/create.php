<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Add Alumni';
$errors     = [];
clear_old();

const ALUMNI_EXTS  = ['jpg','jpeg','png','gif','webp'];
const ALUMNI_MIMES = ['image/jpeg','image/png','image/gif','image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name         = trim($_POST['name']         ?? '');
    $designation  = trim($_POST['designation']  ?? '');
    $organization = trim($_POST['organization'] ?? '');
    $sort_order   = (int)($_POST['sort_order']  ?? 0);
    $is_active    = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $errors[] = 'Name is required.';
    }

    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $f = $_FILES['photo'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Photo upload failed (code ' . $f['error'] . ').';
        } else {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ALUMNI_EXTS, true)) {
                $errors[] = 'Photo: unsupported format. Allowed: JPG, PNG, GIF, WebP.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($f['tmp_name']);
                if (!in_array($mime, ALUMNI_MIMES, true)) {
                    $errors[] = 'Photo: MIME type not allowed.';
                } else {
                    $dir = UPLOAD_DIR . '/alumni';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $photo = bin2hex(random_bytes(12)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $photo)) {
                        $errors[] = 'Failed to save photo. Check server permissions.';
                        $photo = null;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO cms_alumni (name, designation, organization, photo, sort_order, is_active)
             VALUES (?,?,?,?,?,?)'
        )->execute([$name, $designation ?: null, $organization ?: null, $photo, $sort_order, $is_active]);

        flash_set('success', 'Alumni added successfully.');
        redirect(APP_URL . '/cms/alumni/index.php');
    }

    save_old(compact('name', 'designation', 'organization', 'sort_order'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/alumni/index.php">Notable Alumni</a></li>
            <li class="breadcrumb-item active">Add Alumni</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-graduate me-2 text-muted"></i>New Alumni</h6>
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
                <input type="text" name="name" class="form-control" value="<?= old('name') ?>"
                       placeholder="e.g. Mohammad Imran Hayat Khan" maxlength="255" required>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Designation</label>
                    <input type="text" name="designation" class="form-control" value="<?= old('designation') ?>"
                           placeholder="e.g. FAVP" maxlength="255">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Organization</label>
                    <input type="text" name="organization" class="form-control" value="<?= old('organization') ?>"
                           placeholder="e.g. Al-Arafa Islami Bank" maxlength="255">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Photo</label>
                <input type="file" name="photo" class="form-control" id="photoInput"
                       accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="form-text">JPG, PNG, GIF, WebP. Recommended 300×300 px (square).</div>
                <div id="photoPreviewWrap" class="mt-2" style="display:none;">
                    <img id="photoPreview" src="" alt="Preview"
                         style="max-height:150px;border-radius:50%;object-fit:cover;width:150px;height:150px;border:3px solid #002147;">
                </div>
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
                    <i class="fas fa-save me-1"></i> Save Alumni
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
