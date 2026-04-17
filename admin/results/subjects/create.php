<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('results', 'can_create');
require_once __DIR__ . '/../helpers.php';

$exam_id = (int)($_POST['exam_id'] ?? $_GET['exam_id'] ?? 0);
if (!$exam_id) { flash_set('error', 'Invalid exam.'); redirect(APP_URL . '/results/index.php'); }
$exam = rm_get_exam($exam_id);

$page_title = 'Add Subject – ' . h($exam['exam_title']);
$errors     = [];
clear_old();

// Courses from curriculum (if program linked) for auto-fill
$curriculum_courses = [];
if ($exam['program_id']) {
    $cc_stmt = db()->prepare(
        'SELECT id, course_code, course_name, credit FROM course_curriculum
         WHERE program_id = ? ORDER BY semester ASC, sort_order ASC, sl_no ASC'
    );
    $cc_stmt->execute([$exam['program_id']]);
    $curriculum_courses = $cc_stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $curriculum_id = (int)($_POST['curriculum_id'] ?? 0);
    $course_code   = trim($_POST['course_code']   ?? '');
    $course_title  = trim($_POST['course_title']  ?? '');
    $credits       = trim($_POST['credits']        ?? '');
    $sort_order    = (int)($_POST['sort_order']    ?? 0);

    if ($course_title === '') $errors[] = 'Course title is required.';

    // Validate curriculum link
    if ($curriculum_id > 0) {
        $cv = db()->prepare('SELECT id FROM course_curriculum WHERE id = ? LIMIT 1');
        $cv->execute([$curriculum_id]);
        if (!$cv->fetch()) $curriculum_id = 0;
    }

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
            'INSERT INTO result_subjects (exam_id, curriculum_id, course_code, course_title, credits, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $exam_id,
            $curriculum_id ?: null,
            $course_code ?: null,
            $course_title,
            $credits_val,
            $sort_order,
        ]);
        flash_set('success', 'Subject <strong>' . h($course_title) . '</strong> added.');
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
            <li class="breadcrumb-item active">Add Subject</li>
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
                <h6 class="mb-0 fw-semibold"><i class="fas fa-book me-2 text-muted"></i>Subject Details</h6>
            </div>
            <div class="card-body p-4">

                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="exam_id" value="<?= $exam_id ?>">

                    <?php if (!empty($curriculum_courses)): ?>
                    <div class="mb-3">
                        <label class="form-label fw-medium">
                            Auto-fill from Curriculum
                            <small class="text-muted fw-normal">— linked to <?= h($exam['program_name'] ?? '') ?></small>
                        </label>
                        <select id="cc_picker" class="form-select">
                            <option value="">— Pick a course to auto-fill —</option>
                            <?php foreach ($curriculum_courses as $cc): ?>
                            <option value="<?= $cc['id'] ?>"
                                    data-code="<?= h($cc['course_code'] ?? '') ?>"
                                    data-name="<?= h($cc['course_name']) ?>"
                                    data-credit="<?= h($cc['credit'] ?? '') ?>">
                                <?= h(($cc['course_code'] ? $cc['course_code'] . ': ' : '') . $cc['course_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Selecting a course fills the fields below automatically.</div>
                    </div>
                    <hr>
                    <?php endif; ?>

                    <input type="hidden" name="curriculum_id" id="inp_curriculum_id" value="<?= (int)old('curriculum_id') ?>">

                    <div class="mb-3">
                        <label class="form-label fw-medium">Course Title <span class="text-danger">*</span></label>
                        <input type="text" name="course_title" id="inp_course_title"
                               class="form-control" value="<?= old('course_title') ?>"
                               maxlength="300" required placeholder="e.g. English Reading Skills and Public Speaking">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Course Code</label>
                            <input type="text" name="course_code" id="inp_course_code"
                                   class="form-control" value="<?= old('course_code') ?>"
                                   maxlength="50" placeholder="e.g. BEL-111">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Credits</label>
                            <input type="number" name="credits" id="inp_credits"
                                   class="form-control" value="<?= old('credits') ?>"
                                   step="0.01" min="0" placeholder="e.g. 3.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control"
                                   value="<?= old('sort_order', 0) ?>" min="0">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Subject
                        </button>
                        <a href="<?= APP_URL ?>/results/view.php?id=<?= $exam_id ?>&tab=subjects"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>

                </form>

            </div>
        </div>
    </div>
</div>

<?php if (!empty($curriculum_courses)): ?>
<script>
document.getElementById('cc_picker').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    document.getElementById('inp_curriculum_id').value  = this.value;
    document.getElementById('inp_course_code').value    = opt.dataset.code  || '';
    document.getElementById('inp_course_title').value   = opt.dataset.name  || '';
    document.getElementById('inp_credits').value        = opt.dataset.credit || '';
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
