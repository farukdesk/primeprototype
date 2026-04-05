<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!cc_is_staff()) { redirect(APP_URL . '/course-curriculum/index.php'); }

// Resolve context from GET / POST
$dept_id    = (int)($_POST['dept_id']    ?? $_GET['dept_id']    ?? 0);
$program_id = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);
$def_sem    = max(1, min(12, (int)($_GET['semester'] ?? 1)));

$page_title = 'Add Course';
$errors     = [];
clear_old();

// Verify program exists and belongs to dept
$program_row = null;
if ($program_id > 0 && $dept_id > 0) {
    $st = db()->prepare(
        "SELECT p.*, d.name AS dept_name
           FROM dept_academic_programs p
           JOIN dept_departments d ON d.id = p.dept_id
          WHERE p.id = ? AND p.dept_id = ?
          LIMIT 1"
    );
    $st->execute([$program_id, $dept_id]);
    $program_row = $st->fetch() ?: null;
}
if (!$program_row) {
    flash_set('danger', 'Invalid department or program selection.');
    redirect(APP_URL . '/course-curriculum/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $semester    = max(1, min(12, (int)($_POST['semester'] ?? 1)));
    $sl_no       = max(1, (int)($_POST['sl_no'] ?? 1));
    $bnqf_code   = trim($_POST['bnqf_code']   ?? '');
    $course_code = trim($_POST['course_code'] ?? '');
    $course_name = trim($_POST['course_name'] ?? '');
    $credit_raw  = trim($_POST['credit']      ?? '');
    $sort_order  = max(0, (int)($_POST['sort_order'] ?? 0));

    if ($course_name === '')           $errors[] = 'Course Name is required.';
    if (mb_strlen($course_name) > 300) $errors[] = 'Course Name must be 300 characters or less.';

    $credit = null;
    if ($credit_raw !== '') {
        if (!is_numeric($credit_raw) || (float)$credit_raw < 0) {
            $errors[] = 'Credit must be a non-negative number.';
        } else {
            $credit = (float)$credit_raw;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            "INSERT INTO course_curriculum
               (program_id, semester, sl_no, bnqf_code, course_code, course_name, credit, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $program_id, $semester, $sl_no,
            $bnqf_code   ?: null,
            $course_code ?: null,
            $course_name,
            $credit,
            $sort_order,
        ]);

        flash_set('success', 'Course <strong>' . h($course_name) . '</strong> added.');
        redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id . '#sem-' . $semester);
    }

    save_old(compact('semester','sl_no','bnqf_code','course_code','course_name','credit_raw','sort_order'));
}

$semester_labels = cc_semester_labels();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>">
                    Course Curriculum
                </a>
            </li>
            <li class="breadcrumb-item active">Add Course</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Context -->
<div class="alert alert-light border mb-4 small py-2 px-3">
    <i class="fas fa-building me-1 text-muted"></i><?= h($program_row['dept_name']) ?>
    &nbsp;→&nbsp;
    <strong><?= h($program_row['program_name']) ?></strong>
</div>

<form method="POST" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="dept_id"    value="<?= $dept_id ?>">
    <input type="hidden" name="program_id" value="<?= $program_id ?>">

    <div class="row g-4">

        <!-- ── Left column ──────────────────────────────────────────────────── -->
        <div class="col-lg-8">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-book me-2 text-muted"></i>Course Details
                    </h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">
                            Course Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="course_name" class="form-control"
                               value="<?= old('course_name') ?>" maxlength="300"
                               placeholder="e.g. Introduction to Computer Science" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Course Code</label>
                            <input type="text" name="course_code" class="form-control"
                                   value="<?= old('course_code') ?>" maxlength="50"
                                   placeholder="e.g. CSE 101">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">BNQF Code</label>
                            <input type="text" name="bnqf_code" class="form-control"
                                   value="<?= old('bnqf_code') ?>" maxlength="50"
                                   placeholder="e.g. BNQ-1234">
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Credit</label>
                            <input type="number" name="credit" class="form-control"
                                   value="<?= old('credit_raw') ?>" step="0.01" min="0"
                                   placeholder="e.g. 3.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">SL No.</label>
                            <input type="number" name="sl_no" class="form-control"
                                   value="<?= old('sl_no', 1) ?>" min="1" max="999">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control"
                                   value="<?= old('sort_order', 0) ?>" min="0">
                            <div class="form-text">Lower = shown first.</div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Right column ─────────────────────────────────────────────────── -->
        <div class="col-lg-4">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-calendar-alt me-2 text-muted"></i>Semester
                    </h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-medium">
                            Semester <span class="text-danger">*</span>
                        </label>
                        <select name="semester" class="form-select" required>
                            <?php foreach ($semester_labels as $n => $lbl): ?>
                            <option value="<?= $n ?>"
                                <?= (int)old('semester', $def_sem) === $n ? 'selected' : '' ?>>
                                <?= h($lbl) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Course
                        </button>
                        <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
