<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Add Feature Card';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $icon        = trim($_POST['icon']        ?? '');
    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $sort_order  = (int)($_POST['sort_order'] ?? 0);
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '') {
        $errors[] = 'Title is required.';
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO cms_features (icon, title, description, sort_order, is_active)
             VALUES (?,?,?,?,?)'
        )->execute([$icon ?: 'fas fa-star', $title, $description ?: null, $sort_order, $is_active]);

        flash_set('success', 'Feature card <strong>' . h($title) . '</strong> added.');
        redirect(APP_URL . '/cms/why-choose-us/index.php');
    }

    save_old(compact('icon', 'title', 'description', 'sort_order'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/why-choose-us/index.php">Why Choose Us</a></li>
            <li class="breadcrumb-item active">Add Feature Card</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-star me-2 text-muted"></i>New Feature Card</h6>
    </div>
    <div class="card-body p-4">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label fw-medium">Icon</label>
                <div class="input-group">
                    <span class="input-group-text" id="iconPreview" style="min-width:42px;font-size:1.1rem;">
                        <i id="iconPreviewEl" class="<?= h(old('icon', 'fas fa-star')) ?>"></i>
                    </span>
                    <input type="text" name="icon" id="iconInput" class="form-control"
                           value="<?= h(old('icon', 'fas fa-graduation-cap')) ?>"
                           placeholder="fas fa-graduation-cap" maxlength="100">
                </div>
                <div class="form-text">Enter a FontAwesome class name. The icon preview updates as you type.</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" value="<?= h(old('title')) ?>"
                       maxlength="255" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Description</label>
                <textarea name="description" class="form-control" rows="3"><?= h(old('description')) ?></textarea>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= h(old('sort_order', '0')) ?>" min="0">
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
                    <i class="fas fa-save me-1"></i> Save Card
                </button>
                <a href="<?= APP_URL ?>/cms/why-choose-us/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
(function () {
    var input   = document.getElementById('iconInput');
    var preview = document.getElementById('iconPreviewEl');
    function updatePreview() {
        var val = input.value.trim();
        preview.className = val || 'fas fa-star';
    }
    input.addEventListener('input', updatePreview);
    updatePreview();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
