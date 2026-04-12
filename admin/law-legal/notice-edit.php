<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('law-legal', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Edit Legal Notice';
$errors = [];

$id = (int)($_GET['id'] ?? 0);
$row = null;
try {
    $stmt = db()->prepare('SELECT * FROM ll_notices WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
} catch (Throwable $e) {}

if (!$row) {
    flash_set('error', 'Notice not found.');
    redirect(APP_URL . '/law-legal/notice-index.php');
}

$f = [
    'title'       => $row['title'],
    'body'        => $row['body'] ?? '',
    'notice_date' => $row['notice_date'] ?? '',
    'category'    => $row['category'] ?? 'notice',
    'sort_order'  => (int)$row['sort_order'],
    'is_active'   => (int)$row['is_active'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $f['title']       = trim($_POST['title']       ?? '');
    $f['body']        = trim($_POST['body']        ?? '');
    $f['notice_date'] = trim($_POST['notice_date'] ?? '');
    $f['category']    = in_array($_POST['category'] ?? '', ['notice','circular','policy','announcement'])
                        ? $_POST['category'] : 'notice';
    $f['sort_order']  = (int)($_POST['sort_order'] ?? 0);
    $f['is_active']   = isset($_POST['is_active']) ? 1 : 0;

    if ($f['title'] === '') $errors[] = 'Title is required.';

    $date_val = $f['notice_date'] !== '' ? $f['notice_date'] : null;

    if (empty($errors)) {
        db()->prepare(
            'UPDATE ll_notices SET title=?, body=?, notice_date=?, category=?, sort_order=?, is_active=?, updated_at=NOW()
             WHERE id=?'
        )->execute([
            $f['title'], $f['body'] ?: null,
            $date_val, $f['category'],
            $f['sort_order'], $f['is_active'],
            $id,
        ]);
        flash_set('success', 'Notice updated.');
        redirect(APP_URL . '/law-legal/notice-index.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/law-legal/index.php">Law &amp; Legal Affairs</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/law-legal/notice-index.php">Notices</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/law-legal/notice-index.php" class="btn btn-sm btn-light" style="border-radius:8px;">
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
<div class="col-lg-9">
<form method="POST" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-bell me-2 text-muted"></i>Edit Notice</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required maxlength="400"
                           value="<?= h($f['title']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Category</label>
                    <select name="category" class="form-select">
                        <?php foreach (['notice'=>'Notice','circular'=>'Circular','policy'=>'Policy','announcement'=>'Announcement'] as $k=>$lbl): ?>
                        <option value="<?= $k ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Date</label>
                    <input type="date" name="notice_date" class="form-control"
                           value="<?= h($f['notice_date']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" min="0"
                           value="<?= (int)$f['sort_order'] ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Body / Content</label>
                    <textarea name="body" class="form-control" rows="8"><?= h($f['body']) ?></textarea>
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
            <i class="fas fa-save me-1"></i> Update
        </button>
        <a href="<?= APP_URL ?>/law-legal/notice-index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
