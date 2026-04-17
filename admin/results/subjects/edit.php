<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('results', 'can_edit');
require_once __DIR__ . '/../helpers.php';

$id      = (int)($_GET['id']      ?? 0);
$exam_id = (int)($_GET['exam_id'] ?? 0);

$subj_stmt = db()->prepare('SELECT * FROM result_subjects WHERE id = ? AND exam_id = ?');
$subj_stmt->execute([$id, $exam_id]);
$subject = $subj_stmt->fetch();
if (!$subject) {
    flash_set('error', 'Subject not found.');
    redirect(APP_URL . '/results/index.php');
}
$exam = rm_get_exam($exam_id);
$page_title = 'Edit Subject';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $curriculum_id = (int)($_POST['curriculum_id'] ?? 0);
    $course_code   = trim($_POST['course_code']   ?? '');
    $course_title  = trim($_POST['course_title']  ?? '');
    $credits       = trim($_POST['credits']        ?? '');
    $sort_order    = (int)($_POST['sort_order']    ?? 0);

    if ($course_title === '') $errors[] = 'Course title is required.';

    $credits_val = null;
    if ($credits !== '') {
        if (!is_numeric($credits) || (float)$credits < 0) {
            $errors[] = 'Credits must be a valid non-negative number.';
        } else {
            $credits_val = (float)$credits;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE result_subjects
             SET curriculum_id=?, course_code=?, course_title=?, credits=?, sort_order=?
             WHERE id=?'
        )->execute([
            $curriculum_id ?: null,
            $course_code   ?: null,
            $course_title,
            $credits_val,
            $sort_order,
            $id,
        ]);
        flash_set('success', 'Subject updated.');
        redirect(APP_URL . '/results/view.php?id=' . $exam_id . '&tab=subjects');
    }

    save_old(compact('curriculum_id','course_code','course_title','credits','sort_order'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/results/view.php?id=<?= $exam_id ?>&tab=subjects">
                    <?= h($exam['exam_title']) ?>
                </a>
            </li>
            <li class="breadcrumb-item active">Edit Subject</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-book me-2 text-muted"></i>Edit Subject</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="curriculum_id" value="<?= (int)($subject['curriculum_id'] ?? 0) ?>">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Course Title <span class="text-danger">*</span></label>
                        <input type="text" name="course_title" class="form-control"
                               value="<?= old('course_title', $subject['course_title']) ?>"
                               maxlength="300" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Course Code</label>
                            <input type="text" name="course_code" class="form-control"
                                   value="<?= old('course_code', $subject['course_code'] ?? '') ?>"
                                   maxlength="50">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Credits</label>
                            <input type="number" name="credits" class="form-control"
                                   value="<?= old('credits', $subject['credits'] ?? '') ?>"
                                   step="0.01" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control"
                                   value="<?= old('sort_order', $subject['sort_order']) ?>" min="0">
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save
                        </button>
                        <a href="<?= APP_URL ?>/results/view.php?id=<?= $exam_id ?>&tab=subjects"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
