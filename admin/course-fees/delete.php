<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees', 'can_delete');

$id   = (int)($_GET['id'] ?? 0);
$prog = cf_get_program($id);
if (!$prog) { flash_set('error', 'Program not found.'); redirect(APP_URL . '/course-fees/index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    db()->prepare('DELETE FROM cf_programs WHERE id=?')->execute([$id]);
    log_change('course-fees', 'DELETE', $id, $prog['program_name'], null, null, null, 'Program deleted.');

    flash_set('success', '"' . $prog['program_name'] . '" was deleted.');
    redirect(APP_URL . '/course-fees/index.php');
}

$page_title = 'Delete Program – ' . $prog['program_name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 text-danger"><i class="fas fa-trash me-2"></i>Delete Program</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-fees/index.php">Course Fees</a></li>
            <li class="breadcrumb-item active">Delete</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/course-fees/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Cancel
    </a>
</div>

<div class="card border-danger border-0 shadow-sm" style="max-width:600px;margin:0 auto;">
    <div class="card-body p-4 text-center">
        <div class="text-danger mb-3" style="font-size:3rem;"><i class="fas fa-exclamation-triangle"></i></div>
        <h4 class="fw-bold mb-2">Are you sure?</h4>
        <p class="text-muted mb-1">You are about to permanently delete:</p>
        <p class="fw-semibold fs-5 mb-1"><?= h($prog['program_name']) ?></p>
        <p class="text-muted small mb-4">
            <?= cf_type_badge($prog) ?>
            &nbsp; Slug: <code><?= h($prog['program_slug']) ?></code>
        </p>
        <div class="alert alert-warning text-start small mb-4">
            <i class="fas fa-exclamation-circle me-1"></i>
            This will permanently delete the program and all its admission requirements.
            This action <strong>cannot be undone</strong>.
        </div>
        <form method="post" class="d-flex justify-content-center gap-3">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger px-4">
                <i class="fas fa-trash me-1"></i> Yes, Delete
            </button>
            <a href="<?= APP_URL ?>/course-fees/view.php?id=<?= $id ?>" class="btn btn-outline-secondary px-4">
                Cancel
            </a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
