<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$section_id = (int)($_GET['section_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM cms_coc_sections WHERE id = ?');
$stmt->execute([$section_id]);
$sec = $stmt->fetch();

if (!$sec) {
    flash_set('error', 'Section not found.');
    redirect(APP_URL . '/cms/code-of-conduct/index.php');
}

$page_title = 'Add Item – ' . $sec['title'];
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $item_text  = trim($_POST['item_text']  ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($item_text === '') $errors[] = 'Item text is required.';

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO cms_coc_items (section_id, item_text, sort_order, is_active) VALUES (?,?,?,?)'
        )->execute([$section_id, $item_text, $sort_order, $is_active]);

        flash_set('success', 'Conduct item added.');
        redirect(APP_URL . '/cms/code-of-conduct/index.php');
    }

    save_old(compact('item_text', 'sort_order'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/code-of-conduct/index.php">Code of Conduct</a></li>
            <li class="breadcrumb-item active">Add Item</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="<?= h($sec['icon']) ?> me-2 text-muted"></i>
            Add Item to <em><?= h($sec['title']) ?></em>
        </h6>
    </div>
    <div class="card-body p-4">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label fw-medium">Conduct Item Text <span class="text-danger">*</span></label>
                <textarea name="item_text" class="form-control" rows="4" required><?= h(old('item_text')) ?></textarea>
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
                    <i class="fas fa-plus me-1"></i> Add Item
                </button>
                <a href="<?= APP_URL ?>/cms/code-of-conduct/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
