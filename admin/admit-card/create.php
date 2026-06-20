<?php
/**
 * Admit Card – Create
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'New Admit Card';
$db = db();

// Dropdown data
$depts = $db->query("SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$batches = $db->query("SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll();

// Predefined semester options
$year = (int)date('Y');
$semester_opts = [];
foreach ([$year - 1, $year, $year + 1] as $y) {
    $semester_opts[] = "Spring $y";
    $semester_opts[] = "Summer $y";
    $semester_opts[] = "Fall $y";
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $exam_name   = trim($_POST['exam_name']   ?? '');
    $semester    = trim($_POST['semester']    ?? '');
    $dept_id     = (int)($_POST['dept_id']    ?? 0);
    $program_id  = (int)($_POST['program_id'] ?? 0);
    $batch_id    = (int)($_POST['batch_id']   ?? 0) ?: null;
    $batch_label = trim($_POST['batch_label'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    // Courses
    $course_codes   = $_POST['course_code']   ?? [];
    $course_titles  = $_POST['course_title']  ?? [];
    $course_dates   = $_POST['exam_date']     ?? [];
    $course_times   = $_POST['time_slot']     ?? [];
    $course_sections = $_POST['section']      ?? [];

    if ($exam_name === '') $errors[] = 'Exam name is required.';
    if ($semester === '')  $errors[] = 'Semester is required.';
    if ($dept_id <= 0)     $errors[] = 'Please select a department.';
    if ($program_id <= 0)  $errors[] = 'Please select a program.';

    if (empty($errors)) {
        $db->prepare(
            'INSERT INTO ac_admit_cards (exam_name, semester, dept_id, program_id, batch_id, batch_label, is_active, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$exam_name, $semester, $dept_id, $program_id, $batch_id, $batch_label ?: null, $is_active, auth_user()['id']]);
        $card_id = (int)$db->lastInsertId();

        // Insert courses
        foreach ($course_codes as $i => $code) {
            $code  = trim($code);
            $title = trim($course_titles[$i]  ?? '');
            if ($code === '' && $title === '') continue;
            $date  = trim($course_dates[$i]   ?? '') ?: null;
            $time  = trim($course_times[$i]   ?? '') ?: null;
            $sect  = trim($course_sections[$i] ?? '') ?: null;
            $db->prepare(
                'INSERT INTO ac_admit_card_courses (admit_card_id, course_code, course_title, exam_date, time_slot, section, sort_order)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$card_id, $code, $title, $date, $time, $sect, $i]);
        }

        flash_set('success', 'Admit card created successfully.');
        redirect(APP_URL . '/admit-card/view.php?id=' . $card_id);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-plus me-2 text-primary"></i>New Admit Card</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admit-card/index.php">Admit Cards</a></li>
            <li class="breadcrumb-item active">New</li>
        </ol></nav>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<form method="post">
<?= csrf_field() ?>
<div class="row g-4">
    <!-- Left: Card Details -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-3 px-4 fw-semibold">
                <i class="fas fa-id-card me-2 text-primary"></i>Admit Card Details
            </div>
            <div class="card-body px-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Exam Name <span class="text-danger">*</span></label>
                    <input type="text" name="exam_name" class="form-control"
                           value="<?= old('exam_name') ?>" placeholder="e.g. Mid Term-1 Exam" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Semester <span class="text-danger">*</span></label>
                    <select name="semester" class="form-select" required>
                        <option value="">— Select Semester —</option>
                        <?php foreach ($semester_opts as $s): ?>
                        <option value="<?= h($s) ?>" <?= old('semester') === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                        <select name="dept_id" id="dept_id" class="form-select" required>
                            <option value="">— Select Dept —</option>
                            <?php foreach ($depts as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= (int)old('dept_id') === (int)$d['id'] ? 'selected' : '' ?>>
                                <?= h($d['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Program <span class="text-danger">*</span></label>
                        <select name="program_id" id="program_id" class="form-select" required>
                            <option value="">— Select Program —</option>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Batch (optional)</label>
                        <select name="batch_id" class="form-select">
                            <option value="">— All Batches —</option>
                            <?php foreach ($batches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= (int)old('batch_id') === (int)$b['id'] ? 'selected' : '' ?>>
                                <?= h($b['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Batch Label (for card display)</label>
                        <input type="text" name="batch_label" class="form-control"
                               value="<?= old('batch_label') ?>" placeholder="e.g. 12/66">
                    </div>
                </div>
                <div class="mt-3 form-check">
                    <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1"
                           <?= old('is_active', '1') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">Active (visible to students)</label>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Actions -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header py-3 px-4 fw-semibold">
                <i class="fas fa-info-circle me-2 text-muted"></i>Note
            </div>
            <div class="card-body px-4">
                <p class="text-muted small mb-0">
                    Students whose department and program match this admit card will be able to view and download it,
                    provided their total outstanding dues are <strong>500 BDT or less</strong>.
                    You can grant individual exceptions on the card's detail page.
                </p>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-fill">
                <i class="fas fa-save me-1"></i> Save Admit Card
            </button>
            <a href="<?= APP_URL ?>/admit-card/index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</div>

<!-- Courses Section -->
<div class="card mt-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <span class="fw-semibold"><i class="fas fa-book me-2 text-success"></i>Courses</span>
        <button type="button" class="btn btn-sm btn-outline-success" id="addCourseRow">
            <i class="fas fa-plus me-1"></i> Add Course
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="coursesTable">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Course Code</th>
                        <th>Course Title</th>
                        <th>Exam Date</th>
                        <th>Time Slot</th>
                        <th>Section</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody id="courseBody">
                    <tr class="course-row">
                        <td class="px-3"><input type="text" name="course_code[]" class="form-control form-control-sm" placeholder="e.g. HRM-6273"></td>
                        <td><input type="text" name="course_title[]" class="form-control form-control-sm" placeholder="Course Title"></td>
                        <td><input type="date" name="exam_date[]" class="form-control form-control-sm"></td>
                        <td><input type="text" name="time_slot[]" class="form-control form-control-sm" placeholder="e.g. 3:00pm-4:30pm"></td>
                        <td><input type="text" name="section[]" class="form-control form-control-sm" placeholder="e.g. A (69 Cr)"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-times"></i></button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
</form>

<script>
document.getElementById('dept_id').addEventListener('change', function() {
    const deptId = this.value;
    const programSel = document.getElementById('program_id');
    programSel.innerHTML = '<option value="">Loading…</option>';
    if (!deptId) { programSel.innerHTML = '<option value="">— Select Program —</option>'; return; }
    fetch('<?= APP_URL ?>/course-offer/get-programs.php?dept_id=' + deptId)
        .then(r => r.json())
        .then(data => {
            programSel.innerHTML = '<option value="">— Select Program —</option>';
            data.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.program_name;
                programSel.appendChild(opt);
            });
        });
});

document.getElementById('addCourseRow').addEventListener('click', function() {
    const tbody = document.getElementById('courseBody');
    const row = tbody.querySelector('.course-row').cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    tbody.appendChild(row);
});

document.getElementById('courseBody').addEventListener('click', function(e) {
    if (e.target.closest('.remove-row')) {
        const rows = this.querySelectorAll('.course-row');
        if (rows.length > 1) e.target.closest('.course-row').remove();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
