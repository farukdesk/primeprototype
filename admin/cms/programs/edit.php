<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$id      = (int)($_GET['id'] ?? 0);
$program = null;
$errors  = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM cms_programs WHERE id = ?');
    $stmt->execute([$id]);
    $program = $stmt->fetch();
}
if (!$program) {
    flash_set('error', 'Program not found.');
    redirect(APP_URL . '/cms/programs/index.php');
}

clear_old();
$page_title = 'Edit Program';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $link_url    = trim($_POST['link_url']    ?? '');
    $link_text   = trim($_POST['link_text']   ?? 'Read More');
    $sort_order  = (int)($_POST['sort_order'] ?? 0);
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '') {
        $errors[] = 'Title is required.';
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE cms_programs
             SET title=?, description=?, link_url=?, link_text=?, sort_order=?, is_active=?
             WHERE id=?'
        )->execute([$title, $description ?: null, $link_url ?: null, $link_text ?: 'Read More', $sort_order, $is_active, $id]);

        flash_set('success', 'Program updated successfully.');
        redirect(APP_URL . '/cms/programs/index.php');
    }

    $program = array_merge($program, compact('title', 'description', 'link_url', 'link_text', 'sort_order', 'is_active'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cms/programs/index.php">Programs</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Program</h6>
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
                <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control"
                       value="<?= h($program['title']) ?>" maxlength="255" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Description</label>
                <textarea name="description" class="form-control" rows="4"><?= h($program['description'] ?? '') ?></textarea>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label fw-medium">Link URL</label>
                    <input type="text" name="link_url" class="form-control"
                           value="<?= h($program['link_url'] ?? '') ?>" maxlength="500">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Link Text</label>
                    <input type="text" name="link_text" class="form-control"
                           value="<?= h($program['link_text'] ?? 'Read More') ?>" maxlength="150">
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= (int)$program['sort_order'] ?>" min="0">
                </div>
                <div class="col-md-8 d-flex align-items-end mb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               value="1" <?= $program['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Program
                </button>
                <a href="<?= APP_URL ?>/cms/programs/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
