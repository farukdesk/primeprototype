<?php
/**
 * Mark Entry – Teacher creates / edits a mark sheet.
 *
 * Access: determined dynamically — user must be in the entry step of a chain
 * applicable to their dept scope. No hard-coded group names.
 *
 * URL: /results/mark-entry.php          (new sheet)
 *      /results/mark-entry.php?id=X     (edit draft/returned sheet)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/workflow-helpers.php';

auth_check();

if (!wf_can_create_sheet()) {
    flash_set('error', 'You are not assigned as an entry step in any active workflow chain.');
    redirect(APP_URL . '/results/index.php');
}

$user     = auth_user();
$sheet_id = (int)($_GET['id'] ?? 0);
$sheet    = null;
$grades   = [];
$errors   = [];
clear_old();

// ── Creatable chains for this user ────────────────────────────────────────────
// These determine which dept/programs the teacher can submit for.
$creatable = wf_get_creatable_chains(); // [{chain_id, dept_id, program_id, step_order, step_label}, ...]

// Build unique dept_ids from creatable chains
$creatable_dept_ids = array_unique(array_filter(array_column($creatable, 'dept_id')));
$dept_scope         = get_dept_scope();

if (empty($creatable_dept_ids) && !is_super_admin()) {
    flash_set('error', 'No active workflow chains are configured for your department.');
    redirect(APP_URL . '/results/index.php');
}

// Departments the user can submit for
if (is_super_admin()) {
    $departments = db()->query('SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
} else {
    $phs         = implode(',', array_fill(0, count($creatable_dept_ids), '?'));
    $ds          = db()->prepare("SELECT id, name FROM dept_departments WHERE is_active = 1 AND id IN ($phs) ORDER BY name ASC");
    $ds->execute($creatable_dept_ids);
    $departments = $ds->fetchAll();
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

    if (!$sheet) { flash_set('error', 'Sheet not found.'); redirect(APP_URL . '/results/index.php'); }

    // Owner check
    if ((int)$sheet['created_by'] !== (int)$user['id'] && !is_super_admin()) {
        flash_set('error', 'You can only edit your own mark sheets.');
        redirect(APP_URL . '/results/index.php');
    }
    // Status check – only draft or returned can be edited
    if (!in_array($sheet['workflow_status'], ['draft', 'returned'], true)) {
        flash_set('error', 'This sheet has been submitted and cannot be edited.');
        redirect(APP_URL . '/results/index.php?tab=my_sheets');
    }

    $grades = wf_get_grades($sheet_id);
}

$semesters  = wf_semester_list();
$page_title = $sheet ? 'Edit Mark Sheet' : 'New Mark Sheet';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action         = $_POST['action']         ?? 'save';
    $dept_id        = (int)($_POST['dept_id']        ?? 0);
    $program_id     = (int)($_POST['program_id']     ?? 0);
    $semester       = trim($_POST['semester']        ?? '');
    $academic_year  = trim($_POST['academic_year']   ?? '');
    $curriculum_id  = (int)($_POST['curriculum_id']  ?? 0);
    $subject_code   = trim($_POST['subject_code']    ?? '');
    $subject_title  = trim($_POST['subject_title']   ?? '');
    $credits        = trim($_POST['credits']         ?? '');

    if ($dept_id <= 0)        $errors[] = 'Department is required.';
    if ($semester === '')     $errors[] = 'Semester is required.';
    if ($subject_title === '') $errors[] = 'Subject title is required.';

    // Resolve chain on submit/save
    $chain = null;
    if ($action === 'submit') {
        $chain = wf_resolve_chain($dept_id, $program_id ?: null);
        if (!$chain) {
            $errors[] = 'No active workflow chain is configured for this department/program. Please contact the administrator.';
        }
    }

    if (empty($errors)) {
        $db = db();

        if ($sheet_id > 0) {
            // Update existing sheet header
            $db->prepare(
                'UPDATE result_mark_sheets SET
                   dept_id=?, program_id=?, semester=?, academic_year=?,
                   curriculum_id=?, subject_code=?, subject_title=?, credits=?,
                   updated_at=NOW()
                 WHERE id=?'
            )->execute([
                $dept_id, $program_id ?: null, $semester, $academic_year ?: null,
                $curriculum_id ?: null, $subject_code ?: null, $subject_title,
                $credits !== '' ? (float)$credits : null,
                $sheet_id,
            ]);
        } else {
            // Insert new sheet (always starts as draft)
            $db->prepare(
                'INSERT INTO result_mark_sheets
                   (dept_id, program_id, semester, academic_year, curriculum_id,
                    subject_code, subject_title, credits, workflow_status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,\'draft\',?)'
            )->execute([
                $dept_id, $program_id ?: null, $semester, $academic_year ?: null,
                $curriculum_id ?: null, $subject_code ?: null, $subject_title,
                $credits !== '' ? (float)$credits : null,
                $user['id'],
            ]);
            $sheet_id = (int)$db->lastInsertId();
            // Note: chain is not yet resolved for draft saves; history entry added at submit time
        }

        // ── Save grades ────────────────────────────────────────────────────────
        $sids        = (array)($_POST['student_sid']   ?? []);
        $names       = (array)($_POST['student_name']  ?? []);
        $id_pks      = (array)($_POST['student_id_pk'] ?? []);
        $absents     = (array)($_POST['is_absent']     ?? []);
        $attendances = (array)($_POST['attendance']    ?? []);
        $class_tests = (array)($_POST['class_test']    ?? []);
        $mid_terms   = (array)($_POST['mid_term']      ?? []);
        $final_exams = (array)($_POST['final_exam']    ?? []);

        foreach ($sids as $idx => $sid) {
            $sid = trim($sid);
            if ($sid === '') continue;
            wf_upsert_grade(
                $sheet_id,
                (int)($id_pks[$idx] ?? 0),
                $sid,
                trim($names[$idx] ?? ''),
                ($absents[$idx] ?? '0') === '1' ? 1 : 0,
                isset($attendances[$idx]) && $attendances[$idx] !== '' ? (float)$attendances[$idx] : null,
                isset($class_tests[$idx]) && $class_tests[$idx]  !== '' ? (float)$class_tests[$idx]  : null,
                isset($mid_terms[$idx])   && $mid_terms[$idx]    !== '' ? (float)$mid_terms[$idx]    : null,
                isset($final_exams[$idx]) && $final_exams[$idx]  !== '' ? (float)$final_exams[$idx]  : null
            );
        }

        // ── Submit: advance to first approver step ────────────────────────────
        if ($action === 'submit' && $chain) {
            $entry_step = wf_get_entry_step((int)$chain['id']);
            $next_step  = $entry_step ? wf_get_next_step((int)$chain['id'], (int)$entry_step['step_order']) : null;

            if ($next_step) {
                $db->prepare(
                    "UPDATE result_mark_sheets
                     SET chain_id=?, current_step_order=?, workflow_status='pending', updated_at=NOW()
                     WHERE id=?"
                )->execute([$chain['id'], $next_step['step_order'], $sheet_id]);
                _wf_log($sheet_id, (int)($entry_step['step_order'] ?? 1),
                        $entry_step['step_label'] ?? 'Entry',
                        (int)($entry_step['group_id'] ?? 0),
                        'submitted', (int)$user['id']);
            } else {
                // Chain has only entry step → publish immediately (unusual config but handle it)
                $db->prepare(
                    "UPDATE result_mark_sheets
                     SET chain_id=?, current_step_order=?, workflow_status='published', updated_at=NOW()
                     WHERE id=?"
                )->execute([$chain['id'], $entry_step['step_order'] ?? 1, $sheet_id]);
                _wf_log($sheet_id, (int)($entry_step['step_order'] ?? 1),
                        $entry_step['step_label'] ?? 'Entry',
                        (int)($entry_step['group_id'] ?? 0),
                        'published', (int)$user['id']);
            }

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
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php flash_show(); ?>

<?php if ($sheet && $sheet['workflow_status'] === 'returned'): ?>
<?php $history = wf_get_sheet_history($sheet_id); $last_return = null;
foreach (array_reverse($history) as $h) { if ($h['action'] === 'returned') { $last_return = $h; break; } }
?>
<div class="alert alert-warning">
    <strong><i class="fas fa-undo me-1"></i> Returned for revision</strong>
    <?php if ($last_return && $last_return['remarks']): ?>
    — <?= h($last_return['remarks']) ?>
    <small class="text-muted ms-2">by <?= h($last_return['actor_name'] ?? 'reviewer') ?> on <?= date('d M Y', strtotime($last_return['acted_at'])) ?></small>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="POST" id="markEntryForm" novalidate>
    <?= csrf_field() ?>

    <div class="row g-4">

        <!-- Left: Sheet Info -->
        <div class="col-lg-8">
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
                            <select name="semester" class="form-select" required>
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

                    <div class="mb-3">
                        <label class="form-label fw-medium">Subject from Curriculum</label>
                        <select name="curriculum_id" id="curriculum_select" class="form-select"
                                <?= ($v_dept_id && $v_program_id) ? '' : 'disabled' ?>>
                            <option value="">— Select from Curriculum (optional) —</option>
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
                        <button type="button" id="btn_load_students" class="btn btn-sm btn-outline-primary"
                                style="border-radius:8px;" disabled>
                            <i class="fas fa-users me-1"></i> Load Students
                        </button>
                        <button type="button" id="btn_add_row" class="btn btn-sm btn-outline-secondary"
                                style="border-radius:8px;">
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
                                <tr class="grade-row <?= $g['is_absent'] ? 'table-warning' : '' ?>">
                                    <td class="ps-3 row-num"><?= $idx + 1 ?></td>
                                    <td>
                                        <input type="hidden" name="student_id_pk[]" value="<?= (int)$g['student_id'] ?>">
                                        <input type="text" name="student_sid[]" class="form-control form-control-sm"
                                               value="<?= h($g['student_sid']) ?>" placeholder="Student ID" required>
                                    </td>
                                    <td>
                                        <input type="text" name="student_name[]" class="form-control form-control-sm"
                                               value="<?= h($g['student_name']) ?>" placeholder="Full Name">
                                    </td>
                                    <td class="text-center">
                                        <input type="hidden" name="is_absent[]" class="absent-flag" value="<?= $g['is_absent'] ? '1' : '0' ?>">
                                        <input type="checkbox" class="form-check-input absent-chk" <?= $g['is_absent'] ? 'checked' : '' ?>>
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
                                    <td class="text-center grade-cell fw-bold">
                                        <?= $g['is_absent'] ? '<span class="text-danger">F</span>' : h($g['letter_grade'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row"
                                                style="border-radius:6px;"><i class="fas fa-times"></i></button>
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

        <!-- Right: Actions & Reference -->
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
                        <button type="submit" name="action" value="submit" id="btn_submit"
                                class="btn btn-success" style="border-radius:10px;"
                                onclick="return confirm('Submit this mark sheet for review? It will be locked for editing after submission.');">
                            <i class="fas fa-paper-plane me-1"></i> Submit for Review
                        </button>
                        <a href="<?= APP_URL ?>/results/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>

                    <!-- Chain info (shown once dept is selected) -->
                    <div id="chain_info" class="mt-3 p-2 border rounded small text-muted" style="display:none;">
                        <i class="fas fa-sitemap me-1"></i>
                        <span id="chain_info_text">Workflow chain will be shown here.</span>
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
                            <tr class="fw-semibold"><td>Total</td><td>100</td></tr>
                        </tbody>
                    </table>
                    <table class="table table-sm mb-0" style="font-size:.78rem;">
                        <thead class="table-light"><tr><th>Marks</th><th>Grade</th><th>GPA</th></tr></thead>
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
            <input type="text" name="student_sid[]" class="form-control form-control-sm" placeholder="Student ID" required>
        </td>
        <td><input type="text" name="student_name[]" class="form-control form-control-sm" placeholder="Full Name"></td>
        <td class="text-center">
            <input type="hidden" name="is_absent[]" class="absent-flag" value="0">
            <input type="checkbox" class="form-check-input absent-chk">
        </td>
        <td><input type="number" name="attendance[]" class="form-control form-control-sm marks-input att-input" min="0" max="10" step="0.5"></td>
        <td><input type="number" name="class_test[]" class="form-control form-control-sm marks-input ct-input"  min="0" max="10" step="0.5"></td>
        <td><input type="number" name="mid_term[]"   class="form-control form-control-sm marks-input mid-input" min="0" max="30" step="0.5"></td>
        <td><input type="number" name="final_exam[]" class="form-control form-control-sm marks-input fin-input" min="0" max="50" step="0.5"></td>
        <td class="text-center total-cell fw-semibold">—</td>
        <td class="text-center grade-cell fw-bold">—</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" style="border-radius:6px;"><i class="fas fa-times"></i></button></td>
    </tr>
</template>

<?php
// Build JS map: dept_id → [{chain_id, program_id, chain_name, step_label}]
$chain_map = [];
foreach ($creatable as $cr) {
    $chain_map[$cr['dept_id'] ?? 'global'][] = $cr;
}
?>
<script>
(function () {
    var APP_URL    = '<?= APP_URL ?>';
    var chainMap   = <?= json_encode($chain_map) ?>;
    var deptSel    = document.getElementById('dept_select');
    var progSel    = document.getElementById('prog_select');
    var currSel    = document.getElementById('curriculum_select');
    var subCode    = document.getElementById('subject_code');
    var subTitle   = document.getElementById('subject_title');
    var credits    = document.getElementById('credits_input');
    var tbody      = document.getElementById('marks_tbody');
    var emptyRow   = document.getElementById('empty_row');
    var btnLoad    = document.getElementById('btn_load_students');
    var btnAdd     = document.getElementById('btn_add_row');
    var template   = document.getElementById('row_template');
    var chainInfo  = document.getElementById('chain_info');
    var chainText  = document.getElementById('chain_info_text');

    var savedDept = <?= (int)$v_dept_id ?>;
    var savedProg = <?= (int)$v_program_id ?>;
    var savedCurr = <?= (int)$v_curriculum_id ?>;

    var scale = [[80,Infinity,'A+'],[75,80,'A'],[70,75,'A-'],[65,70,'B+'],
                 [60,65,'B'],[55,60,'B-'],[50,55,'C+'],[45,50,'C'],[40,45,'D'],[0,40,'F']];
    function grade(t) { for (var i=0;i<scale.length;i++) if (t>=scale[i][0]&&t<scale[i][1]) return scale[i][2]; return 'F'; }

    // Show chain info for selected dept
    function updateChainInfo(deptId, progId) {
        var chains = chainMap[deptId] || chainMap['global'] || [];
        if (!chains.length) { chainInfo.style.display = 'none'; return; }
        // Find best match
        var best = chains.find(function(c) { return (c.program_id == progId) || (!c.program_id); });
        if (!best) best = chains[0];
        chainInfo.style.display = '';
        chainText.textContent = 'Chain: ' + best.chain_name + ' — Entry: ' + best.step_label;
    }

    function loadPrograms(deptId, selectId) {
        progSel.innerHTML = '<option value="">— Select Program —</option>';
        progSel.disabled  = true;
        currSel.innerHTML = '<option value="">—</option>';
        currSel.disabled  = true;
        btnLoad.disabled  = true;
        if (!deptId) { chainInfo.style.display='none'; return; }
        fetch(APP_URL + '/results/get-programs.php?dept_id=' + deptId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                data.forEach(function(p) {
                    var o = document.createElement('option');
                    o.value = p.id; o.textContent = p.program_name;
                    if (p.id == selectId) o.selected = true;
                    progSel.appendChild(o);
                });
                progSel.disabled = false;
                updateChainInfo(deptId, selectId);
                if (selectId) { loadSubjects(selectId, savedCurr); btnLoad.disabled = false; }
            });
    }

    function loadSubjects(progId, selectId) {
        currSel.innerHTML = '<option value="">— Select from Curriculum (optional) —</option>';
        currSel.disabled  = true;
        if (!progId) return;
        fetch(APP_URL + '/results/get-subjects.php?program_id=' + progId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                data.forEach(function(s) {
                    var o = document.createElement('option');
                    o.value = s.id;
                    o.textContent = (s.course_code ? s.course_code + ' – ' : '') + s.course_name;
                    o.dataset.code = s.course_code || ''; o.dataset.title = s.course_name; o.dataset.credits = s.credit || '';
                    if (s.id == selectId) o.selected = true;
                    currSel.appendChild(o);
                });
                currSel.disabled = false;
                if (selectId) fillFromCurriculum();
            });
    }

    function fillFromCurriculum() {
        var sel = currSel.options[currSel.selectedIndex];
        if (!sel || !sel.value) return;
        subCode.value   = sel.dataset.code    || '';
        subTitle.value  = sel.dataset.title   || '';
        credits.value   = sel.dataset.credits || '';
    }

    deptSel.addEventListener('change', function() { loadPrograms(this.value, 0); updateChainInfo(this.value, 0); });
    progSel.addEventListener('change', function() { loadSubjects(this.value, 0); btnLoad.disabled = !this.value; updateChainInfo(deptSel.value, this.value); });
    currSel.addEventListener('change', fillFromCurriculum);
    if (savedDept) loadPrograms(savedDept, savedProg);

    // ── Load students ──────────────────────────────────────────────────────────
    btnLoad.addEventListener('click', function() {
        var deptId = deptSel.value, progId = progSel.value;
        if (!progId) { alert('Please select a program first.'); return; }
        fetch(APP_URL + '/results/get-students.php?load_all=1&dept_id=' + deptId + '&program_id=' + progId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.length) { alert('No students found for this program.'); return; }
                clearRows();
                data.forEach(function(s) { appendRow(s.student_id, s.full_name, s.id); });
                renumber();
            });
    });

    function clearRows() {
        Array.from(tbody.querySelectorAll('tr.grade-row')).forEach(function(r) { r.remove(); });
        if (emptyRow) emptyRow.style.display = '';
    }
    function appendRow(sid, name, idPk) {
        if (emptyRow) emptyRow.style.display = 'none';
        var clone = template.content.cloneNode(true);
        var tr    = clone.querySelector('tr');
        tr.querySelector('[name="student_id_pk[]"]').value = idPk || 0;
        tr.querySelector('[name="student_sid[]"]').value   = sid  || '';
        tr.querySelector('[name="student_name[]"]').value  = name || '';
        wireRow(tr);
        tbody.appendChild(tr);
    }
    btnAdd.addEventListener('click', function() {
        if (emptyRow) emptyRow.style.display = 'none';
        var clone = template.content.cloneNode(true);
        wireRow(clone.querySelector('tr'));
        tbody.appendChild(clone);
        renumber();
    });

    function wireRow(tr) {
        var flag   = tr.querySelector('.absent-flag');
        var chk    = tr.querySelector('.absent-chk');
        var inputs = tr.querySelectorAll('.marks-input');
        var total  = tr.querySelector('.total-cell');
        var grd    = tr.querySelector('.grade-cell');

        function updateTotal() {
            if (chk && chk.checked) { total.innerHTML='<span class="text-danger">Abs</span>'; grd.innerHTML='<span class="text-danger">F</span>'; return; }
            var att=parseFloat(tr.querySelector('.att-input').value)||0;
            var ct =parseFloat(tr.querySelector('.ct-input').value) ||0;
            var mid=parseFloat(tr.querySelector('.mid-input').value)||0;
            var fin=parseFloat(tr.querySelector('.fin-input').value)||0;
            var sum=att+ct+mid+fin;
            if(!sum&&!att&&!ct&&!mid&&!fin){total.textContent='—';grd.textContent='—';return;}
            total.textContent=sum.toFixed(1); grd.textContent=grade(sum);
        }
        if (chk && flag) {
            chk.addEventListener('change', function() {
                flag.value = this.checked ? '1' : '0';
                inputs.forEach(function(i) { i.disabled = chk.checked; });
                updateTotal();
            });
        }
        inputs.forEach(function(i) { i.addEventListener('input', updateTotal); });
        var btn = tr.querySelector('.btn-remove-row');
        if (btn) btn.addEventListener('click', function() {
            tr.remove(); renumber();
            if (!tbody.querySelector('tr.grade-row') && emptyRow) emptyRow.style.display = '';
        });
    }

    // Wire existing rows
    Array.from(tbody.querySelectorAll('tr.grade-row')).forEach(wireRow);

    function renumber() {
        Array.from(tbody.querySelectorAll('tr.grade-row')).forEach(function(tr, i) {
            var n = tr.querySelector('.row-num'); if (n) n.textContent = i + 1;
        });
    }
    renumber();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
