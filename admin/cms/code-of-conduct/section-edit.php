<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM cms_coc_sections WHERE id = ?');
$stmt->execute([$id]);
$sec = $stmt->fetch();

if (!$sec) {
    flash_set('error', 'Section not found.');
    redirect(APP_URL . '/cms/code-of-conduct/index.php');
}

$page_title = 'Edit Section – ' . $sec['title'];
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title      = trim($_POST['title']      ?? '');
    $subtitle   = trim($_POST['subtitle']   ?? '');
    $intro_text = trim($_POST['intro_text'] ?? '');
    $icon       = trim($_POST['icon']       ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '') $errors[] = 'Title is required.';

    if (empty($errors)) {
        db()->prepare(
            'UPDATE cms_coc_sections
             SET title=?, subtitle=?, intro_text=?, icon=?, sort_order=?, is_active=?
             WHERE id=?'
        )->execute([$title, $subtitle ?: null, $intro_text ?: null, $icon ?: 'fas fa-book', $sort_order, $is_active, $id]);

        flash_set('success', 'Section <strong>' . h($title) . '</strong> updated.');
        redirect(APP_URL . '/cms/code-of-conduct/index.php');
    }

    $sec = array_merge($sec, compact('title', 'subtitle', 'intro_text', 'icon', 'sort_order', 'is_active'));
    save_old(compact('title', 'subtitle', 'intro_text', 'icon', 'sort_order'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/code-of-conduct/index.php">Code of Conduct</a></li>
            <li class="breadcrumb-item active">Edit Section</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Section</h6>
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
                <label class="form-label fw-medium">Icon</label>
                <div class="input-group">
                    <span class="input-group-text" style="min-width:42px;font-size:1.1rem;">
                        <i id="iconPreviewEl" class="<?= h($sec['icon'] ?: 'fas fa-book') ?>"></i>
                    </span>
                    <input type="text" name="icon" id="iconInput" class="form-control"
                           value="<?= h(old('icon', $sec['icon'])) ?>"
                           placeholder="fas fa-book" maxlength="100">
                </div>
                <div class="form-text">Enter a FontAwesome class name.</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control"
                       value="<?= h(old('title', $sec['title'])) ?>" maxlength="255" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Subtitle</label>
                <input type="text" name="subtitle" class="form-control"
                       value="<?= h(old('subtitle', $sec['subtitle'])) ?>" maxlength="255">
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Introductory Text</label>
                <textarea name="intro_text" class="form-control" rows="5"><?= h(old('intro_text', $sec['intro_text'])) ?></textarea>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= h(old('sort_order', $sec['sort_order'])) ?>" min="0">
                </div>
                <div class="col-md-8 d-flex align-items-end mb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= $sec['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Changes
                </button>
                <a href="<?= APP_URL ?>/cms/code-of-conduct/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
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
        preview.className = val || 'fas fa-book';
    }
    input.addEventListener('input', updatePreview);
    updatePreview();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
