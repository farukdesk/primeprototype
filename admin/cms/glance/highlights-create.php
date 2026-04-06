<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_once __DIR__ . '/helpers.php';
require_access('cms-glance', 'can_create');

$page_title = 'Add Campus Highlight';
$errors     = [];
clear_old();

$color_themes = [
    'hc-blue'   => 'Blue',
    'hc-green'  => 'Green',
    'hc-amber'  => 'Amber / Orange',
    'hc-purple' => 'Purple',
    'hc-navy'   => 'Navy',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title       = trim($_POST['title']        ?? '');
    $icon        = trim($_POST['icon']         ?? '');
    $description = trim($_POST['description']  ?? '');
    $color_theme = $_POST['color_theme']       ?? 'hc-blue';
    $tag_label   = trim($_POST['tag_label']    ?? '');
    $sort_order  = (int)($_POST['sort_order']  ?? 0);
    $is_active   = isset($_POST['is_active'])   ? 1 : 0;

    if ($title === '')       $errors[] = 'Title is required.';
    if ($description === '') $errors[] = 'Description is required.';
    if (!array_key_exists($color_theme, $color_themes)) $color_theme = 'hc-blue';

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO glance_highlights (title, icon, description, color_theme, tag_label, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$title, $icon, $description, $color_theme, $tag_label, $sort_order, $is_active]);
        log_change('cms-glance', 'CREATE', (int)db()->lastInsertId(), $title, null, null, null, 'Highlight created.');
        flash_set('success', 'Highlight <strong>' . h($title) . '</strong> added.');
        redirect(APP_URL . '/cms/glance/index.php');
    }
    save_old(compact('title', 'icon', 'description', 'color_theme', 'tag_label', 'sort_order', 'is_active'));
}

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/glance/index.php">PU At a Glance</a></li>
            <li class="breadcrumb-item active">Add Highlight</li>
        </ol>
    </nav>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="row"><div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-building me-2 text-muted"></i>New Campus Highlight</h6></div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" value="<?= h(old('title')) ?>" maxlength="200" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Color Theme</label>
                    <select name="color_theme" class="form-select">
                        <?php foreach ($color_themes as $val => $label): ?>
                        <option value="<?= h($val) ?>" <?= old('color_theme', 'hc-blue') === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Icon Class</label>
                    <input type="text" name="icon" class="form-control" value="<?= h(old('icon', 'fas fa-star')) ?>" maxlength="120" placeholder="fas fa-lightbulb">
                    <div class="form-text">Font Awesome class e.g. <code>fas fa-lightbulb</code></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Tag / Badge Label</label>
                    <input type="text" name="tag_label" class="form-control" value="<?= h(old('tag_label')) ?>" maxlength="100" placeholder="Research & Technology">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                <textarea name="description" class="form-control" rows="5" required><?= h(old('description')) ?></textarea>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= h(old('sort_order', '0')) ?>" min="0">
                </div>
                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= old('is_active', '1') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_active">Active</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-save me-1"></i> Save</button>
                <a href="<?= APP_URL ?>/cms/glance/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
