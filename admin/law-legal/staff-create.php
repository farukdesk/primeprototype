<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('law-legal', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Add Legal Staff Member';
$errors = [];

$f = [
    'name'        => '',
    'designation' => '',
    'email'       => '',
    'phone'       => '',
    'bio'         => '',
    'sort_order'  => 0,
    'is_active'   => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $f['name']        = trim($_POST['name']        ?? '');
    $f['designation'] = trim($_POST['designation'] ?? '');
    $f['email']       = trim($_POST['email']       ?? '');
    $f['phone']       = trim($_POST['phone']       ?? '');
    $f['bio']         = trim($_POST['bio']         ?? '');
    $f['sort_order']  = (int)($_POST['sort_order'] ?? 0);
    $f['is_active']   = isset($_POST['is_active']) ? 1 : 0;

    if ($f['name'] === '') $errors[] = 'Name is required.';
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }

    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $res = ll_upload_photo($_FILES['photo']);
        if ($res === false) {
            $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP (max 5 MB).';
        } else {
            $photo = $res;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO ll_staff (name, designation, email, phone, bio, photo, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $f['name'], $f['designation'] ?: null,
            $f['email'] ?: null, $f['phone'] ?: null,
            $f['bio'] ?: null, $photo,
            $f['sort_order'], $f['is_active'],
        ]);
        flash_set('success', 'Staff member <strong>' . h($f['name']) . '</strong> added.');
        redirect(APP_URL . '/law-legal/staff-index.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/law-legal/index.php">Law &amp; Legal Affairs</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/law-legal/staff-index.php">Staff</a></li>
            <li class="breadcrumb-item active">Add</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/law-legal/staff-index.php" class="btn btn-sm btn-light" style="border-radius:8px;">
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
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-muted"></i>Staff Member Details</h6>
        </div>
        <div class="card-body p-4">
            <!-- Photo -->
            <div class="mb-4">
                <label class="form-label fw-medium">Profile Photo</label>
                <div class="d-flex align-items-center gap-3">
                    <div id="ll-staff-placeholder"
                         style="width:80px;height:80px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:2px dashed #cbd5e1;flex-shrink:0;">
                        <i class="fas fa-user" style="color:#94a3b8;font-size:1.6rem;"></i>
                    </div>
                    <div>
                        <input type="file" name="photo" id="ll_staff_photo" class="form-control"
                               accept="image/jpeg,image/png,image/gif,image/webp" style="max-width:300px;">
                        <div class="form-text">JPG, PNG, GIF, WebP. Square, min 300×300px recommended.</div>
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
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" min="0"
                           value="<?= (int)$f['sort_order'] ?>">
                    <div class="form-text">Lower = first.</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Designation</label>
                    <input type="text" name="designation" class="form-control" maxlength="200"
                           value="<?= h($f['designation']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email</label>
                    <input type="email" name="email" class="form-control" maxlength="200"
                           value="<?= h($f['email']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="phone" class="form-control" maxlength="50"
                           value="<?= h($f['phone']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Bio / About</label>
                    <textarea name="bio" class="form-control" rows="4"
                              placeholder="Short biography or professional summary."><?= h($f['bio']) ?></textarea>
                </div>
                <div class="col-12">
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
            <i class="fas fa-save me-1"></i> Save
        </button>
        <a href="<?= APP_URL ?>/law-legal/staff-index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>
</div>
</div>

<script>
document.getElementById('ll_staff_photo')?.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        let img = document.getElementById('ll-staff-preview');
        if (!img) {
            img = document.createElement('img');
            img.id = 'll-staff-preview';
            img.style.cssText = 'width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;flex-shrink:0;';
            const ph = document.getElementById('ll-staff-placeholder');
            if (ph) ph.replaceWith(img);
        }
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
