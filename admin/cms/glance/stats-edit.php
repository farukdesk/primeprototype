<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_once __DIR__ . '/helpers.php';
require_access('cms-glance', 'can_edit');

$id = (int)($_GET['id'] ?? 0);
$stat = db()->prepare('SELECT * FROM glance_stats WHERE id = ?');
$stat->execute([$id]);
$stat = $stat->fetch();
if (!$stat) { flash_set('error', 'Stat not found.'); redirect(APP_URL . '/cms/glance/index.php'); }

$page_title = 'Edit Stat';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $icon       = trim($_POST['icon']        ?? '');
    $value      = trim($_POST['value']       ?? '');
    $label      = trim($_POST['label']       ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active'])  ? 1 : 0;

    if ($value === '') $errors[] = 'Value is required.';
    if ($label === '') $errors[] = 'Label is required.';

    if (empty($errors)) {
        db()->prepare(
            'UPDATE glance_stats SET icon=?, value=?, label=?, sort_order=?, is_active=?, updated_at=NOW()
             WHERE id=?'
        )->execute([$icon, $value, $label, $sort_order, $is_active, $id]);
        log_change('cms-glance', 'UPDATE', $id, $label, null, null, null, 'Stat updated.');
        flash_set('success', 'Stat updated.');
        redirect(APP_URL . '/cms/glance/index.php');
    }
} else {
    $icon       = $stat['icon'];
    $value      = $stat['value'];
    $label      = $stat['label'];
    $sort_order = $stat['sort_order'];
    $is_active  = $stat['is_active'];
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/glance/index.php">PU At a Glance</a></li>
            <li class="breadcrumb-item active">Edit Stat</li>
        </ol>
    </nav>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row"><div class="col-lg-6">
<div class="card">
    <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Quick Stat</h6></div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-medium">Font Awesome Icon Class</label>
                <input type="text" name="icon" class="form-control" value="<?= h($icon) ?>" maxlength="120">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-5">
                    <label class="form-label fw-medium">Display Value <span class="text-danger">*</span></label>
                    <input type="text" name="value" class="form-control" value="<?= h($value) ?>" maxlength="60" required>
                </div>
                <div class="col-md-7">
                    <label class="form-label fw-medium">Label <span class="text-danger">*</span></label>
                    <input type="text" name="label" class="form-control" value="<?= h($label) ?>" maxlength="120" required>
                </div>
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
