<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if (!co_is_staff()) {
    flash_set('error', 'You do not have permission to edit course offers.');
    redirect(APP_URL . '/course-offer/index.php');
}

$id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$offer = $id > 0 ? co_get_offer($id) : null;
if (!$offer) {
    flash_set('error', 'Course offer not found.');
    redirect(APP_URL . '/course-offer/index.php');
}

$page_title = 'Edit Course Offer';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $dept_id         = (int)($_POST['dept_id']         ?? 0);
    $program_id      = (int)($_POST['program_id']      ?? 0);
    $batch_id        = (int)($_POST['batch_id']        ?? 0);
    $curriculum_id   = (int)($_POST['curriculum_id']   ?? 0);
    $semester        = trim($_POST['semester']         ?? '');
    $academic_intake = trim($_POST['academic_intake']  ?? '');
    $teacher_ids     = array_map('intval', (array)($_POST['teacher_ids'] ?? []));
    $teacher_ids     = array_values(array_filter($teacher_ids));
    $status          = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

    // ── Validation ─────────────────────────────────────────────────────────
    if ($dept_id <= 0)       $errors[] = 'Please select a department.';
    if ($program_id <= 0)    $errors[] = 'Please select a program.';
    if ($batch_id <= 0)      $errors[] = 'Please select a batch.';
    if ($curriculum_id <= 0) $errors[] = 'Please choose a subject.';

    if ($dept_id > 0 && $program_id > 0) {
        $chk = db()->prepare(
            "SELECT id FROM dept_academic_programs WHERE id = ? AND dept_id = ? LIMIT 1"
        );
        $chk->execute([$program_id, $dept_id]);
        if (!$chk->fetch()) $errors[] = 'Selected program does not belong to the selected department.';
    }

    if ($batch_id > 0) {
        $chk = db()->prepare("SELECT id FROM student_batches WHERE id = ? LIMIT 1");
        $chk->execute([$batch_id]);
        if (!$chk->fetch()) $errors[] = 'Selected batch does not exist.';
    }

    if ($curriculum_id > 0) {
        $chk = db()->prepare("SELECT id FROM course_curriculum WHERE id = ? LIMIT 1");
        $chk->execute([$curriculum_id]);
        if (!$chk->fetch()) $errors[] = 'Selected subject does not exist.';
    }

    // Uniqueness (exclude current offer)
    if (empty($errors)) {
        $dup = db()->prepare(
            "SELECT id FROM co_offers WHERE batch_id = ? AND curriculum_id = ? AND id != ? LIMIT 1"
        );
        $dup->execute([$batch_id, $curriculum_id, $id]);
        if ($dup->fetch()) {
            $errors[] = 'This subject is already offered for the selected batch.';
        }
    }

    if (empty($errors)) {
        db()->prepare(
            "UPDATE co_offers
                SET dept_id = ?, program_id = ?, batch_id = ?, curriculum_id = ?,
                    semester = ?, academic_intake = ?, status = ?
              WHERE id = ?"
        )->execute([$dept_id, $program_id, $batch_id, $curriculum_id,
                    $semester ?: null, $academic_intake ?: null, $status, $id]);

        co_save_teachers($id, $teacher_ids);

        $sub = db()->prepare("SELECT course_name FROM course_curriculum WHERE id = ? LIMIT 1");
        $sub->execute([$curriculum_id]);
        $sub_name = $sub->fetchColumn() ?: 'Subject #' . $curriculum_id;

        log_change('course-offer', 'UPDATE', $id, $sub_name, null, null, null,
            'Course offer #' . $id . ' updated');

        flash_set('success', 'Course offer updated.');
        redirect(APP_URL . '/course-offer/index.php');
    }

    save_old([
        'dept_id'         => $dept_id,
        'program_id'      => $program_id,
        'batch_id'        => $batch_id,
        'curriculum_id'   => $curriculum_id,
        'semester'        => $semester,
        'academic_intake' => $academic_intake,
        'teacher_ids'     => $teacher_ids,
        'status'          => $status,
    ]);

    $offer = array_merge($offer, [
        'dept_id'         => $dept_id,
        'program_id'      => $program_id,
        'batch_id'        => $batch_id,
        'curriculum_id'   => $curriculum_id,
        'semester'        => $semester,
        'academic_intake' => $academic_intake,
        'status'          => $status,
    ]);
}

