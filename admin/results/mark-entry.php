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
require_once __DIR__ . '/helpers.php';
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

// ── Auto-detect faculty's department ──────────────────────────────────────────
// Prefer the dept_id from faculty_profiles; fall back to the single dept in
// their creatable chains if unambiguous.
$faculty_dept_id = 0;
if (!is_super_admin()) {
    try {
        $fp_stmt = db()->prepare('SELECT dept_id FROM faculty_profiles WHERE user_id = ? LIMIT 1');
        $fp_stmt->execute([$user['id']]);
        $fp_row = $fp_stmt->fetch();
        if ($fp_row && $fp_row['dept_id']) {
            $faculty_dept_id = (int)$fp_row['dept_id'];
        }
    } catch (Throwable $_e) {}
    // If faculty_profiles has no record, use the only chain dept (if unambiguous)
    if (!$faculty_dept_id && count($departments) === 1) {
        $faculty_dept_id = (int)$departments[0]['id'];
    }
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

$page_title = $sheet ? 'Edit Mark Sheet' : 'New Mark Sheet';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action         = $_POST['action']         ?? 'save';
    $dept_id        = (int)($_POST['dept_id']        ?? 0);
    $program_id     = (int)($_POST['program_id']     ?? 0);
    $batch          = trim($_POST['batch']           ?? '');
    $curriculum_id  = (int)($_POST['curriculum_id']  ?? 0);
    $subject_code   = trim($_POST['subject_code']    ?? '');
    $subject_title  = trim($_POST['subject_title']   ?? '');
    $credits        = trim($_POST['credits']         ?? '');

    if ($dept_id <= 0)        $errors[] = 'Department is required.';
    if ($batch === '')        $errors[] = 'Batch is required.';
    if ($subject_title === '') $errors[] = 'Subject title is required.';

    // Faculty: server-side check that the chosen curriculum subject is assigned to them
    if ($curriculum_id > 0 && !is_super_admin() && !rm_can_create() && !rm_is_staff()) {
        $fac_uid = (int)$user['id'];
        $fa_stmt = db()->prepare(
            "SELECT COUNT(*) FROM faculty_subject_assignments fsa
              WHERE fsa.faculty_user_id = ? AND fsa.course_id = ? AND fsa.status = 'approved'"
        );
        $fa_stmt->execute([$fac_uid, $curriculum_id]);
        $is_authorized = (int)$fa_stmt->fetchColumn() > 0;
        if (!$is_authorized) {
            $fa2 = db()->prepare(
                "SELECT COUNT(*) FROM course_curriculum cc
                   JOIN dept_faculty df ON df.id = cc.assigned_faculty_id
                  WHERE df.user_id = ? AND cc.id = ?"
            );
            $fa2->execute([$fac_uid, $curriculum_id]);
            $is_authorized = (int)$fa2->fetchColumn() > 0;
        }
        if (!$is_authorized) {
            $errors[] = 'You are not authorized to submit results for this subject.';
        }
    }

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
                   dept_id=?, program_id=?, semester=?,
                   curriculum_id=?, subject_code=?, subject_title=?, credits=?,
                   updated_at=NOW()
                 WHERE id=?'
            )->execute([
                $dept_id, $program_id ?: null, $batch,
                $curriculum_id ?: null, $subject_code ?: null, $subject_title,
                $credits !== '' ? (float)$credits : null,
                $sheet_id,
            ]);
        } else {
            // Insert new sheet (always starts as draft)
            $db->prepare(
                'INSERT INTO result_mark_sheets
                   (dept_id, program_id, semester, curriculum_id,
                    subject_code, subject_title, credits, workflow_status, created_by)
                 VALUES (?,?,?,?,?,?,?,\'draft\',?)'
            )->execute([
                $dept_id, $program_id ?: null, $batch,
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

    save_old(compact('dept_id','program_id','batch',
                     'curriculum_id','subject_code','subject_title','credits'));
}

require_once __DIR__ . '/../includes/header.php';

$v_dept_id       = $sheet ? $sheet['dept_id']       : (old('dept_id') ?: ($faculty_dept_id ?: null));
$v_program_id    = $sheet ? $sheet['program_id']     : old('program_id');
$v_batch         = $sheet ? $sheet['semester']       : old('batch');
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

    <div class="row g-4 mb-4">

        <!-- Sheet Info -->
        <div class="col-lg-9">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-university me-2 text-muted"></i>Department &amp; Subject</h6>
                </div>
                <div class="card-body p-4">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department <span class="text-danger">*</span></label>
                            <?php if (!is_super_admin() && $faculty_dept_id): ?>
                            <?php $fd = array_values(array_filter($departments, fn($d) => (int)$d['id'] === $faculty_dept_id))[0] ?? null; ?>
                            <input type="hidden" name="dept_id" id="dept_select" value="<?= $faculty_dept_id ?>">
                            <input type="text" class="form-control" value="<?= h($fd['name'] ?? '') ?>" readonly
                                   style="background:#f8f9fa;">
                            <?php else: ?>
                            <select name="dept_id" id="dept_select" class="form-select" required>
                                <option value="">— Select Department —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (int)$v_dept_id === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
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
                            <label class="form-label fw-medium">Batch <span class="text-danger">*</span></label>
                            <input type="text" name="batch" id="batch_input" class="form-control"
                                   value="<?= h($v_batch ?? '') ?>" placeholder="Type or select batch (e.g. 52nd)" maxlength="50"
                                   list="batch_list" required>
                            <datalist id="batch_list"></datalist>
                            <div class="form-text">Click the field or start typing to filter available batches.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Subject <?= (!is_super_admin() && !rm_can_create() && !rm_is_staff()) ? '<span class="badge bg-info text-dark ms-1" style="font-size:.7rem;">Your Subjects</span>' : '' ?> <span class="text-danger">*</span></label>
                        <select name="curriculum_id" id="curriculum_select" class="form-select"
                                <?= ($v_dept_id && $v_program_id) ? '' : 'disabled' ?>>
                            <option value="">— Select Subject —</option>
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
        </div><!-- /col-lg-9 -->

        <!-- Actions & Reference -->
        <div class="col-lg-3">
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
                    <div id="dist_ref_wrap">
                        <table class="table table-sm mb-3" id="dist_ref_table" style="font-size:.8rem;">
                            <thead class="table-light"><tr><th>Component</th><th>Max</th></tr></thead>
                            <tbody id="dist_ref_tbody">
                                <tr><td>Attendance</td><td>10</td></tr>
                                <tr><td>Class Test</td><td>10</td></tr>
                                <tr><td>Mid Term</td><td>30</td></tr>
                                <tr><td>Final Exam</td><td>50</td></tr>
                                <tr class="fw-semibold"><td>Total</td><td>100</td></tr>
                            </tbody>
                        </table>
                        <small class="text-muted" id="dist_ref_note" style="display:none;">
                            <i class="fas fa-info-circle me-1"></i>Distribution from course curriculum.
                        </small>
                    </div>
                    <table class="table table-sm mb-0" style="font-size:.78rem;">
                        <thead class="table-light"><tr><th>Marks</th><th>Grade</th><th>GPA</th></tr></thead>
                        <tbody>
                            <?php foreach (wf_grading_scale() as [$min, $max, $letter, $point]):
                                $range = ($max === PHP_INT_MAX) ? '≥'.$min : $min.'–<'.$max; ?>
                            <tr><td><?= $range ?></td><td><strong><?= h($letter) ?></strong></td><td><?= number_format($point,2) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div><!-- /dist_ref_wrap -->
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
                <button type="button" id="btn_add_other_batch" class="btn btn-sm btn-outline-warning"
                        style="border-radius:8px;" title="Add a student from a different batch">
                    <i class="fas fa-user-plus me-1"></i> Other Batch
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
                            <th class="ps-3" style="width:50px;">#</th>
                            <th style="min-width:150px;">Student ID</th>
                            <th style="min-width:220px;">Name</th>
                            <th class="text-center" style="width:85px;">Absent</th>
                            <th class="text-center" id="th_att" style="width:100px;">Att.<br><small class="text-muted">/10</small></th>
                            <th class="text-center" id="th_ct"  style="width:100px;">CT<br><small class="text-muted">/10</small></th>
                            <th class="text-center" id="th_mid" style="width:100px;">Mid<br><small class="text-muted">/30</small></th>
                            <th class="text-center" id="th_fin" style="width:110px;">Final<br><small class="text-muted">/50</small></th>
                            <th class="text-center" style="width:100px;">Total</th>
                            <th class="text-center" style="width:80px;">Grade</th>
                            <th style="width:50px;"></th>
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
                                Enter a batch and select a program — students load automatically. Use "Load Students" to reload or "Add Row" to add manually.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div id="other_batch_panel" class="px-3 pb-3" style="display:none;">
            <div class="card border-warning" style="border-radius:10px;">
                <div class="card-body p-3">
                    <h6 class="fw-semibold mb-2" style="font-size:.85rem;">
                        <i class="fas fa-user-plus me-1 text-warning"></i>
                        Add Student from Another Batch
                    </h6>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Batch</label>
                            <input type="text" id="other_batch_val" class="form-control form-control-sm"
                                   list="other_batch_datalist" placeholder="e.g. 51st" autocomplete="off">
                            <datalist id="other_batch_datalist"></datalist>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1">Search by Student ID or Name</label>
                            <input type="text" id="other_student_q" class="form-control form-control-sm"
                                   placeholder="Type at least 2 charactersâ¦" autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <button type="button" id="btn_other_search" class="btn btn-warning btn-sm w-100">
                                <i class="fas fa-search me-1"></i> Search
                            </button>
                        </div>
                    </div>
                    <div id="other_student_results" class="mt-2"></div>
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
    var APP_URL       = '<?= APP_URL ?>';
    var chainMap      = <?= json_encode($chain_map) ?>;
    var deptSel       = document.getElementById('dept_select');
    var progSel       = document.getElementById('prog_select');
    var currSel       = document.getElementById('curriculum_select');
    var batchInput    = document.getElementById('batch_input');
    var batchList     = document.getElementById('batch_list');
    var subCode       = document.getElementById('subject_code');
    var subTitle      = document.getElementById('subject_title');
    var credits       = document.getElementById('credits_input');
    var tbody         = document.getElementById('marks_tbody');
    var emptyRow      = document.getElementById('empty_row');
    var btnLoad       = document.getElementById('btn_load_students');
    var btnAdd        = document.getElementById('btn_add_row');
    var template      = document.getElementById('row_template');
    var chainInfo     = document.getElementById('chain_info');
    var chainText     = document.getElementById('chain_info_text');

    var savedDept     = <?= (int)$v_dept_id ?>;
    var savedProg     = <?= (int)$v_program_id ?>;
    var savedCurr     = <?= (int)$v_curriculum_id ?>;
    var savedBatch    = <?= json_encode($v_batch ?? '') ?>;
    var facultyDeptId = <?= $faculty_dept_id ?>;

    // ── Mark distribution defaults (used when curriculum has no config) ─────────
    var defaultDist = [
        { name: 'Att.',  max: 10 },
        { name: 'CT',    max: 10 },
        { name: 'Mid',   max: 30 },
        { name: 'Final', max: 50 },
    ];
    var currentDist = defaultDist.slice();

    var scale = [[80,Infinity,'A+'],[75,80,'A'],[70,75,'A-'],[65,70,'B+'],
                 [60,65,'B'],[55,60,'B-'],[50,55,'C+'],[45,50,'C'],[40,45,'D'],[0,40,'F']];
    function grade(t) { for (var i=0;i<scale.length;i++) if (t>=scale[i][0]&&t<scale[i][1]) return scale[i][2]; return 'F'; }

    // ── Apply mark distribution (from curriculum or defaults) ─────────────────
    function applyMarkDistribution(dists) {
        currentDist = defaultDist.slice();
        var fromCurriculum = dists && dists.length > 0;
        if (fromCurriculum) {
            dists.slice(0, 4).forEach(function(d, i) {
                currentDist[i] = { name: d.distribution_name, max: parseFloat(d.max_marks) };
            });
        }
        var thIds    = ['th_att', 'th_ct', 'th_mid', 'th_fin'];
        var inpCls   = ['.att-input', '.ct-input', '.mid-input', '.fin-input'];
        thIds.forEach(function(id, i) {
            var th = document.getElementById(id);
            if (th) th.innerHTML = currentDist[i].name + '<br><small class="text-muted">/' + currentDist[i].max + '</small>';
        });
        inpCls.forEach(function(cls, i) {
            document.querySelectorAll(cls).forEach(function(inp) { inp.max = currentDist[i].max; });
            var tplInp = template ? template.content.querySelector(cls) : null;
            if (tplInp) tplInp.max = currentDist[i].max;
        });
        // Update reference panel
        var refNote = document.getElementById('dist_ref_note');
        var refBody = document.getElementById('dist_ref_tbody');
        if (refBody) {
            var total = 0;
            currentDist.forEach(function(d) { total += d.max; });
            var rows = '';
            currentDist.forEach(function(d) { rows += '<tr><td>' + d.name + '</td><td>' + d.max + '</td></tr>'; });
            rows += '<tr class="fw-semibold"><td>Total</td><td>' + total + '</td></tr>';
            refBody.innerHTML = rows;
        }
        if (refNote) refNote.style.display = fromCurriculum ? '' : 'none';
    }

    function loadMarkDistribution(curriculumId) {
        if (!curriculumId) { applyMarkDistribution([]); return; }
        fetch(APP_URL + '/results/get-mark-distribution.php?curriculum_id=' + curriculumId)
            .then(function(r) { return r.json(); })
            .then(function(data) { applyMarkDistribution(data); });
    }

    // ── Batch datalist population ─────────────────────────────────────────────
    function loadBatches(deptId, progId) {
        if (!batchList) return;
        var url = APP_URL + '/results/get-batches.php?dept_id=' + (deptId || 0)
                          + '&program_id=' + (progId || 0);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(batches) {
                batchList.innerHTML = '';
                batches.forEach(function(b) {
                    var opt = document.createElement('option');
                    opt.value = b;
                    batchList.appendChild(opt);
                });
            });
    }

    // ── Load all batches for "other batch" datalist ───────────────────────────
    function loadOtherBatchList(deptId) {
        var dl = document.getElementById('other_batch_datalist');
        if (!dl || !deptId) return;
        fetch(APP_URL + '/results/get-batches.php?dept_id=' + deptId)
            .then(function(r) { return r.json(); })
            .then(function(batches) {
                dl.innerHTML = '';
                batches.forEach(function(b) {
                    var opt = document.createElement('option');
                    opt.value = b;
                    dl.appendChild(opt);
                });
            });
    }

    // Show chain info for selected dept
    function updateChainInfo(deptId, progId) {
        var chains = chainMap[deptId] || chainMap['global'] || [];
        if (!chains.length) { chainInfo.style.display = 'none'; return; }
        var best = chains.find(function(c) { return (c.program_id == progId) || (!c.program_id); });
        if (!best) best = chains[0];
        chainInfo.style.display = '';
        chainText.textContent = 'Chain: ' + best.chain_name + ' — Entry: ' + best.step_label;
    }

    function getDeptId() {
        return deptSel ? deptSel.value : facultyDeptId;
    }

    function loadPrograms(deptId, selectId) {
        progSel.innerHTML = '<option value="">— Select Program —</option>';
        progSel.disabled  = true;
        currSel.innerHTML = '<option value="">— Select Subject —</option>';
        currSel.disabled  = true;
        if (btnLoad) btnLoad.disabled = true;
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
                loadBatches(deptId, selectId);
                loadOtherBatchList(deptId);
                if (selectId) {
                    loadFacultySubjects(selectId, savedCurr);
                    if (btnLoad) btnLoad.disabled = false;
                    if (savedBatch) loadStudentsByBatch(false);
                }
            });
    }

    // Load subjects from faculty profile (or all for admin)
    function loadFacultySubjects(progId, selectId) {
        currSel.innerHTML = '<option value="">— Select Subject —</option>';
        currSel.disabled  = true;
        if (!progId) return;
        loadBatches(getDeptId(), progId);
        fetch(APP_URL + '/results/get-faculty-subjects.php?program_id=' + progId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.length) {
                    var o = document.createElement('option');
                    o.value = ''; o.textContent = '— No subjects assigned —';
                    o.disabled = true;
                    currSel.appendChild(o);
                }
                data.forEach(function(s) {
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
                if (selectId) fillFromCurriculum();
            });
    }

    function fillFromCurriculum() {
        var sel = currSel.options[currSel.selectedIndex];
        if (!sel || !sel.value) { applyMarkDistribution([]); return; }
        subCode.value  = sel.dataset.code    || '';
        subTitle.value = sel.dataset.title   || '';
        credits.value  = sel.dataset.credits || '';
        loadMarkDistribution(sel.value);
    }

    // ── Load students by batch ─────────────────────────────────────────────────
    function loadStudentsByBatch(showAlert) {
        var deptId = getDeptId();
        var progId = progSel.value;
        var batch  = batchInput ? batchInput.value.trim() : '';
        if (!progId || !batch) return;
        var url = APP_URL + '/results/get-students.php?load_all=1'
            + '&dept_id='    + encodeURIComponent(deptId)
            + '&program_id=' + encodeURIComponent(progId)
            + '&batch='      + encodeURIComponent(batch);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.length) {
                    if (showAlert) alert('No students found for batch "' + batch + '" in this program.');
                    return;
                }
                clearRows();
                data.forEach(function(s) { appendRow(s.student_id, s.full_name, s.id); });
                renumber();
            });
    }

    // Dept change (only for admin – faculty has a hidden input)
    if (deptSel && deptSel.tagName === 'SELECT') {
        deptSel.addEventListener('change', function() {
            loadPrograms(this.value, 0);
            updateChainInfo(this.value, 0);
            loadOtherBatchList(this.value);
        });
    }

    progSel.addEventListener('change', function() {
        loadFacultySubjects(this.value, 0);
        if (btnLoad) btnLoad.disabled = !this.value;
        updateChainInfo(getDeptId(), this.value);
        loadBatches(getDeptId(), this.value);
        if (this.value && batchInput && batchInput.value.trim()) loadStudentsByBatch(false);
    });

    currSel.addEventListener('change', fillFromCurriculum);

    // Auto-load students when batch is entered
    if (batchInput) {
        batchInput.addEventListener('change', function() {
            if (progSel.value && this.value.trim()) loadStudentsByBatch(false);
        });
    }

    // Manual "Load Students" button – shows alert if nothing found
    if (btnLoad) {
        btnLoad.addEventListener('click', function() {
            var progId = progSel.value;
            var batch  = batchInput ? batchInput.value.trim() : '';
            if (!progId) { alert('Please select a program first.'); return; }
            if (!batch)  { alert('Please enter a batch first.'); return; }
            loadStudentsByBatch(true);
        });
    }

    // ── "Other Batch" panel toggle ────────────────────────────────────────────
    var btnOtherBatch   = document.getElementById('btn_add_other_batch');
    var otherBatchPanel = document.getElementById('other_batch_panel');
    var otherBatchVal   = document.getElementById('other_batch_val');
    var otherStudentQ   = document.getElementById('other_student_q');
    var btnOtherSearch  = document.getElementById('btn_other_search');
    var otherResults    = document.getElementById('other_student_results');

    if (btnOtherBatch) {
        btnOtherBatch.addEventListener('click', function() {
            var visible = otherBatchPanel.style.display !== 'none';
            otherBatchPanel.style.display = visible ? 'none' : '';
            if (!visible) loadOtherBatchList(getDeptId());
        });
    }

    function doOtherSearch() {
        var batch = otherBatchVal ? otherBatchVal.value.trim() : '';
        var q     = otherStudentQ ? otherStudentQ.value.trim() : '';
        if (!q || q.length < 2) { otherResults.innerHTML = '<small class="text-danger">Enter at least 2 characters.</small>'; return; }
        var deptId = getDeptId();
        var url = APP_URL + '/results/get-students.php?q=' + encodeURIComponent(q)
                          + '&dept_id=' + encodeURIComponent(deptId);
        if (batch) url += '&batch=' + encodeURIComponent(batch);
        otherResults.innerHTML = '<small class="text-muted">Searching…</small>';
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.length) { otherResults.innerHTML = '<small class="text-warning">No students found.</small>'; return; }
                var html = '<div class="list-group list-group-flush mt-1" style="max-height:180px;overflow-y:auto;">';
                data.forEach(function(s) {
                    html += '<button type="button" class="list-group-item list-group-item-action py-1 px-2 other-add-btn"'
                          + ' data-sid="' + (s.student_id||'') + '"'
                          + ' data-name="' + (s.full_name||'').replace(/"/g,'&quot;') + '"'
                          + ' data-pk="'  + (s.id||0) + '" style="font-size:.82rem;">'
                          + '<strong>' + (s.student_id||'') + '</strong> — ' + (s.full_name||'')
                          + (s.batch ? ' <span class="badge bg-secondary ms-1">' + s.batch + '</span>' : '')
                          + (s.program_name ? ' <small class="text-muted ms-1">' + s.program_name + '</small>' : '')
                          + '</button>';
                });
                html += '</div>';
                otherResults.innerHTML = html;
                otherResults.querySelectorAll('.other-add-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        appendRow(this.dataset.sid, this.dataset.name, this.dataset.pk);
                        renumber();
                        this.classList.add('active');
                        this.disabled = true;
                        this.textContent = '✓ Added';
                    });
                });
            });
    }

    if (btnOtherSearch) btnOtherSearch.addEventListener('click', doOtherSearch);
    if (otherStudentQ) {
        otherStudentQ.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doOtherSearch(); } });
    }

    // Initial load: programs (and subjects + students if editing)
    var initDept = facultyDeptId || savedDept;
    if (initDept) {
        loadPrograms(initDept, savedProg);
        loadOtherBatchList(initDept);
    }
    if (!savedDept && !facultyDeptId) updateChainInfo(0, 0);
    // Load mark distribution for existing sheet (edit mode)
    if (savedCurr) loadMarkDistribution(savedCurr);

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

    // Wire existing rows (edit mode)
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
