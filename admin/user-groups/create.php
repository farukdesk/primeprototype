<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('user-groups', 'can_create');

$page_title = 'Create User Group';
$errors = [];
$old    = $_SESSION['old'] ?? [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;
    $is_super    = (is_super_admin() && isset($_POST['is_super'])) ? 1 : 0;

    if ($name === '') {
        $errors[] = 'Group name is required.';
    } elseif (strlen($name) > 100) {
        $errors[] = 'Group name must be 100 characters or fewer.';
    } else {
        $dup = db()->prepare('SELECT id FROM user_groups WHERE name = ?');
        $dup->execute([$name]);
        if ($dup->fetch()) $errors[] = 'A group with that name already exists.';
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO user_groups (name, description, is_active, is_super) VALUES (?,?,?,?)'
        )->execute([$name, $description, $is_active, $is_super]);

        flash_set('success', "User group <strong>" . h($name) . "</strong> created successfully.");
        redirect(APP_URL . '/user-groups/index.php');
    }

    save_old(['name' => $name, 'description' => $description]);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/user-groups/index.php">User Groups</a></li>
            <li class="breadcrumb-item active">Create</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-6">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-layer-group me-2 text-muted"></i>New User Group</h6>
    </div>
    <div class="card-body p-4">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?>
                    <li><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="name" class="form-label fw-medium">Group Name <span class="text-danger">*</span></label>
                <input type="text" id="name" name="name" class="form-control"
                       value="<?= old('name') ?>" maxlength="100" required placeholder="e.g. Content Editor">
            </div>

            <div class="mb-3">
                <label for="description" class="form-label fw-medium">Description</label>
                <textarea id="description" name="description" class="form-control" rows="3"
                          placeholder="Optional description"><?= old('description') ?></textarea>
            </div>

            <div class="mb-3 d-flex gap-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                           checked value="1">
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
                <?php if (is_super_admin()): ?>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="is_super" name="is_super" value="1">
                    <label class="form-check-label" for="is_super">
                        Super Admin Group
                        <small class="text-muted">(unrestricted access)</small>
                    </label>
                </div>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Create Group
                </button>
                <a href="<?= APP_URL ?>/user-groups/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
