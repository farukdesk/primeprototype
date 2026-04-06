<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_once __DIR__ . '/helpers.php';
require_access('cms-glance', 'can_edit');

$id = (int)($_GET['id'] ?? 0);
$row = db()->prepare('SELECT * FROM glance_leaders WHERE id = ?');
$row->execute([$id]);
$row = $row->fetch();
if (!$row) { flash_set('error', 'Leader not found.'); redirect(APP_URL . '/cms/glance/index.php'); }

$page_title = 'Edit Leadership Card';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name       = trim($_POST['name']        ?? '');
    $role       = trim($_POST['role']        ?? '');
    $bio        = trim($_POST['bio']         ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active'])  ? 1 : 0;

    if ($name === '') $errors[] = 'Name is required.';
    if ($role === '') $errors[] = 'Role is required.';

    $photo = $row['photo'];
    if (!empty($_FILES['photo']['name'])) {
        $res = glance_upload_image($_FILES['photo']);
        if ($res === false) $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        else $photo = $res;
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE glance_leaders SET name=?, role=?, bio=?, photo=?, sort_order=?, is_active=?, updated_at=NOW()
             WHERE id=?'
        )->execute([$name, $role, $bio, $photo, $sort_order, $is_active, $id]);
        log_change('cms-glance', 'UPDATE', $id, $name, null, null, null, 'Leader updated.');
        flash_set('success', 'Leader updated.');
        redirect(APP_URL . '/cms/glance/index.php');
    }
} else {
    $name       = $row['name'];
    $role       = $row['role'];
    $bio        = $row['bio'];
    $sort_order = $row['sort_order'];
    $is_active  = $row['is_active'];
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/glance/index.php">PU At a Glance</a></li>
            <li class="breadcrumb-item active">Edit Leader</li>
        </ol>
    </nav>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row"><div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Leadership Card</h6></div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= h($name) ?>" maxlength="200" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium">Role / Title <span class="text-danger">*</span></label>
                    <input type="text" name="role" class="form-control" value="<?= h($role) ?>" maxlength="120" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Bio / Description</label>
                <textarea name="bio" class="form-control" rows="3"><?= h($bio) ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Photo</label>
                <?php if ($row['photo']): ?>
                <div class="mb-2"><img src="<?= h(glance_img_url($row['photo'])) ?>" alt="" style="height:80px;border-radius:50%;object-fit:cover;aspect-ratio:1;"></div>
                <?php endif; ?>
                <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="form-text">Leave blank to keep the current photo.</div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= (int)$sort_order ?>" min="0">
                </div>
                <div class="col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= $is_active ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_active">Active</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save me-1"></i> Update</button>
                <a href="<?= APP_URL ?>/cms/glance/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