// ── Form data ─────────────────────────────────────────────────────────────────
$departments   = co_departments();
$all_batches   = co_student_batches();
$semester_opts = co_semester_options();
$intake_opts   = co_academic_intake_options();
$programs      = co_programs((int)$offer['dept_id']);

$cur_teachers = !empty($_SESSION['old']['teacher_ids'])
    ? array_map('intval', (array)$_SESSION['old']['teacher_ids'])
    : array_column(co_get_teachers($id), 'id');

$cur_semester = $_SESSION['old']['semester']        ?? ($offer['semester']        ?? '');
$cur_intake   = $_SESSION['old']['academic_intake'] ?? ($offer['academic_intake'] ?? '');

// Subject for pre-load
$pre_subject = null;
$sub_id = (int)$offer['curriculum_id'];
if ($sub_id > 0) {
    $st = db()->prepare(
        "SELECT c.id, c.course_code, c.course_name, c.credit, p.program_name, d.name AS dept_name
           FROM course_curriculum c
           JOIN dept_academic_programs p ON p.id = c.program_id
           JOIN dept_departments       d ON d.id = p.dept_id
          WHERE c.id = ? LIMIT 1"
    );
    $st->execute([$sub_id]);
    $pre_subject = $st->fetch() ?: null;
}

// Teachers for pre-load
$pre_teachers = [];
if (!empty($cur_teachers)) {
    $ph = implode(',', array_fill(0, count($cur_teachers), '?'));
    $st = db()->prepare(
        "SELECT f.id, f.name, f.designation, d.name AS dept_name
           FROM dept_faculty f JOIN dept_departments d ON d.id = f.dept_id
          WHERE f.id IN ($ph) ORDER BY f.name ASC"
    );
    $st->execute($cur_teachers);
    $pre_teachers = $st->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">';
echo '<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-offer/index.php">Course Offer</a></li>
            <li class="breadcrumb-item active">Edit Offer #<?= $id ?></li>
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

<form method="POST" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="row g-4">

        <!-- ── Main column ──────────────────────────────────────────────────── -->
        <div class="col-lg-8">

            <!-- Department / Program / Batch -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-sitemap me-2 text-muted"></i>Offering Department, Program &amp; Batch
                    </h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">
                            Department <span class="text-danger">*</span>
                        </label>
                        <select name="dept_id" id="sel-dept" class="form-select" required>
                            <option value="">— Select Department —</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $offer['dept_id'] == $d['id'] ? 'selected' : '' ?>>
                                <?= h($d['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">
                            Program <span class="text-danger">*</span>
                        </label>
                        <select name="program_id" id="sel-program" class="form-select" required>
                            <option value="">— Select Program —</option>
                            <?php foreach ($programs as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $offer['program_id'] == $p['id'] ? 'selected' : '' ?>>
                                <?= h($p['program_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Select a department first.</div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-medium">
                            Batch <span class="text-danger">*</span>
                        </label>
                        <select name="batch_id" id="sel-batch" class="form-select" required>
                            <option value="">— Select Batch —</option>
                            <?php foreach ($all_batches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= $offer['batch_id'] == $b['id'] ? 'selected' : '' ?>>
                                <?= h($b['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Batches from the Student Batches registry.</div>
                    </div>

                </div>
            </div>

            <!-- Semester & Academic Intake -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-calendar-alt me-2 text-muted"></i>Semester &amp; Academic Intake
                    </h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Semester</label>
                            <input type="hidden" name="semester" id="semester-input"
                                   value="<?= h($cur_semester) ?>">
                            <select id="sel-semester" class="form-select">
                                <option value="">— Select or type semester —</option>
                                <?php foreach ($semester_opts as $s): ?>
                                <option value="<?= h($s) ?>"
                                    <?= $cur_semester === $s ? 'selected' : '' ?>>
                                    <?= h($s) ?>
                                </option>
                                <?php endforeach; ?>
                                <?php if ($cur_semester && !in_array($cur_semester, $semester_opts)): ?>
                                <option value="<?= h($cur_semester) ?>" selected>
                                    <?= h($cur_semester) ?>
                                </option>
                                <?php endif; ?>
                            </select>
                            <div class="form-text">e.g. Spring 2026, Summer 2026. Type to create custom.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Academic Intake</label>
                            <input type="hidden" name="academic_intake" id="intake-input"
                                   value="<?= h($cur_intake) ?>">
                            <select id="sel-intake" class="form-select">
                                <option value="">— Select academic intake —</option>
                                <?php foreach ($intake_opts as $ai): ?>
                                <option value="<?= h($ai) ?>"
                                    <?= $cur_intake === $ai ? 'selected' : '' ?>>
                                    <?= h($ai) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">e.g. 1st Year 1st Semester.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Subject -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-book me-2 text-muted"></i>Subject
                        <small class="fw-normal text-muted ms-1">— searchable across all departments &amp; programs</small>
                    </h6>
                </div>
                <div class="card-body p-4">
                    <input type="hidden" name="curriculum_id" id="curriculum_id_input"
                           value="<?= h($offer['curriculum_id']) ?>">
                    <select id="sel-subject" class="form-select">
                        <option value="">— Type to search subject —</option>
                        <?php if ($pre_subject): ?>
                        <option value="<?= $pre_subject['id'] ?>" selected>
                            <?php
                            $code = $pre_subject['course_code'] ? '[' . $pre_subject['course_code'] . '] ' : '';
                            echo h($code . $pre_subject['course_name']
                                . ' — ' . $pre_subject['program_name']
                                . ' (' . $pre_subject['dept_name'] . ')');
                            ?>
                        </option>
                        <?php endif; ?>
                    </select>
                    <div class="form-text">Search by subject code or name.</div>
                </div>
            </div>

            <!-- Teachers -->
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-chalkboard-teacher me-2 text-muted"></i>Course Teacher(s)
                        <small class="fw-normal text-muted ms-1">— searchable, multiple allowed</small>
                    </h6>
                </div>
                <div class="card-body p-4">
                    <select name="teacher_ids[]" id="sel-teachers" class="form-select" multiple>
                        <?php foreach ($pre_teachers as $t): ?>
                        <option value="<?= $t['id'] ?>" selected>
                            <?php
                            $desig = $t['designation'] ? ', ' . $t['designation'] : '';
                            echo h($t['name'] . $desig . ' (' . $t['dept_name'] . ')');
                            ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Type a name or department to search. You may assign more than one teacher.</div>
                </div>
            </div>

        </div>

        <!-- ── Sidebar ───────────────────────────────────────────────────────── -->
        <div class="col-lg-4">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Settings</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-medium">Status</label>
                        <select name="status" class="form-select">
                            <option value="active"   <?= $offer['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $offer['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                        <a href="<?= APP_URL ?>/course-offer/index.php"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

<script>
var APP_URL = <?= json_encode(APP_URL) ?>;

var deptSelect    = document.getElementById('sel-dept');
var programSelect = document.getElementById('sel-program');

deptSelect.addEventListener('change', function() {
    var deptId = this.value;
    programSelect.innerHTML = '<option value="">— Select Program —</option>';
    if (!deptId) return;
    fetch(APP_URL + '/course-offer/get-programs.php?dept_id=' + encodeURIComponent(deptId))
        .then(r => r.json())
        .then(function(data) {
            data.forEach(function(p) {
                var opt = new Option(p.program_name, p.id);
                programSelect.appendChild(opt);
            });
            tsProgram.clear(true); tsProgram.clearOptions();
            tsProgram.addOption({value: '', text: '— Select Program —'});
            data.forEach(function(p) { tsProgram.addOption({value: p.id, text: p.program_name}); });
            tsProgram.setValue('', true);
        });
});

new TomSelect('#sel-dept', { allowEmptyOption: true, sortField: 'text' });

var tsProgram = new TomSelect('#sel-program', { allowEmptyOption: true, sortField: 'text' });

deptSelect.addEventListener('change', function() {
    setTimeout(function() {
        tsProgram.clear(true); tsProgram.clearOptions();
        tsProgram.addOption({value: '', text: '— Select Program —'});
        Array.from(programSelect.options).forEach(function(o) {
            if (o.value) tsProgram.addOption({value: o.value, text: o.text});
        });
        tsProgram.setValue('', true);
    }, 150);
});

new TomSelect('#sel-batch', { allowEmptyOption: true, sortField: 'text' });

// ── TomSelect: Semester ───────────────────────────────────────────────────────
var semesterInput = document.getElementById('semester-input');
new TomSelect('#sel-semester', {
    allowEmptyOption: true,
    create: true,
    sortField: 'text',
    onChange: function(value) { semesterInput.value = value || ''; },
});

// ── TomSelect: Academic Intake ────────────────────────────────────────────────
var intakeInput = document.getElementById('intake-input');
new TomSelect('#sel-intake', {
    allowEmptyOption: true,
    create: true,
    sortField: 'text',
    onChange: function(value) { intakeInput.value = value || ''; },
});

// ── TomSelect: Subject ────────────────────────────────────────────────────────
var curriculumInput = document.getElementById('curriculum_id_input');
new TomSelect('#sel-subject', {
    valueField:      'id',
    labelField:      'text',
    searchField:     ['text'],
    allowEmptyOption: true,
    placeholder:     'Type to search…',
    load: function(query, callback) {
        if (!query.length) return callback();
        fetch(APP_URL + '/course-offer/get-subjects.php?q=' + encodeURIComponent(query))
            .then(r => r.json()).then(callback).catch(function() { callback(); });
    },
    onChange: function(value) { curriculumInput.value = value || ''; },
    <?php if ($pre_subject): ?>
    items:   [<?= json_encode($sub_id) ?>],
    options: [{
        id:   <?= json_encode($sub_id) ?>,
        text: <?= json_encode(
            ($pre_subject['course_code'] ? '[' . $pre_subject['course_code'] . '] ' : '')
            . $pre_subject['course_name']
            . ' — ' . $pre_subject['program_name']
            . ' (' . $pre_subject['dept_name'] . ')'
        ) ?>
    }],
    <?php endif; ?>
});

// ── TomSelect: Teachers ───────────────────────────────────────────────────────
new TomSelect('#sel-teachers', {
    valueField:  'id',
    labelField:  'text',
    searchField: ['text'],
    maxItems:    null,
    placeholder: 'Type to search teacher…',
    plugins:     ['remove_button'],
    load: function(query, callback) {
        if (!query.length) return callback();
        fetch(APP_URL + '/course-offer/get-faculty.php?q=' + encodeURIComponent(query))
            .then(r => r.json()).then(callback).catch(function() { callback(); });
    },
    <?php if (!empty($pre_teachers)): ?>
    items:   <?= json_encode(array_column($pre_teachers, 'id')) ?>,
    options: <?= json_encode(array_map(function($t) {
        $desig = $t['designation'] ? ', ' . $t['designation'] : '';
        return ['id' => $t['id'], 'text' => $t['name'] . $desig . ' (' . $t['dept_name'] . ')'];
    }, $pre_teachers)) ?>,
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
