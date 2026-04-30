<?php
/**
 * Mark Entry – Teacher creates / edits a mark sheet.
 * URL: /results/mark-entry.php          (new sheet)
 *      /results/mark-entry.php?id=X     (edit draft/returned sheet)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/workflow-helpers.php';

if (!wf_can_enter()) {
    flash_set('error', 'You do not have permission to enter marks.');
    redirect(APP_URL . '/results/index.php');
}

$user     = auth_user();
$sheet_id = (int)($_GET['id'] ?? 0);
$sheet    = null;
$grades   = [];
$errors   = [];
clear_old();

// ── Allowed departments for this teacher ──────────────────────────────────────
$dept_scope   = get_dept_scope();  // null = all
$dept_sql     = 'SELECT id, name FROM dept_departments WHERE is_active = 1';
$dept_params  = [];
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $departments = [];
    } else {
        $phs          = implode(',', array_fill(0, count($dept_scope), '?'));
        $dept_sql    .= " AND id IN ($phs)";
        $dept_params  = $dept_scope;
    }
}
if ($dept_scope === null || !empty($dept_scope)) {
    $ds           = db()->prepare($dept_sql . ' ORDER BY name ASC');
    $ds->execute($dept_params);
    $departments  = $ds->fetchAll();
} else {
    $departments = [];
}

// ── Load existing sheet (edit mode) ──────────────────────────────────────────
if ($sheet_id > 0) {
    $stmt = db()->prepare(
        'SELECT ms.*, d.name AS dept_name, p.program_name
         FROM result_mark_sheets ms
         JOIN dept_departments d            ON d.id = ms.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = ms.program_id
         WHERE ms.id = ?'
    );
    $stmt->execute([$sheet_id]);
    $sheet = $stmt->fetch();
    if (!$sheet) {
        flash_set('error', 'Mark sheet not found.');
        redirect(APP_URL . '/results/index.php');
    }
    // Only owner can edit; status must be draft or returned
    if ((int)$sheet['created_by'] !== (int)$user['id'] && !is_super_admin()) {
        flash_set('error', 'You can only edit your own mark sheets.');
        redirect(APP_URL . '/results/index.php');
    }
    if (!in_array($sheet['workflow_status'], ['draft', 'returned'], true)) {
        flash_set('error', 'This sheet has already been submitted and cannot be edited.');
        redirect(APP_URL . '/results/mark-entry.php?id=' . $sheet_id . '&view=1');
    }
    $grades = wf_get_grades($sheet_id);
}

$semesters   = wf_semester_list();
$page_title  = $sheet ? 'Edit Mark Sheet' : 'New Mark Sheet';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action         = $_POST['action'] ?? 'save';   // 'save' or 'submit'
    $dept_id        = (int)($_POST['dept_id']     ?? 0);
    $program_id     = (int)($_POST['program_id']  ?? 0);
    $semester       = trim($_POST['semester']     ?? '');
    $academic_year  = trim($_POST['academic_year'] ?? '');
    $curriculum_id  = (int)($_POST['curriculum_id'] ?? 0);
    $subject_code   = trim($_POST['subject_code'] ?? '');
    $subject_title  = trim($_POST['subject_title'] ?? '');
    $credits        = trim($_POST['credits']       ?? '');

    if ($dept_id <= 0)        $errors[] = 'Department is required.';
    if ($semester === '')      $errors[] = 'Semester is required.';
    if ($subject_title === '') $errors[] = 'Subject title is required.';

    // Validate dept access
    if ($dept_id > 0 && !can_access_dept($dept_id)) {
        $errors[] = 'You do not have access to the selected department.';
    }

    if (empty($errors)) {
        $db = db();

        if ($sheet_id > 0) {
            // Update existing sheet header
            $db->prepare(
                'UPDATE result_mark_sheets SET
                   dept_id = ?, program_id = ?, semester = ?, academic_year = ?,
                   curriculum_id = ?, subject_code = ?, subject_title = ?, credits = ?,
                   workflow_status = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $dept_id,
                $program_id ?: null,
                $semester,
                $academic_year ?: null,
                $curriculum_id ?: null,
                $subject_code ?: null,
                $subject_title,
                $credits !== '' ? (float)$credits : null,
                $action === 'submit' ? 'submitted' : 'draft',
                $sheet_id,
            ]);
            if ($action === 'submit') {
                $db->prepare('UPDATE result_mark_sheets SET submitted_at = NOW() WHERE id = ? AND submitted_at IS NULL')
                   ->execute([$sheet_id]);
            }
        } else {
            // Create new sheet
            $db->prepare(
                'INSERT INTO result_mark_sheets
                   (dept_id, program_id, semester, academic_year, curriculum_id,
                    subject_code, subject_title, credits, workflow_status, created_by, submitted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $dept_id,
                $program_id ?: null,
                $semester,
                $academic_year ?: null,
                $curriculum_id ?: null,
                $subject_code ?: null,
                $subject_title,
                $credits !== '' ? (float)$credits : null,
                $action === 'submit' ? 'submitted' : 'draft',
                $user['id'],
                $action === 'submit' ? date('Y-m-d H:i:s') : null,
            ]);
            $sheet_id = (int)$db->lastInsertId();
        }

        // ── Save student grades ────────────────────────────────────────────────
        $sids        = (array)($_POST['student_sid']  ?? []);
        $names       = (array)($_POST['student_name'] ?? []);
        $id_pks      = (array)($_POST['student_id_pk'] ?? []);
        $absents     = (array)($_POST['is_absent']    ?? []);
        $attendances = (array)($_POST['attendance']   ?? []);
        $class_tests = (array)($_POST['class_test']   ?? []);
        $mid_terms   = (array)($_POST['mid_term']     ?? []);
        $final_exams = (array)($_POST['final_exam']   ?? []);

        foreach ($sids as $idx => $sid) {
            $sid = trim($sid);
            if ($sid === '') continue;
            $name       = trim($names[$idx] ?? '');
            $id_pk      = (int)($id_pks[$idx] ?? 0);
            $is_absent  = isset($absents[$idx]) && $absents[$idx] == '1' ? 1 : 0;
            $att        = isset($attendances[$idx]) && $attendances[$idx] !== '' ? (float)$attendances[$idx] : null;
            $ct         = isset($class_tests[$idx]) && $class_tests[$idx] !== '' ? (float)$class_tests[$idx] : null;
            $mid        = isset($mid_terms[$idx])   && $mid_terms[$idx]   !== '' ? (float)$mid_terms[$idx]   : null;
            $fin        = isset($final_exams[$idx]) && $final_exams[$idx] !== '' ? (float)$final_exams[$idx] : null;

            wf_upsert_grade($sheet_id, $id_pk, $sid, $name, $is_absent, $att, $ct, $mid, $fin);
        }

        if ($action === 'submit') {
            flash_set('success', 'Mark sheet submitted for review.');
        } else {
            flash_set('success', 'Mark sheet saved as draft.');
        }
        redirect(APP_URL . '/results/index.php?tab=my_sheets');
    }

    save_old(compact('dept_id','program_id','semester','academic_year',
                     'curriculum_id','subject_code','subject_title','credits'));
}

require_once __DIR__ . '/../includes/header.php';

// Pre-fill values from sheet or old input
$v_dept_id       = $sheet ? $sheet['dept_id']       : old('dept_id');
$v_program_id    = $sheet ? $sheet['program_id']     : old('program_id');
$v_semester      = $sheet ? $sheet['semester']       : old('semester');
$v_academic_year = $sheet ? $sheet['academic_year']  : old('academic_year');
$v_curriculum_id = $sheet ? $sheet['curriculum_id']  : old('curriculum_id');
$v_subject_code  = $sheet ? $sheet['subject_code']   : old('subject_code');
$v_subject_title = $sheet ? $sheet['subject_title']  : old('subject_title');
$v_credits       = $sheet ? $sheet['credits']        : old('credits');
$is_edit         = $sheet !== null;
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item active"><?= $is_edit ? 'Edit Mark Sheet' : 'New Mark Sheet' ?></li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php flash_show(); ?>

<?php if ($sheet && $sheet['workflow_status'] === 'returned'): ?>
<div class="alert alert-warning">
    <strong><i class="fas fa-undo me-1"></i> Returned for revision</strong>
    <?php if ($sheet['return_remarks']): ?>
    — <?= h($sheet['return_remarks']) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="POST" id="markEntryForm" novalidate>
    <?= csrf_field() ?>
    <?php if ($sheet_id): ?>
    <input type="hidden" name="sheet_id" value="<?= $sheet_id ?>">
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── Left: Sheet Info ── -->
        <div class="col-lg-8">

            <!-- Department & Program -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-university me-2 text-muted"></i>Department &amp; Subject</h6>
                </div>
                <div class="card-body p-4">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department <span class="text-danger">*</span></label>
                            <select name="dept_id" id="dept_select" class="form-select" required>
                                <option value="">— Select Department —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (int)$v_dept_id === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Program</label>
                            <select name="program_id" id="prog_select" class="form-select" <?= $v_dept_id ? '' : 'disabled' ?>>
                                <option value="">— Select Program —</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Semester <span class="text-danger">*</span></label>
                            <select name="semester" id="semester_select" class="form-select" required>
                                <option value="">— Select Semester —</option>
                                <?php foreach ($semesters as $s): ?>
                                <option value="<?= h($s) ?>" <?= $v_semester === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Academic Year</label>
                            <input type="text" name="academic_year" class="form-control"
                                   value="<?= h($v_academic_year) ?>" placeholder="e.g. 2025-2026" maxlength="20">
                        </div>
                    </div>

                    <!-- Subject (select from curriculum or enter manually) -->
                    <div class="mb-3">
                        <label class="form-label fw-medium">Subject from Curriculum</label>
                        <select name="curriculum_id" id="curriculum_select" class="form-select" <?= ($v_dept_id && $v_program_id) ? '' : 'disabled' ?>>
                            <option value="">— Select Subject from Curriculum (optional) —</option>
                        </select>
                        <div class="form-text">Selecting a subject auto-fills code, title, and credits below.</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Subject Code</label>
                            <input type="text" name="subject_code" id="subject_code" class="form-control"
                                   value="<?= h($v_subject_code) ?>" placeholder="e.g. CSE-301" maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Subject Title <span class="text-danger">*</span></label>
                            <input type="text" name="subject_title" id="subject_title" class="form-control"
                                   value="<?= h($v_subject_title) ?>" placeholder="e.g. Data Structures" maxlength="300" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Credits</label>
                            <input type="number" name="credits" id="credits_input" class="form-control"
                                   value="<?= h($v_credits) ?>" min="0" max="10" step="0.5">
                        </div>
                    </div>

                </div>
            </div>

            <!-- Student Marks Table -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Student Marks</h6>
                    <div class="d-flex gap-2">
                        <button type="button" id="btn_load_students" class="btn btn-sm btn-outline-primary" style="border-radius:8px;" disabled>
                            <i class="fas fa-users me-1"></i> Load Students
                        </button>
                        <button type="button" id="btn_add_row" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
                            <i class="fas fa-plus me-1"></i> Add Row
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0" id="marks_table">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3" style="width:40px;">#</th>
                                    <th style="min-width:130px;">Student ID</th>
                                    <th style="min-width:180px;">Name</th>
                                    <th class="text-center" style="width:70px;">Absent</th>
                                    <th class="text-center" style="width:75px;">Att.<br><small class="text-muted">/10</small></th>
                                    <th class="text-center" style="width:75px;">CT<br><small class="text-muted">/10</small></th>
                                    <th class="text-center" style="width:75px;">Mid<br><small class="text-muted">/30</small></th>
                                    <th class="text-center" style="width:75px;">Final<br><small class="text-muted">/50</small></th>
                                    <th class="text-center" style="width:75px;">Total</th>
                                    <th class="text-center" style="width:60px;">Grade</th>
                                    <th style="width:40px;"></th>
                                </tr>
                            </thead>
                            <tbody id="marks_tbody">
                                <?php if (!empty($grades)): ?>
                                <?php foreach ($grades as $idx => $g): ?>
                                <tr class="grade-row">
                                    <td class="ps-3 row-num"><?= $idx + 1 ?></td>
                                    <td>
                                        <input type="hidden" name="student_id_pk[]" value="<?= (int)$g['student_id'] ?>">
                                        <input type="text" name="student_sid[]" class="form-control form-control-sm sid-input"
                                               value="<?= h($g['student_sid']) ?>" placeholder="Student ID" required>
                                    </td>
                                    <td>
                                        <input type="text" name="student_name[]" class="form-control form-control-sm"
                                               value="<?= h($g['student_name']) ?>" placeholder="Full Name">
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" name="is_absent[<?= $idx ?>]" class="form-check-input absent-chk"
                                               value="1" data-row="<?= $idx ?>" <?= $g['is_absent'] ? 'checked' : '' ?>>
                                        <input type="hidden" name="is_absent[<?= $idx ?>]" value="0" class="absent-hidden" <?= $g['is_absent'] ? 'disabled' : '' ?>>
                                    </td>
                                    <td><input type="number" name="attendance[]" class="form-control form-control-sm marks-input att-input"
                                               value="<?= $g['is_absent'] ? '' : h($g['attendance'] ?? '') ?>"
                                               min="0" max="10" step="0.5" <?= $g['is_absent'] ? 'disabled' : '' ?>></td>
                                    <td><input type="number" name="class_test[]" class="form-control form-control-sm marks-input ct-input"
                                               value="<?= $g['is_absent'] ? '' : h($g['class_test'] ?? '') ?>"
                                               min="0" max="10" step="0.5" <?= $g['is_absent'] ? 'disabled' : '' ?>></td>
                                    <td><input type="number" name="mid_term[]" class="form-control form-control-sm marks-input mid-input"
                                               value="<?= $g['is_absent'] ? '' : h($g['mid_term'] ?? '') ?>"
                                               min="0" max="30" step="0.5" <?= $g['is_absent'] ? 'disabled' : '' ?>></td>
                                    <td><input type="number" name="final_exam[]" class="form-control form-control-sm marks-input fin-input"
                                               value="<?= $g['is_absent'] ? '' : h($g['final_exam'] ?? '') ?>"
                                               min="0" max="50" step="0.5" <?= $g['is_absent'] ? 'disabled' : '' ?>></td>
                                    <td class="text-center total-cell fw-semibold">
                                        <?= $g['is_absent'] ? '<span class="text-danger">Abs</span>' : h($g['total_marks'] ?? '—') ?>
                                    </td>
                                    <td class="text-center grade-cell fw-semibold">
                                        <?= $g['is_absent'] ? '<span class="text-danger">F</span>' : h($g['letter_grade'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" style="border-radius:6px;">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr id="empty_row">
                                    <td colspan="11" class="text-center text-muted py-3">
                                        Use "Load Students" or "Add Row" to add students.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Right: Actions & Reference ── -->
        <div class="col-lg-4">
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-paper-plane me-2 text-muted"></i>Actions</h6>
                </div>
                <div class="card-body p-4">
                    <?php if ($sheet): ?>
                    <div class="mb-3 p-2 bg-light rounded small">
                        <div><strong>Status:</strong> <?= wf_status_badge($sheet['workflow_status']) ?></div>
                        <div class="mt-1"><strong>Created:</strong> <?= date('d M Y', strtotime($sheet['created_at'])) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="d-grid gap-2">
                        <button type="submit" name="action" value="save" class="btn btn-outline-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Draft
                        </button>
                        <button type="submit" name="action" value="submit"
                                class="btn btn-success" style="border-radius:10px;"
                                onclick="return confirm('Submit this mark sheet for review? You will not be able to edit it after submission.');">
                            <i class="fas fa-paper-plane me-1"></i> Submit for Review
                        </button>
                        <a href="<?= APP_URL ?>/results/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </div>
            </div>

            <!-- Grading reference -->
            <div class="card mb-4" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-star me-2 text-muted"></i>Mark Distribution</h6>
                </div>
                <div class="card-body p-3">
                    <table class="table table-sm mb-3" style="font-size:.8rem;">
                        <thead class="table-light"><tr><th>Component</th><th>Max</th></tr></thead>
                        <tbody>
                            <tr><td>Attendance</td><td>10</td></tr>
                            <tr><td>Class Test</td><td>10</td></tr>
                            <tr><td>Mid Term</td><td>30</td></tr>
                            <tr><td>Final Exam</td><td>50</td></tr>
                            <tr class="table-light fw-semibold"><td>Total</td><td>100</td></tr>
                        </tbody>
                    </table>
                    <table class="table table-sm mb-0" style="font-size:.8rem;">
                        <thead class="table-light"><tr><th>Marks</th><th>Grade</th><th>Point</th></tr></thead>
                        <tbody>
                            <?php foreach (wf_grading_scale() as [$min, $max, $letter, $point]):
                                $range = ($max === PHP_INT_MAX) ? '≥'.$min : $min.'–<'.$max; ?>
                            <tr><td><?= $range ?></td><td><strong><?= h($letter) ?></strong></td><td><?= number_format($point,2) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</form>

<!-- Row template (hidden) -->
<template id="row_template">
    <tr class="grade-row">
        <td class="ps-3 row-num"></td>
        <td>
            <input type="hidden" name="student_id_pk[]" value="0">
            <input type="text" name="student_sid[]" class="form-control form-control-sm sid-input" placeholder="Student ID" required>
        </td>
        <td><input type="text" name="student_name[]" class="form-control form-control-sm" placeholder="Full Name"></td>
        <td class="text-center">
            <input type="checkbox" class="form-check-input absent-chk" value="1">
        </td>
        <td><input type="number" name="attendance[]" class="form-control form-control-sm marks-input att-input" min="0" max="10" step="0.5"></td>
        <td><input type="number" name="class_test[]" class="form-control form-control-sm marks-input ct-input" min="0" max="10" step="0.5"></td>
        <td><input type="number" name="mid_term[]" class="form-control form-control-sm marks-input mid-input" min="0" max="30" step="0.5"></td>
        <td><input type="number" name="final_exam[]" class="form-control form-control-sm marks-input fin-input" min="0" max="50" step="0.5"></td>
        <td class="text-center total-cell fw-semibold">—</td>
        <td class="text-center grade-cell fw-semibold">—</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" style="border-radius:6px;"><i class="fas fa-times"></i></button></td>
    </tr>
</template>

<script>
(function () {
    var APP_URL       = '<?= APP_URL ?>';
    var deptSel       = document.getElementById('dept_select');
    var progSel       = document.getElementById('prog_select');
    var semSel        = document.getElementById('semester_select');
    var currSel       = document.getElementById('curriculum_select');
    var subCodeInput  = document.getElementById('subject_code');
    var subTitleInput = document.getElementById('subject_title');
    var creditsInput  = document.getElementById('credits_input');
    var tbody         = document.getElementById('marks_tbody');
    var emptyRow      = document.getElementById('empty_row');
    var btnLoad       = document.getElementById('btn_load_students');
    var btnAdd        = document.getElementById('btn_add_row');
    var rowTemplate   = document.getElementById('row_template');

    var savedDept    = <?= (int)$v_dept_id ?>;
    var savedProg    = <?= (int)$v_program_id ?>;
    var savedCurr    = <?= (int)$v_curriculum_id ?>;

    // Grading scale (client-side)
    var scale = [
        [80, Infinity, 'A+'], [75, 80, 'A'], [70, 75, 'A-'],
        [65, 70, 'B+'], [60, 65, 'B'], [55, 60, 'B-'],
        [50, 55, 'C+'], [45, 50, 'C'], [40, 45, 'D'], [0, 40, 'F']
    ];
    function computeGrade(total) {
        for (var i = 0; i < scale.length; i++) {
            if (total >= scale[i][0] && total < scale[i][1]) return scale[i][2];
        }
        return 'F';
    }

    function loadPrograms(deptId, selectId) {
        progSel.innerHTML = '<option value="">Loading…</option>';
        progSel.disabled = true;
        currSel.innerHTML = '<option value="">— Select Subject from Curriculum (optional) —</option>';
        currSel.disabled = true;
        if (!deptId) { progSel.innerHTML = '<option value="">— Select Program —</option>'; return; }
        fetch(APP_URL + '/results/get-programs.php?dept_id=' + deptId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                progSel.innerHTML = '<option value="">— Select Program —</option>';
                data.forEach(function (p) {
                    var o = document.createElement('option');
                    o.value = p.id; o.textContent = p.program_name;
                    if (p.id == selectId) o.selected = true;
                    progSel.appendChild(o);
                });
                progSel.disabled = false;
                if (selectId) loadSubjects(selectId, savedCurr);
            });
    }

    function loadSubjects(progId, selectId) {
        currSel.innerHTML = '<option value="">Loading subjects…</option>';
        currSel.disabled = true;
        if (!progId) { currSel.innerHTML = '<option value="">— Select Subject from Curriculum (optional) —</option>'; return; }
        fetch(APP_URL + '/results/get-subjects.php?program_id=' + progId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                currSel.innerHTML = '<option value="">— Select Subject from Curriculum (optional) —</option>';
                data.forEach(function (s) {
                    var o = document.createElement('option');
                    o.value = s.id;
                    o.textContent = (s.course_code ? s.course_code + ' – ' : '') + s.course_name;
                    o.dataset.code    = s.course_code || '';
                    o.dataset.title   = s.course_name;
                    o.dataset.credits = s.credit || '';
                    if (s.id == selectId) o.selected = true;
                    currSel.appendChild(o);
                });
                currSel.disabled = false;
                if (selectId) fillSubjectFromCurriculum();
            });
    }

    function fillSubjectFromCurriculum() {
        var sel = currSel.options[currSel.selectedIndex];
        if (!sel || !sel.value) return;
        subCodeInput.value  = sel.dataset.code  || '';
        subTitleInput.value = sel.dataset.title || '';
        creditsInput.value  = sel.dataset.credits || '';
    }

    deptSel.addEventListener('change', function () {
        loadPrograms(this.value, 0);
        btnLoad.disabled = true;
    });
    progSel.addEventListener('change', function () {
        loadSubjects(this.value, 0);
        btnLoad.disabled = !this.value;
    });
    currSel.addEventListener('change', fillSubjectFromCurriculum);

    // On page load with saved values
    if (savedDept) {
        loadPrograms(savedDept, savedProg);
        btnLoad.disabled = !savedProg;
    }

    // ── Load students ──────────────────────────────────────────────────────────
    btnLoad.addEventListener('click', function () {
        var deptId = deptSel.value;
        var progId = progSel.value;
        if (!progId) { alert('Please select a program first.'); return; }

        fetch(APP_URL + '/results/get-students.php?load_all=1&dept_id=' + deptId + '&program_id=' + progId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.length) { alert('No students found for this department / program.'); return; }
                // Clear existing rows
                clearRows();
                data.forEach(function (s) { appendRow(s.student_id, s.full_name, s.id); });
                renumberRows();
            });
    });

    function clearRows() {
        // Remove all grade rows (keep empty_row placeholder out)
        Array.from(tbody.querySelectorAll('tr.grade-row')).forEach(function (r) { r.remove(); });
        if (emptyRow) emptyRow.style.display = '';
    }

    function appendRow(sid, name, idPk) {
        if (emptyRow) emptyRow.style.display = 'none';
        var clone = rowTemplate.content.cloneNode(true);
        var tr    = clone.querySelector('tr');
        tr.querySelector('[name="student_id_pk[]"]').value  = idPk || 0;
        tr.querySelector('[name="student_sid[]"]').value    = sid  || '';
        tr.querySelector('[name="student_name[]"]').value   = name || '';
        // Wire up absent + total calc
        wireRow(tr);
        tbody.appendChild(tr);
    }

    // Add blank row
    btnAdd.addEventListener('click', function () {
        if (emptyRow) emptyRow.style.display = 'none';
        var clone = rowTemplate.content.cloneNode(true);
        var tr    = clone.querySelector('tr');
        wireRow(tr);
        tbody.appendChild(tr);
        renumberRows();
    });

    function wireRow(tr) {
        var absentChk   = tr.querySelector('.absent-chk');
        var markInputs  = tr.querySelectorAll('.marks-input');
        var totalCell   = tr.querySelector('.total-cell');
        var gradeCell   = tr.querySelector('.grade-cell');
        var removeBtn   = tr.querySelector('.btn-remove-row');

        function updateTotal() {
            if (absentChk && absentChk.checked) {
                totalCell.innerHTML = '<span class="text-danger">Abs</span>';
                gradeCell.innerHTML = '<span class="text-danger">F</span>';
                return;
            }
            var att  = parseFloat(tr.querySelector('.att-input').value)  || 0;
            var ct   = parseFloat(tr.querySelector('.ct-input').value)   || 0;
            var mid  = parseFloat(tr.querySelector('.mid-input').value)  || 0;
            var fin  = parseFloat(tr.querySelector('.fin-input').value)  || 0;
            var sum  = att + ct + mid + fin;
            if (sum <= 0) { totalCell.textContent = '—'; gradeCell.textContent = '—'; return; }
            totalCell.textContent = sum.toFixed(1);
            gradeCell.textContent = computeGrade(sum);
        }

        if (absentChk) {
            absentChk.addEventListener('change', function () {
                markInputs.forEach(function (inp) { inp.disabled = absentChk.checked; });
                updateTotal();
            });
        }
        markInputs.forEach(function (inp) { inp.addEventListener('input', updateTotal); });

        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                tr.remove();
                renumberRows();
                if (!tbody.querySelector('tr.grade-row') && emptyRow) emptyRow.style.display = '';
            });
        }
    }

    // Wire existing rows (edit mode)
    Array.from(tbody.querySelectorAll('tr.grade-row')).forEach(wireRow);

    function renumberRows() {
        Array.from(tbody.querySelectorAll('tr.grade-row')).forEach(function (tr, i) {
            var num = tr.querySelector('.row-num');
            if (num) num.textContent = i + 1;
        });
    }

    renumberRows();

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
