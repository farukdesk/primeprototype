<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';
if (!cc_is_staff()) { redirect(APP_URL . '/course-curriculum/index.php'); }

$id         = (int)($_GET['id']         ?? 0);
$dept_id    = (int)($_POST['dept_id']    ?? $_GET['dept_id']    ?? 0);
$program_id = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);

$page_title = 'Edit Subject';
$errors     = [];
clear_old();

// Fetch the existing course row
$course = null;
if ($id > 0) {
    $st = db()->prepare("SELECT * FROM course_curriculum WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $course = $st->fetch() ?: null;
}
if (!$course) {
    flash_set('danger', 'Subject not found.');
    redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id);
}

// Resolve program context from course row if not supplied
if ($program_id <= 0) $program_id = (int)$course['program_id'];
$program_row = null;
if ($program_id > 0) {
    $query = $dept_id > 0
        ? "SELECT p.*, d.name AS dept_name
             FROM dept_academic_programs p
             JOIN dept_departments d ON d.id = p.dept_id
            WHERE p.id = ? AND p.dept_id = ? LIMIT 1"
        : "SELECT p.*, d.name AS dept_name
             FROM dept_academic_programs p
             JOIN dept_departments d ON d.id = p.dept_id
            WHERE p.id = ? LIMIT 1";
    $args = $dept_id > 0 ? [$program_id, $dept_id] : [$program_id];
    $st2 = db()->prepare($query);
    $st2->execute($args);
    $program_row = $st2->fetch() ?: null;
    if ($program_row && $dept_id <= 0) {
        $dept_id = (int)$program_row['dept_id'];
    }
}
if (!$program_row) {
    flash_set('danger', 'Program not found.');
    redirect(APP_URL . '/course-curriculum/index.php');
}

// Load faculty for this department
$dept_faculty = cc_get_dept_faculty($dept_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $semester              = max(1, min(12, (int)($_POST['semester'] ?? 1)));
    $sl_no                 = max(1, (int)($_POST['sl_no'] ?? 1));
    $bnqf_code             = trim($_POST['bnqf_code']            ?? '');
    $course_code           = trim($_POST['course_code']          ?? '');
    $course_name           = trim($_POST['course_name']          ?? '');
    $credit_raw            = trim($_POST['credit']               ?? '');
    $sort_order            = max(0, (int)($_POST['sort_order']   ?? 0));
    $assigned_faculty_id   = (int)($_POST['assigned_faculty_id'] ?? 0) ?: null;

    if ($course_name === '')            $errors[] = 'Subject Title is required.';
    if (mb_strlen($course_name) > 300) $errors[] = 'Subject Title must be 300 characters or less.';

    $credit = null;
    if ($credit_raw !== '') {
        if (!is_numeric($credit_raw) || (float)$credit_raw < 0) {
            $errors[] = 'Credit must be a non-negative number.';
        } else {
            $credit = (float)$credit_raw;
        }
    }

    // Validate assigned_faculty_id belongs to this dept
    if ($assigned_faculty_id !== null) {
        $fv = db()->prepare(
            "SELECT id FROM dept_faculty WHERE id = ? AND dept_id = ? LIMIT 1"
        );
        $fv->execute([$assigned_faculty_id, $dept_id]);
        if (!$fv->fetch()) {
            $assigned_faculty_id = null;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            "UPDATE course_curriculum
                SET semester=?, sl_no=?, bnqf_code=?, course_code=?, course_name=?,
                    credit=?, assigned_faculty_id=?, sort_order=?
              WHERE id=?"
        )->execute([
            $semester, $sl_no,
            $bnqf_code   ?: null,
            $course_code ?: null,
            $course_name,
            $credit,
            $assigned_faculty_id,
            $sort_order,
            $id,
        ]);

        log_change(
            'course-curriculum',
            'UPDATE',
            $id,
            $course_name,
            null, null, null,
            'Subject "' . $course_name . '" updated in program #' . $program_id
        );

        flash_set('success', 'Subject <strong>' . h($course_name) . '</strong> updated.');
        redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id . '#sem-' . $semester);
    }

    save_old(compact('semester','sl_no','bnqf_code','course_code','course_name','credit_raw','sort_order','assigned_faculty_id'));
} else {
    // Pre-fill old() from existing row
    save_old([
        'semester'              => $course['semester'],
        'sl_no'                 => $course['sl_no'],
        'bnqf_code'             => $course['bnqf_code']           ?? '',
        'course_code'           => $course['course_code']         ?? '',
        'course_name'           => $course['course_name'],
        'credit_raw'            => $course['credit'] !== null ? rtrim(rtrim(number_format((float)$course['credit'], 2), '0'), '.') : '',
        'sort_order'            => $course['sort_order'],
        'assigned_faculty_id'   => $course['assigned_faculty_id'] ?? 0,
    ]);
}

$semester_labels = cc_semester_labels();

require_once __DIR__ . '/../includes/header.php';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">';
echo '<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>';
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
            <li class="breadcrumb-item active">Edit Subject</li>
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
    &nbsp;→&nbsp;
    <?= h(cc_semester_label((int)old('semester', $course['semester']))) ?>
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
                        <i class="fas fa-book me-2 text-muted"></i>Subject Details
                    </h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">
                            Subject Title <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="course_name" class="form-control"
                               value="<?= old('course_name') ?>" maxlength="300"
                               placeholder="e.g. Introduction to Computer Science" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Subject Code</label>
                            <input type="text" name="course_code" class="form-control"
                                   value="<?= old('course_code') ?>" maxlength="50"
                                   placeholder="e.g. CSE 101">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Credit</label>
                            <input type="number" name="credit" class="form-control"
                                   value="<?= old('credit_raw') ?>" step="0.01" min="0"
                                   placeholder="e.g. 3.00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Course Teacher</label>
                        <select name="assigned_faculty_id" id="faculty_select" class="form-select">
                            <option value="">— Not Assigned —</option>
                            <?php foreach ($dept_faculty as $f): ?>
                            <option value="<?= $f['id'] ?>"
                                <?= (int)old('assigned_faculty_id', 0) == $f['id'] ? 'selected' : '' ?>>
                                <?= h($f['name']) ?><?= $f['designation'] ? ' — ' . h($f['designation']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Type to search by name or designation.</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">BNQF Code</label>
                            <input type="text" name="bnqf_code" class="form-control"
                                   value="<?= old('bnqf_code') ?>" maxlength="50"
                                   placeholder="e.g. BNQ-1234">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">SL No.</label>
                            <input type="number" name="sl_no" class="form-control"
                                   value="<?= old('sl_no', 1) ?>" min="1" max="999">
                        </div>
                        <div class="col-md-3">
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
                                <?= (int)old('semester', $course['semester']) === $n ? 'selected' : '' ?>>
                                <?= h($lbl) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Update Subject
                        </button>
                        <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<script>
new TomSelect('#faculty_select', {
    placeholder: '— Not Assigned —',
    allowEmptyOption: true,
    sortField: 'text',
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
