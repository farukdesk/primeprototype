<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-vc', 'can_edit');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/office-of-vc/former-vc-index.php');
}

$stmt = db()->prepare('SELECT * FROM vc_former_vcs WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$rec = $stmt->fetch();
if (!$rec) {
    flash_set('error', 'Record not found.');
    redirect(APP_URL . '/office-of-vc/former-vc-index.php');
}

$page_title = 'Edit Former Vice Chancellor';
$errors     = [];

function fvc_edit_upload_photo(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/office-of-vc';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

$f = [
    'name'       => $rec['name'],
    'title'      => $rec['title'],
    'tenure'     => $rec['tenure'],
    'bio'        => $rec['bio'] ?? '',
    'sort_order' => (int)$rec['sort_order'],
    'is_active'  => (int)$rec['is_active'],
];
$current_photo = $rec['photo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $f['name']       = trim($_POST['name']       ?? '');
    $f['title']      = trim($_POST['title']      ?? 'Former Vice Chancellor');
    $f['tenure']     = trim($_POST['tenure']     ?? '');
    $f['bio']        = trim($_POST['bio']        ?? '');
    $f['sort_order'] = (int)($_POST['sort_order'] ?? 0);
    $f['is_active']  = isset($_POST['is_active']) ? 1 : 0;

    if ($f['name'] === '') $errors[] = 'Name is required.';

    $photo = $current_photo;
    if (!empty($_FILES['photo']['name'])) {
        $result = fvc_edit_upload_photo($_FILES['photo']);
        if ($result === false) {
            $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            // Delete old photo
            if ($current_photo) {
                $old = UPLOAD_DIR . '/office-of-vc/' . $current_photo;
                if (is_file($old)) @unlink($old);
            }
            $photo = $result;
        }
    }

    // Remove photo if requested
    if (isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
        if ($current_photo) {
            $old = UPLOAD_DIR . '/office-of-vc/' . $current_photo;
            if (is_file($old)) @unlink($old);
        }
        $photo = null;
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE vc_former_vcs
             SET name=?, title=?, tenure=?, photo=?, bio=?, sort_order=?, is_active=?
             WHERE id=?'
        )->execute([
            $f['name'], $f['title'], $f['tenure'],
            $photo, $f['bio'] ?: null,
            $f['sort_order'], $f['is_active'], $id,
        ]);
        flash_set('success', 'Former VC <strong>' . h($f['name']) . '</strong> updated.');
        redirect(APP_URL . '/office-of-vc/former-vc-index.php');
    }

    $current_photo = $photo;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-vc/index.php">Office of VC</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-vc/former-vc-index.php">Former VCs</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/office-of-vc/former-vc-index.php"
       class="btn btn-sm btn-light" style="border-radius:8px;">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger" style="border-radius:10px;">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-8">
<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-user-graduate me-2 text-muted"></i>Former Vice Chancellor Details
            </h6>
        </div>
        <div class="card-body p-4">

            <!-- Photo -->
            <div class="mb-4">
                <label class="form-label fw-medium">Profile Photo</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <?php if ($current_photo): ?>
                    <img src="<?= UPLOAD_URL ?>/office-of-vc/<?= h($current_photo) ?>"
                         id="fvc-photo-preview"
                         style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #e8eaf0;flex-shrink:0;" alt="">
                    <?php else: ?>
                    <div id="fvc-photo-preview-placeholder"
                         style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:2px dashed #cbd5e1;flex-shrink:0;">
                        <i class="fas fa-user-tie" style="color:#94a3b8;font-size:1.6rem;"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <input type="file" name="photo" id="fvc_photo" class="form-control mb-2"
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               style="max-width:280px;">
                        <div class="form-text mb-2">JPG, PNG, GIF, WebP. Recommended: square, min 300×300px.</div>
                        <?php if ($current_photo): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remove_photo"
                                   name="remove_photo" value="1">
                            <label class="form-check-label text-danger" for="remove_photo"
                                   style="font-size:.83rem;">Remove current photo</label>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="200"
                           value="<?= h($f['name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Tenure / Period</label>
                    <input type="text" name="tenure" class="form-control" maxlength="100"
                           placeholder="e.g. 2010–2015"
                           value="<?= h($f['tenure']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Title / Designation</label>
                    <input type="text" name="title" class="form-control" maxlength="200"
                           value="<?= h($f['title']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Bio / About</label>
                    <textarea name="bio" class="form-control" rows="5"
                              placeholder="Short biography or academic background."><?= h($f['bio']) ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" min="0"
                           value="<?= (int)$f['sort_order'] ?>">
                    <div class="form-text">Lower numbers appear first.</div>
                </div>
                <div class="col-md-8 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               value="1" <?= $f['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_active">
                            Active (show on public page)
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save Changes
        </button>
        <a href="<?= APP_URL ?>/office-of-vc/former-vc-index.php"
           class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>
</div>
</div>

<script>
document.getElementById('fvc_photo')?.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        let img = document.getElementById('fvc-photo-preview');
        if (!img) {
            img = document.createElement('img');
            img.id = 'fvc-photo-preview';
            img.style.cssText = 'width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #e8eaf0;flex-shrink:0;';
            const placeholder = document.getElementById('fvc-photo-preview-placeholder');
            if (placeholder) placeholder.replaceWith(img);
        }
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
