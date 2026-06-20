<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_once __DIR__ . '/helpers.php';
require_access('cms-glance', 'can_edit');

$id = (int)($_GET['id'] ?? 0);
$row = db()->prepare('SELECT * FROM glance_milestones WHERE id = ?');
$row->execute([$id]);
$row = $row->fetch();
if (!$row) { flash_set('error', 'Milestone not found.'); redirect(APP_URL . '/cms/glance/index.php'); }

$page_title = 'Edit Milestone';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $year_label  = trim($_POST['year_label']  ?? '');
    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $sort_order  = (int)($_POST['sort_order'] ?? 0);
    $is_active   = isset($_POST['is_active'])  ? 1 : 0;

    if ($year_label === '') $errors[] = 'Year/Label is required.';
    if ($title === '')      $errors[] = 'Title is required.';
    if ($description === '') $errors[] = 'Description is required.';

    if (empty($errors)) {
        db()->prepare(
            'UPDATE glance_milestones
             SET year_label=?, title=?, description=?, sort_order=?, is_active=?, updated_at=NOW()
             WHERE id=?'
        )->execute([$year_label, $title, $description, $sort_order, $is_active, $id]);
        log_change('cms-glance', 'UPDATE', $id, $title, null, null, null, 'Milestone updated.');
        flash_set('success', 'Milestone updated.');
        redirect(APP_URL . '/cms/glance/index.php');
    }
} else {
    $year_label  = $row['year_label'];
    $title       = $row['title'];
    $description = $row['description'];
    $sort_order  = $row['sort_order'];
    $is_active   = $row['is_active'];
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/glance/index.php">PU At a Glance</a></li>
            <li class="breadcrumb-item active">Edit Milestone</li>
        </ol>
    </nav>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row"><div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Milestone</h6></div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Year / Label <span class="text-danger">*</span></label>
                    <input type="text" name="year_label" class="form-control" value="<?= h($year_label) ?>" maxlength="60" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" value="<?= h($title) ?>" maxlength="200" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                <textarea name="description" class="form-control" rows="4" required><?= h($description) ?></textarea>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= (int)$sort_order ?>" min="0">
                </div>
                <div class="col-md-4 d-flex align-items-end pb-1">
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
