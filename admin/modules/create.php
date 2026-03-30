<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('modules', 'can_create');

$page_title = 'Create Module';
$errors = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name       = trim($_POST['name']       ?? '');
    $slug       = strtolower(trim(preg_replace('/[^a-zA-Z0-9\-]/', '-', $_POST['slug'] ?? '')));
    $slug       = trim(preg_replace('/-+/', '-', $slug), '-');
    $desc       = trim($_POST['description'] ?? '');
    $icon       = trim($_POST['icon']        ?? 'fas fa-circle');
    $sort       = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Module name is required.';
    if ($slug === '') $errors[] = 'Slug is required.';
    if (!preg_match('/^[a-z0-9\-]+$/', $slug)) $errors[] = 'Slug may only contain lowercase letters, numbers, and hyphens.';

    if (empty($errors)) {
        $dup = db()->prepare('SELECT id FROM modules WHERE slug = ?');
        $dup->execute([$slug]);
        if ($dup->fetch()) $errors[] = 'A module with that slug already exists.';
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO modules (name, slug, description, icon, sort_order, is_active) VALUES (?,?,?,?,?,?)'
        )->execute([$name, $slug, $desc, $icon, $sort, $is_active]);

        flash_set('success', "Module <strong>" . h($name) . "</strong> created.");
        redirect(APP_URL . '/modules/index.php');
    }

    save_old(compact('name', 'slug', 'desc', 'icon', 'sort'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/modules/index.php">Modules</a></li>
            <li class="breadcrumb-item active">Create</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-6">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-cubes me-2 text-muted"></i>New Module</h6>
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
                <input type="text" name="name" class="form-control" value="<?= old('name') ?>"
                       id="modName" required placeholder="e.g. Reports" maxlength="100">
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Slug <span class="text-danger">*</span>
                    <small class="text-muted">(unique identifier, lowercase-hyphen)</small>
                </label>
                <input type="text" name="slug" class="form-control" value="<?= old('slug') ?>"
                       id="modSlug" required placeholder="e.g. reports" maxlength="100">
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Icon Class
                    <small class="text-muted">(Font Awesome class, e.g. <code>fas fa-chart-bar</code>)</small>
                </label>
                <input type="text" name="icon" class="form-control" value="<?= old('icon', 'fas fa-circle') ?>"
                       placeholder="fas fa-circle">
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Description</label>
                <textarea name="description" class="form-control" rows="2"><?= old('desc') ?></textarea>
            </div>

            <div class="row g-3 mb-3">
                <div class="col">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= old('sort', '0') ?>" min="0">
                </div>
                <div class="col d-flex align-items-end mb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Create Module
                </button>
                <a href="<?= APP_URL ?>/modules/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
document.getElementById('modName').addEventListener('input', function() {
    var slug = this.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    document.getElementById('modSlug').value = slug;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
