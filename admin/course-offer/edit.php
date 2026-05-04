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
    $semester        = trim($_POST['semester']         ?? '');
    $academic_intake = trim($_POST['academic_intake']  ?? '');
    $status          = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';

    // Parse subject rows
    $rows_raw = (array)($_POST['rows'] ?? []);
    $rows     = [];
    foreach ($rows_raw as $row) {
        $cid  = (int)($row['curriculum_id'] ?? 0);
        $tids = array_values(array_filter(array_map('intval', (array)($row['teacher_ids'] ?? []))));
        if ($cid > 0) {
            $rows[] = ['curriculum_id' => $cid, 'teacher_ids' => $tids];
        }
    }

    // ── Validation ─────────────────────────────────────────────────────────
    if ($dept_id <= 0)    $errors[] = 'Please select a department.';
    if ($program_id <= 0) $errors[] = 'Please select a program.';
    if ($batch_id <= 0)   $errors[] = 'Please select a batch.';
    if (empty($rows))     $errors[] = 'Please add at least one subject row.';

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

    $seen_cids = [];
    foreach ($rows as &$row) {
        $cid = $row['curriculum_id'];
        if (in_array($cid, $seen_cids, true)) {
            $errors[] = 'Duplicate subject selected. Each subject may appear only once.';
            break;
        }
        $seen_cids[] = $cid;
        $chk = db()->prepare("SELECT id FROM course_curriculum WHERE id = ? LIMIT 1");
        $chk->execute([$cid]);
        if (!$chk->fetch()) {
            $errors[] = "Subject ID $cid does not exist.";
        }
    }
    unset($row);

    if (empty($errors)) {
        db()->prepare(
            "UPDATE co_offers
                SET dept_id = ?, program_id = ?, batch_id = ?,
                    semester = ?, academic_intake = ?, status = ?
              WHERE id = ?"
        )->execute([$dept_id, $program_id, $batch_id,
                    $semester ?: null, $academic_intake ?: null, $status, $id]);

        co_save_subjects_teachers($id, $rows);

        log_change('course-offer', 'UPDATE', $id, 'Offer #' . $id, null, null, null,
            'Course offer #' . $id . ' updated with ' . count($rows) . ' subject(s)');

        flash_set('success', 'Course offer updated.');
        redirect(APP_URL . '/course-offer/index.php');
    }

    save_old([
        'dept_id'         => $dept_id,
        'program_id'      => $program_id,
        'batch_id'        => $batch_id,
        'semester'        => $semester,
        'academic_intake' => $academic_intake,
        'rows'            => $rows,
        'status'          => $status,
    ]);

    $offer = array_merge($offer, [
        'dept_id'         => $dept_id,
        'program_id'      => $program_id,
        'batch_id'        => $batch_id,
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

$cur_semester = $_SESSION['old']['semester']        ?? ($offer['semester']        ?? '');
$cur_intake   = $_SESSION['old']['academic_intake'] ?? ($offer['academic_intake'] ?? '');

// Build row list: prefer session-old (after validation error), else load from DB
if (!empty($_SESSION['old']['rows'])) {
    $raw_rows = (array)$_SESSION['old']['rows'];
    $pre_rows = [];
    foreach ($raw_rows as $row) {
        $cid  = (int)($row['curriculum_id'] ?? 0);
        if ($cid <= 0) continue;
        $tids = array_values(array_filter(array_map('intval', (array)($row['teacher_ids'] ?? []))));
        $st = db()->prepare(
            "SELECT c.id, c.course_code, c.course_name, c.credit, p.program_name, d.name AS dept_name
               FROM course_curriculum c
               JOIN dept_academic_programs p ON p.id = c.program_id
               JOIN dept_departments       d ON d.id = p.dept_id
              WHERE c.id = ? LIMIT 1"
        );
        $st->execute([$cid]);
        $sub = $st->fetch() ?: null;
        $pre_teachers = [];
        if (!empty($tids)) {
            $ph = implode(',', array_fill(0, count($tids), '?'));
            $ts = db()->prepare(
                "SELECT f.id, f.name, f.designation, d.name AS dept_name
                   FROM dept_faculty f JOIN dept_departments d ON d.id = f.dept_id
                  WHERE f.id IN ($ph) ORDER BY f.name ASC"
            );
            $ts->execute($tids);
            $pre_teachers = $ts->fetchAll();
        }
        $pre_rows[] = ['subject' => $sub, 'teacher_ids' => $tids, 'teachers' => $pre_teachers];
    }
} else {
    // Load from DB
    $db_subjects = co_get_subjects_with_teachers($id);
    $pre_rows = [];
    foreach ($db_subjects as $sub) {
        $tids = array_column($sub['teachers'], 'id');
        $pre_rows[] = [
            'subject'    => [
                'id'           => $sub['curriculum_id'],
                'course_code'  => $sub['course_code'],
                'course_name'  => $sub['course_name'],
                'credit'       => $sub['credit'],
                'program_name' => $sub['program_name'],
                'dept_name'    => $sub['dept_name'],
            ],
            'teacher_ids' => $tids,
            'teachers'    => $sub['teachers'],
        ];
    }
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

            <!-- Subjects & Teachers -->
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-book me-2 text-muted"></i>Subjects &amp; Teachers
                        <small class="fw-normal text-muted ms-1">— one row per subject</small>
                    </h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-row"
                            style="border-radius:8px;">
                        <i class="fas fa-plus me-1"></i>Add Row
                    </button>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-2" id="subjects-table"
                               style="font-size:.875rem;">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:44%;">Subject <span class="text-danger">*</span></th>
                                    <th>Assigned Teacher(s)</th>
                                    <th style="width:2.5rem;"></th>
                                </tr>
                            </thead>
                            <tbody id="subjects-tbody">
                                <?php foreach ($pre_rows as $ri => $pr): ?>
                                <tr class="subject-row" data-row="<?= $ri ?>">
                                    <td>
                                        <input type="hidden"
                                               name="rows[<?= $ri ?>][curriculum_id]"
                                               class="curriculum-id-input"
                                               value="<?= (int)($pr['subject']['id'] ?? 0) ?>">
                                        <select class="form-select form-select-sm subject-select">
                                            <option value="">— Type to search subject —</option>
                                            <?php if ($pr['subject']): $sub = $pr['subject'];
                                                $code = $sub['course_code'] ? '[' . $sub['course_code'] . '] ' : ''; ?>
                                            <option value="<?= $sub['id'] ?>" selected>
                                                <?= h($code . $sub['course_name'] . ' — ' . $sub['program_name'] . ' (' . $sub['dept_name'] . ')') ?>
                                            </option>
                                            <?php endif; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="rows[<?= $ri ?>][teacher_ids][]"
                                                class="form-select form-select-sm teacher-select" multiple>
                                            <?php foreach ($pr['teachers'] as $t):
                                                $desig = $t['designation'] ? ', ' . $t['designation'] : ''; ?>
                                            <option value="<?= $t['id'] ?>" selected>
                                                <?= h($t['name'] . $desig . ' (' . $t['dept_name'] . ')') ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row"
                                                title="Remove row" style="border-radius:6px;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small ps-1" id="no-rows-hint"
                         <?= !empty($pre_rows) ? 'style="display:none;"' : '' ?>>
                        Click <strong>Add Row</strong> to add a subject.
                    </div>
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

<?php $pre_rows_json = json_encode(array_map(function($pr, $i) {
    $sub = $pr['subject'];
    $code = ($sub && $sub['course_code']) ? '[' . $sub['course_code'] . '] ' : '';
    return [
        'ri'          => $i,
        'cid'         => $sub ? (int)$sub['id'] : 0,
        'ctext'       => $sub ? ($code . $sub['course_name'] . ' — ' . $sub['program_name'] . ' (' . $sub['dept_name'] . ')') : '',
        'teacher_ids' => $pr['teacher_ids'],
        'teachers'    => array_map(function($t) {
            $desig = $t['designation'] ? ', ' . $t['designation'] : '';
            return ['id' => (int)$t['id'], 'text' => $t['name'] . $desig . ' (' . $t['dept_name'] . ')'];
        }, $pr['teachers']),
    ];
}, $pre_rows, array_keys($pre_rows))); ?>

<script>
var APP_URL    = <?= json_encode(APP_URL) ?>;
var PRE_ROWS   = <?= $pre_rows_json ?>;
var rowCounter = <?= count($pre_rows) ?>;

var tsSubjectMap = {};
var tsTeacherMap = {};

var deptSelect    = document.getElementById('sel-dept');
var programSelect = document.getElementById('sel-program');

deptSelect.addEventListener('change', function() {
    var deptId = this.value;
    programSelect.innerHTML = '<option value="">— Select Program —</option>';
    if (!deptId) return;
    fetch(APP_URL + '/course-offer/get-programs.php?dept_id=' + encodeURIComponent(deptId))
        .then(r => r.json())
        .then(function(data) {
            tsProgram.clear(true); tsProgram.clearOptions();
            tsProgram.addOption({value: '', text: '— Select Program —'});
            data.forEach(function(p) { tsProgram.addOption({value: p.id, text: p.program_name}); });
            tsProgram.setValue('', true);
        });
});

new TomSelect('#sel-dept',    { allowEmptyOption: true, sortField: 'text' });
var tsProgram = new TomSelect('#sel-program', { allowEmptyOption: true, sortField: 'text' });
new TomSelect('#sel-batch',   { allowEmptyOption: true, sortField: 'text' });

var semesterInput = document.getElementById('semester-input');
new TomSelect('#sel-semester', {
    allowEmptyOption: true, create: true, sortField: 'text',
    onChange: function(v) { semesterInput.value = v || ''; },
});

var intakeInput = document.getElementById('intake-input');
new TomSelect('#sel-intake', {
    allowEmptyOption: true, create: true, sortField: 'text',
    onChange: function(v) { intakeInput.value = v || ''; },
});

function buildSubjectSelect(ri, cid, ctext) {
    var el = document.querySelector('[data-row="' + ri + '"] .subject-select');
    var hiddenEl = document.querySelector('[data-row="' + ri + '"] .curriculum-id-input');
    var opts = { valueField: 'id', labelField: 'text', searchField: ['text'],
        allowEmptyOption: true, placeholder: 'Type to search subject\u2026',
        load: function(q, cb) {
            if (!q.length) return cb();
            fetch(APP_URL + '/course-offer/get-subjects.php?q=' + encodeURIComponent(q))
                .then(r => r.json()).then(cb).catch(function() { cb(); });
        },
        onChange: function(v) { hiddenEl.value = v || ''; },
    };
    if (cid) { opts.items = [String(cid)]; opts.options = [{id: cid, text: ctext}]; }
    tsSubjectMap[ri] = new TomSelect(el, opts);
}

function buildTeacherSelect(ri, tids, teachers) {
    var el = document.querySelector('[data-row="' + ri + '"] .teacher-select');
    var opts = { valueField: 'id', labelField: 'text', searchField: ['text'],
        maxItems: null, placeholder: 'Type to search teacher\u2026',
        plugins: ['remove_button'],
        load: function(q, cb) {
            if (!q.length) return cb();
            fetch(APP_URL + '/course-offer/get-faculty.php?q=' + encodeURIComponent(q))
                .then(r => r.json()).then(cb).catch(function() { cb(); });
        },
    };
    if (tids && tids.length) {
        opts.items   = tids.map(String);
        opts.options = teachers;
    }
    tsTeacherMap[ri] = new TomSelect(el, opts);
}

function addRow(cid, ctext, tids, teachers) {
    var ri = rowCounter++;
    var noHint = document.getElementById('no-rows-hint');
    if (noHint) noHint.style.display = 'none';

    var tr = document.createElement('tr');
    tr.className = 'subject-row';
    tr.setAttribute('data-row', ri);
    tr.innerHTML =
        '<td>' +
            '<input type="hidden" name="rows[' + ri + '][curriculum_id]" class="curriculum-id-input" value="' + (cid || '') + '">' +
            '<select class="form-select form-select-sm subject-select">' +
                '<option value="">— Type to search subject —</option>' +
            '</select>' +
        '</td>' +
        '<td>' +
            '<select name="rows[' + ri + '][teacher_ids][]" class="form-select form-select-sm teacher-select" multiple></select>' +
        '</td>' +
        '<td class="text-center">' +
            '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" title="Remove row" style="border-radius:6px;">' +
                '<i class="fas fa-times"></i>' +
            '</button>' +
        '</td>';

    document.getElementById('subjects-tbody').appendChild(tr);
    buildSubjectSelect(ri, cid || 0, ctext || '');
    buildTeacherSelect(ri, tids || [], teachers || []);
}

function removeRow(btn) {
    var tr = btn.closest('tr.subject-row');
    var ri = parseInt(tr.getAttribute('data-row'));
    if (tsSubjectMap[ri]) { tsSubjectMap[ri].destroy(); delete tsSubjectMap[ri]; }
    if (tsTeacherMap[ri]) { tsTeacherMap[ri].destroy(); delete tsTeacherMap[ri]; }
    tr.remove();
    var tbody = document.getElementById('subjects-tbody');
    var noHint = document.getElementById('no-rows-hint');
    if (noHint) noHint.style.display = tbody.children.length === 0 ? '' : 'none';
}

document.getElementById('subjects-tbody').addEventListener('click', function(e) {
    var btn = e.target.closest('.btn-remove-row');
    if (btn) removeRow(btn);
});

document.getElementById('btn-add-row').addEventListener('click', function() {
    addRow();
});

PRE_ROWS.forEach(function(pr) {
    buildSubjectSelect(pr.ri, pr.cid, pr.ctext);
    buildTeacherSelect(pr.ri, pr.teacher_ids, pr.teachers);
});

if (PRE_ROWS.length === 0) addRow();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
