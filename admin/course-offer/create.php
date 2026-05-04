<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if (!co_can_create()) {
    flash_set('error', 'You do not have permission to create course offers.');
    redirect(APP_URL . '/course-offer/index.php');
}

$page_title = 'New Course Offer';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $dept_id       = (int)($_POST['dept_id']       ?? 0);
    $program_id    = (int)($_POST['program_id']     ?? 0);
    $batch_id      = (int)($_POST['batch_id']       ?? 0);
    $curriculum_id = (int)($_POST['curriculum_id']  ?? 0);
    $teacher_ids   = array_map('intval', (array)($_POST['teacher_ids'] ?? []));
    $teacher_ids   = array_values(array_filter($teacher_ids));
    $status        = $_POST['status'] === 'inactive' ? 'inactive' : 'active';

    // ── Validation ─────────────────────────────────────────────────────────
    if ($dept_id <= 0)       $errors[] = 'Please select a department.';
    if ($program_id <= 0)    $errors[] = 'Please select a program.';
    if ($batch_id <= 0)      $errors[] = 'Please select a batch.';
    if ($curriculum_id <= 0) $errors[] = 'Please choose a subject.';

    // Verify program belongs to dept
    if ($dept_id > 0 && $program_id > 0) {
        $chk = db()->prepare(
            "SELECT id FROM dept_academic_programs WHERE id = ? AND dept_id = ? LIMIT 1"
        );
        $chk->execute([$program_id, $dept_id]);
        if (!$chk->fetch()) {
            $errors[] = 'Selected program does not belong to the selected department.';
        }
    }

    // Verify batch belongs to program
    if ($program_id > 0 && $batch_id > 0) {
        $chk = db()->prepare(
            "SELECT id FROM course_curriculum_intakes WHERE id = ? AND program_id = ? LIMIT 1"
        );
        $chk->execute([$batch_id, $program_id]);
        if (!$chk->fetch()) {
            $errors[] = 'Selected batch does not belong to the selected program.';
        }
    }

    // Verify subject exists
    if ($curriculum_id > 0) {
        $chk = db()->prepare("SELECT id FROM course_curriculum WHERE id = ? LIMIT 1");
        $chk->execute([$curriculum_id]);
        if (!$chk->fetch()) {
            $errors[] = 'Selected subject does not exist.';
        }
    }

    // Uniqueness: same subject cannot be offered twice in the same batch
    if (empty($errors)) {
        $dup = db()->prepare(
            "SELECT id FROM co_offers WHERE batch_id = ? AND curriculum_id = ? LIMIT 1"
        );
        $dup->execute([$batch_id, $curriculum_id]);
        if ($dup->fetch()) {
            $errors[] = 'This subject is already offered for the selected batch.';
        }
    }

    if (empty($errors)) {
        $user = auth_user();
        db()->prepare(
            "INSERT INTO co_offers (dept_id, program_id, batch_id, curriculum_id, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$dept_id, $program_id, $batch_id, $curriculum_id, $status, $user['id']]);

        $offer_id = (int)db()->lastInsertId();
        co_save_teachers($offer_id, $teacher_ids);

        // Fetch subject name for log
        $sub = db()->prepare("SELECT course_name FROM course_curriculum WHERE id = ? LIMIT 1");
        $sub->execute([$curriculum_id]);
        $sub_name = $sub->fetchColumn() ?: 'Subject #' . $curriculum_id;

        log_change(
            'course-offer',
            'CREATE',
            $offer_id,
            $sub_name,
            null, null, null,
            'Course offer created for "' . $sub_name . '" (batch #' . $batch_id . ')'
        );

        flash_set('success', 'Course offer for <strong>' . h($sub_name) . '</strong> created.');
        redirect(APP_URL . '/course-offer/index.php');
    }

    save_old([
        'dept_id'       => $dept_id,
        'program_id'    => $program_id,
        'batch_id'      => $batch_id,
        'curriculum_id' => $curriculum_id,
        'teacher_ids'   => $teacher_ids,
        'status'        => $status,
    ]);
}

