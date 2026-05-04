<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if (!co_can_delete()) {
    flash_set('error', 'You do not have permission to delete course offers.');
    redirect(APP_URL . '/course-offer/index.php');
}

$id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$offer = $id > 0 ? co_get_offer($id) : null;
if (!$offer) {
    flash_set('error', 'Course offer not found.');
    redirect(APP_URL . '/course-offer/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // co_offer_subjects and co_offer_subject_teachers are deleted automatically via ON DELETE CASCADE
    db()->prepare("DELETE FROM co_offers WHERE id = ?")->execute([$id]);

    log_change(
        'course-offer',
        'DELETE',
        $id,
        'Offer #' . $id,
        null, null, null,
        'Course offer #' . $id . ' deleted (batch: ' . ($offer['batch_name'] ?? '') . ')'
    );

    flash_set('success', 'Course offer deleted.');
    redirect(APP_URL . '/course-offer/index.php');
}

// GET: confirmation page
$page_title    = 'Delete Course Offer';
$del_subjects  = co_get_subjects_with_teachers($id);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-offer/index.php">Course Offer</a></li>
            <li class="breadcrumb-item active">Delete</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card border-danger" style="border-radius:12px;">
            <div class="card-header bg-danger text-white py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete
                </h6>
            </div>
            <div class="card-body p-4">
                <p>Are you sure you want to delete the following course offer? This action cannot be undone.</p>
                <table class="table table-borderless table-sm mb-3">
                    <tr>
                        <th class="text-muted small pe-3" style="width:130px;">Department</th>
                        <td class="small"><?= h($offer['dept_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted small">Program</th>
                        <td class="small"><?= h($offer['program_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted small">Batch</th>
                        <td class="small"><?= h(co_batch_label($offer)) ?></td>
                    </tr>
                    <?php if ($offer['semester']): ?>
                    <tr>
                        <th class="text-muted small">Semester</th>
                        <td class="small"><?= h($offer['semester']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php if (!empty($del_subjects)): ?>
                <p class="fw-medium mb-2">
                    Subjects in this offer (<?= count($del_subjects) ?>):
                </p>
                <ul class="list-group list-group-flush mb-4" style="font-size:.875rem;">
                    <?php foreach ($del_subjects as $sub): ?>
                    <li class="list-group-item px-0 py-1">
                        <?php if ($sub['course_code']): ?>
                        <span class="badge bg-light text-dark border me-1" style="font-family:monospace;">
                            <?= h($sub['course_code']) ?>
                        </span>
                        <?php endif; ?>
                        <?= h($sub['course_name']) ?>
                        <span class="text-muted small ms-1">(<?= h($sub['dept_name']) ?>)</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger" style="border-radius:10px;">
                            <i class="fas fa-trash me-1"></i> Yes, Delete
                        </button>
                        <a href="<?= APP_URL ?>/course-offer/index.php"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
