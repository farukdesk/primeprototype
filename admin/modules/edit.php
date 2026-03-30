<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('modules', 'can_edit');

$id     = (int)($_GET['id'] ?? 0);
$module = null;
$errors = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM modules WHERE id = ?');
    $stmt->execute([$id]);
    $module = $stmt->fetch();
}
if (!$module) {
    flash_set('error', 'Module not found.');
    redirect(APP_URL . '/modules/index.php');
}

clear_old();
$page_title = 'Edit Module';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name      = trim($_POST['name']        ?? '');
    $slug      = strtolower(trim(preg_replace('/[^a-zA-Z0-9\-]/', '-', $_POST['slug'] ?? '')));
    $slug      = trim(preg_replace('/-+/', '-', $slug), '-');
    $desc      = trim($_POST['description'] ?? '');
    $icon      = trim($_POST['icon']        ?? 'fas fa-circle');
    $sort      = (int)($_POST['sort_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Module name is required.';
    if ($slug === '') $errors[] = 'Slug is required.';
    if (!preg_match('/^[a-z0-9\-]+$/', $slug)) $errors[] = 'Slug may only contain lowercase letters, numbers, and hyphens.';

    if (empty($errors)) {
        $dup = db()->prepare('SELECT id FROM modules WHERE slug = ? AND id != ?');
        $dup->execute([$slug, $id]);
        if ($dup->fetch()) $errors[] = 'A module with that slug already exists.';
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE modules SET name=?, slug=?, description=?, icon=?, sort_order=?, is_active=? WHERE id=?'
        )->execute([$name, $slug, $desc, $icon, $sort, $is_active, $id]);

        flash_set('success', "Module <strong>" . h($name) . "</strong> updated.");
        redirect(APP_URL . '/modules/index.php');
    }

    $module = array_merge($module, [
        'name' => $name, 'slug' => $slug, 'description' => $desc,
        'icon' => $icon, 'sort_order' => $sort, 'is_active' => $is_active,
    ]);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/modules/index.php">Modules</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-6">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit: <?= h($module['name']) ?></h6>
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
                <label class="form-label fw-medium">Module Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" value="<?= h($module['name']) ?>" required maxlength="100">
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Slug <span class="text-danger">*</span></label>
                <input type="text" name="slug" class="form-control" value="<?= h($module['slug']) ?>" required maxlength="100">
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Icon Class</label>
                <input type="text" name="icon" class="form-control" value="<?= h($module['icon']) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Description</label>
                <textarea name="description" class="form-control" rows="2"><?= h($module['description']) ?></textarea>
            </div>

            <div class="row g-3 mb-3">
                <div class="col">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= (int)$module['sort_order'] ?>" min="0">
                </div>
                <div class="col d-flex align-items-end mb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               <?= $module['is_active'] ? 'checked' : '' ?> value="1">
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Module
                </button>
                <a href="<?= APP_URL ?>/modules/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