// ── Data for the form ─────────────────────────────────────────────────────────
$departments   = co_departments();
$old_dept      = (int)($_SESSION['old']['dept_id']       ?? $_GET['dept_id']    ?? 0);
$old_program   = (int)($_SESSION['old']['program_id']    ?? $_GET['program_id'] ?? 0);
$old_batch     = (int)($_SESSION['old']['batch_id']      ?? 0);
$old_subject   = (int)($_SESSION['old']['curriculum_id'] ?? 0);
$old_teachers  = (array)($_SESSION['old']['teacher_ids'] ?? []);
$old_status    = $_SESSION['old']['status'] ?? 'active';

$programs    = $old_dept    > 0 ? co_programs($old_dept)    : [];
$batches     = $old_program > 0 ? co_batches($old_program)  : [];

// Pre-load the selected subject and teachers for re-population on error
$pre_subject  = null;
if ($old_subject > 0) {
    $st = db()->prepare(
        "SELECT c.id, c.course_code, c.course_name, c.credit, p.program_name, d.name AS dept_name
           FROM course_curriculum c
           JOIN dept_academic_programs p ON p.id = c.program_id
           JOIN dept_departments       d ON d.id = p.dept_id
          WHERE c.id = ? LIMIT 1"
    );
    $st->execute([$old_subject]);
    $pre_subject = $st->fetch() ?: null;
}
$pre_teachers = [];
if (!empty($old_teachers)) {
    $ph  = implode(',', array_fill(0, count($old_teachers), '?'));
    $st  = db()->prepare(
        "SELECT f.id, f.name, f.designation, d.name AS dept_name
           FROM dept_faculty f JOIN dept_departments d ON d.id = f.dept_id
          WHERE f.id IN ($ph) ORDER BY f.name ASC"
    );
    $st->execute($old_teachers);
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
            <li class="breadcrumb-item active">New Offer</li>
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

    <div class="row g-4">

        <!-- ── Main column ──────────────────────────────────────────────────── -->
        <div class="col-lg-8">

            <!-- Department / Program / Batch cascade -->
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
                            <option value="<?= $d['id'] ?>" <?= $old_dept == $d['id'] ? 'selected' : '' ?>>
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
                            <option value="<?= $p['id'] ?>" <?= $old_program == $p['id'] ? 'selected' : '' ?>>
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
                            <?php foreach ($batches as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= $old_batch == $b['id'] ? 'selected' : '' ?>>
                                <?= h(co_batch_label($b)) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Select a program first. Batches are loaded from Course Curriculum intakes.</div>
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
                    <input type="hidden" name="curriculum_id" id="curriculum_id_input" value="<?= $old_subject ?>">
                    <select id="sel-subject" class="form-select">
                        <option value="">— Type to search subject —</option>
                        <?php if ($pre_subject): ?>
                        <option value="<?= $pre_subject['id'] ?>" selected>
                            <?php
                            $code = $pre_subject['course_code'] ? '[' . $pre_subject['course_code'] . '] ' : '';
                            echo h($code . $pre_subject['course_name'] . ' — ' . $pre_subject['program_name'] . ' (' . $pre_subject['dept_name'] . ')');
                            ?>
                        </option>
                        <?php endif; ?>
                    </select>
                    <div class="form-text">Search by subject code or name. Subjects come from the Course Curriculum module.</div>
                </div>
            </div>

            <!-- Teachers -->
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-chalkboard-teacher me-2 text-muted"></i>Course Teacher(s)
                        <small class="fw-normal text-muted ms-1">— searchable across all departments, multiple allowed</small>
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

        <!-- ── Sidebar column ───────────────────────────────────────────────── -->
        <div class="col-lg-4">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-cog me-2 text-muted"></i>Settings
                    </h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-medium">Status</label>
                        <select name="status" class="form-select">
                            <option value="active"   <?= $old_status === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $old_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Create Offer
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

// ── Cascade: department → program ─────────────────────────────────────────────
var deptSelect    = document.getElementById('sel-dept');
var programSelect = document.getElementById('sel-program');
var batchSelect   = document.getElementById('sel-batch');

deptSelect.addEventListener('change', function() {
    var deptId = this.value;
    programSelect.innerHTML = '<option value="">— Select Program —</option>';
    batchSelect.innerHTML   = '<option value="">— Select Batch —</option>';
    if (!deptId) return;
    fetch(APP_URL + '/course-offer/get-programs.php?dept_id=' + encodeURIComponent(deptId))
        .then(r => r.json())
        .then(function(data) {
            data.forEach(function(p) {
                var opt = new Option(p.program_name, p.id);
                programSelect.appendChild(opt);
            });
        });
});

// ── Cascade: program → batch ──────────────────────────────────────────────────
programSelect.addEventListener('change', function() {
    var programId = this.value;
    batchSelect.innerHTML = '<option value="">— Select Batch —</option>';
    if (!programId) return;
    fetch(APP_URL + '/course-offer/get-batches.php?program_id=' + encodeURIComponent(programId))
        .then(r => r.json())
        .then(function(data) {
            data.forEach(function(b) {
                var opt = new Option(b.label, b.id);
                batchSelect.appendChild(opt);
            });
        });
});

// ── TomSelect: Department (plain searchable) ──────────────────────────────────
new TomSelect('#sel-dept', {
    allowEmptyOption: true,
    sortField: 'text',
    onChange: function() {
        // native change event is fired; cascade handled above
    }
});

// ── TomSelect: Program (plain searchable, rebuilt on dept change) ─────────────
var tsProgram = new TomSelect('#sel-program', {
    allowEmptyOption: true,
    sortField: 'text',
    onChange: function() {}
});

// ── TomSelect: Batch (plain searchable, rebuilt on program change) ────────────
var tsBatch = new TomSelect('#sel-batch', {
    allowEmptyOption: true,
    sortField: 'text',
});

// Rebuild TomSelect dropdowns when native select options change
deptSelect.addEventListener('change', function() {
    setTimeout(function() {
        tsProgram.clear(true);
        tsProgram.clearOptions();
        tsProgram.addOption({value: '', text: '— Select Program —'});
        Array.from(programSelect.options).forEach(function(o) {
            if (o.value) tsProgram.addOption({value: o.value, text: o.text});
        });
        tsProgram.setValue('', true);
        // Also reset batch
        tsBatch.clear(true);
        tsBatch.clearOptions();
        tsBatch.addOption({value: '', text: '— Select Batch —'});
        tsBatch.setValue('', true);
    }, 100);
});

programSelect.addEventListener('change', function() {
    setTimeout(function() {
        tsBatch.clear(true);
        tsBatch.clearOptions();
        tsBatch.addOption({value: '', text: '— Select Batch —'});
        Array.from(batchSelect.options).forEach(function(o) {
            if (o.value) tsBatch.addOption({value: o.value, text: o.text});
        });
        tsBatch.setValue('', true);
    }, 150);
});

// ── TomSelect: Subject (server-side AJAX search) ──────────────────────────────
var preSubjectId   = <?= json_encode($old_subject) ?>;
var curriculumInput = document.getElementById('curriculum_id_input');

new TomSelect('#sel-subject', {
    valueField:   'id',
    labelField:   'text',
    searchField:  ['text'],
    allowEmptyOption: true,
    placeholder:  'Type to search…',
    load: function(query, callback) {
        if (!query.length) return callback();
        fetch(APP_URL + '/course-offer/get-subjects.php?q=' + encodeURIComponent(query))
            .then(r => r.json())
            .then(callback)
            .catch(function() { callback(); });
    },
    onChange: function(value) {
        curriculumInput.value = value || '';
    },
    <?php if ($pre_subject): ?>
    items: [<?= json_encode($old_subject) ?>],
    options: [{
        id:   <?= json_encode($old_subject) ?>,
        text: <?= json_encode(
            ($pre_subject['course_code'] ? '[' . $pre_subject['course_code'] . '] ' : '')
            . $pre_subject['course_name']
            . ' — ' . $pre_subject['program_name']
            . ' (' . $pre_subject['dept_name'] . ')'
        ) ?>
    }],
    <?php endif; ?>
});

// ── TomSelect: Teachers (server-side AJAX, multiple) ─────────────────────────
new TomSelect('#sel-teachers', {
    valueField:   'id',
    labelField:   'text',
    searchField:  ['text'],
    maxItems:     null,
    placeholder:  'Type to search teacher…',
    plugins:      ['remove_button'],
    load: function(query, callback) {
        if (!query.length) return callback();
        fetch(APP_URL + '/course-offer/get-faculty.php?q=' + encodeURIComponent(query))
            .then(r => r.json())
            .then(callback)
            .catch(function() { callback(); });
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
