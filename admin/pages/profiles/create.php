<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_id = (int)($_GET['page_id'] ?? 0);
if (!$page_id) { flash_set('error', 'Invalid page.'); redirect(APP_URL . '/pages/index.php'); }

$stmt = db()->prepare('SELECT * FROM pages WHERE id = ? AND category = ?');
$stmt->execute([$page_id, 'profile']);
$parent = $stmt->fetch();
if (!$parent) { flash_set('error', 'Profile page not found.'); redirect(APP_URL . '/pages/index.php'); }

$page_title = 'Add Member – ' . $parent['title'];
$errors     = [];
clear_old();

// ── Upload helper ──────────────────────────────────────────────────────────────
function upload_profile_photo(array $file): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $allowed_exts  = ['jpg','jpeg','png','gif','webp'];
    $allowed_mimes = ['image/jpeg','image/png','image/gif','image/webp'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/pages/profiles';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $full_name   = trim($_POST['full_name']   ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $bio         = trim($_POST['bio']         ?? '');
    $email       = trim($_POST['email']       ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $sort_order  = (int)($_POST['sort_order'] ?? 0);

    if ($full_name === '') $errors[] = 'Full name is required.';

    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $result = upload_profile_photo($_FILES['photo']);
        if ($result === false) {
            $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            $photo = $result;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO page_profiles (page_id, full_name, designation, bio, photo, email, phone, is_featured, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $page_id, $full_name, $designation ?: null, $bio ?: null, $photo,
            $email ?: null, $phone ?: null, $is_featured, $sort_order,
        ]);

        flash_set('success', 'Member <strong>' . h($full_name) . '</strong> added.');
        redirect(APP_URL . '/pages/profiles/index.php?page_id=' . $page_id);
    }

    save_old(compact('full_name','designation','bio','email','phone','is_featured','sort_order'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/index.php">Pages</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/profiles/index.php?page_id=<?= $page_id ?>"><?= h($parent['title']) ?> – Members</a></li>
            <li class="breadcrumb-item active">Add Member</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-8">
<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <div class="card mb-4">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-user me-2 text-muted"></i>Member Details</h6>
        </div>
        <div class="card-body p-4">

            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= old('full_name') ?>" required maxlength="150">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium">Designation / Role</label>
                    <input type="text" name="designation" class="form-control"
                           value="<?= old('designation') ?>" maxlength="150"
                           placeholder="e.g. Chairman, Member, Dean">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Bio / Description</label>
                <textarea name="bio" class="form-control" rows="4"
                          placeholder="Short biography or description of this person"><?= old('bio') ?></textarea>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= old('email') ?>" maxlength="191">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= old('phone') ?>" maxlength="30">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Photo</label>
                <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="form-text">Recommended: square image, min 300×300 px. JPG, PNG, GIF, WebP.</div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= old('sort_order','0') ?>" min="0" style="width:100px;">
                    <div class="form-text">Lower number = shown first.</div>
                </div>
                <div class="col-md-6 d-flex align-items-center mt-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured"
                               value="1" <?= old('is_featured') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_featured">
                            Featured (highlighted card, e.g. Chairman)
                        </label>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save Member
        </button>
        <a href="<?= APP_URL ?>/pages/profiles/index.php?page_id=<?= $page_id ?>"
           class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
