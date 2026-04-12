<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('law-legal', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Add Legal Service';
$errors = [];

$f = [
    'title'       => '',
    'description' => '',
    'icon'        => 'fas fa-gavel',
    'sort_order'  => 0,
    'is_active'   => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $f['title']       = trim($_POST['title']       ?? '');
    $f['description'] = trim($_POST['description'] ?? '');
    $f['icon']        = trim($_POST['icon']        ?? 'fas fa-gavel');
    $f['sort_order']  = (int)($_POST['sort_order'] ?? 0);
    $f['is_active']   = isset($_POST['is_active']) ? 1 : 0;

    if ($f['title'] === '') $errors[] = 'Title is required.';
    if ($f['icon']  === '') $f['icon'] = 'fas fa-gavel';

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO ll_services (title, description, icon, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $f['title'], $f['description'] ?: null,
            $f['icon'], $f['sort_order'], $f['is_active'],
        ]);
        flash_set('success', 'Service added.');
        redirect(APP_URL . '/law-legal/service-index.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/law-legal/index.php">Law &amp; Legal Affairs</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/law-legal/service-index.php">Services</a></li>
            <li class="breadcrumb-item active">Add</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/law-legal/service-index.php" class="btn btn-sm btn-light" style="border-radius:8px;">
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
<form method="POST" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-balance-scale me-2 text-muted"></i>Service Details</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required maxlength="300"
                           value="<?= h($f['title']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" min="0"
                           value="<?= (int)$f['sort_order'] ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Font Awesome Icon Class</label>
                    <div class="input-group">
                        <span class="input-group-text" id="icon-preview-wrap">
                            <i id="icon-preview" class="<?= h($f['icon']) ?>"></i>
                        </span>
                        <input type="text" name="icon" id="icon_input" class="form-control" maxlength="100"
                               value="<?= h($f['icon']) ?>"
                               placeholder="e.g. fas fa-gavel">
                    </div>
                    <div class="form-text">Use any Font Awesome 6 class, e.g. <code>fas fa-balance-scale</code>, <code>fas fa-file-contract</code>, <code>fas fa-building</code>.</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Description</label>
                    <textarea name="description" class="form-control" rows="4"
                              placeholder="Brief description of this legal service…"><?= h($f['description']) ?></textarea>
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
        <a href="<?= APP_URL ?>/law-legal/service-index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>
</div>
</div>

<script>
document.getElementById('icon_input')?.addEventListener('input', function () {
    document.getElementById('icon-preview').className = this.value.trim() || 'fas fa-gavel';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
