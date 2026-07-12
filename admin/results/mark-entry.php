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
// Only administrators (super admins) may remove registered students from a sheet.
$can_remove_rows = is_super_admin();
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
        'SELECT ms.*, d.name AS dept_name, p.program_name,
                e.exam_name, e.exam_year
         FROM result_mark_sheets ms
         JOIN dept_departments d            ON d.id = ms.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = ms.program_id
         LEFT JOIN ei_exams e               ON e.id = ms.exam_id
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

    $action           = $_POST['action']           ?? 'save';
    $dept_id          = (int)($_POST['dept_id']          ?? 0);
    $program_id       = (int)($_POST['program_id']       ?? 0);
    $exam_id          = (int)($_POST['exam_id']          ?? 0);
    $offer_subject_id = (int)($_POST['offer_subject_id'] ?? 0);
    $subject_code     = trim($_POST['subject_code']      ?? '');
    $subject_title    = trim($_POST['subject_title']     ?? '');
    $credits          = trim($_POST['credits']           ?? '');

    // Curriculum + term label are derived authoritatively from the selected course
    // offer subject, so the client cannot spoof an unrelated subject/term.
    $curriculum_id  = 0;
    $semester_label = ''; // stored in result_mark_sheets.semester (offer's term label)
    $offer_row      = null;
    if ($offer_subject_id > 0) {
        $ost = db()->prepare(
            "SELECT cos.curriculum_id, o.dept_id, o.program_id,
                    o.semester, o.academic_intake
               FROM co_offer_subjects cos
               JOIN co_offers o ON o.id = cos.offer_id
              WHERE cos.id = ?
              LIMIT 1"
        );
        $ost->execute([$offer_subject_id]);
        $offer_row = $ost->fetch();
    }
    if ($offer_row) {
        $curriculum_id  = (int)$offer_row['curriculum_id'];
        // Keep the sheet aligned with the offer's own dept/program.
        $dept_id        = (int)$offer_row['dept_id'];
        $program_id     = (int)$offer_row['program_id'];
        $semester_label = trim((string)($offer_row['semester'] ?: $offer_row['academic_intake']));
    }
    // Fall back to a non-empty term label so the NOT NULL `semester` column is satisfied.
    if ($semester_label === '') {
        if ($exam_id > 0) {
            $ex = db()->prepare('SELECT exam_name, exam_year FROM ei_exams WHERE id = ? LIMIT 1');
            $ex->execute([$exam_id]);
            if ($exrow = $ex->fetch()) {
                $semester_label = trim($exrow['exam_name'] . ' ' . $exrow['exam_year']);
            }
        }
        if ($semester_label === '') $semester_label = date('Y');
    }

    if ($dept_id <= 0)         $errors[] = 'Department is required.';
    if ($exam_id <= 0)         $errors[] = 'Please select an exam.';
    if ($offer_subject_id <= 0 || !$offer_row) $errors[] = 'Please select a subject from the course offers.';
    if ($subject_title === '') $errors[] = 'Subject title is required.';

    // Faculty: server-side check that they are assigned to teach this offered subject.
    if ($offer_subject_id > 0 && !is_super_admin() && !rm_can_create() && !rm_is_staff()) {
        $fac_uid = (int)$user['id'];
        // Faculty members only qualify when they are a teacher on this offer subject…
        $fa_stmt = db()->prepare(
            "SELECT COUNT(*) FROM co_offer_subject_teachers t
               JOIN dept_faculty df ON df.id = t.faculty_id
              WHERE t.offer_subject_id = ? AND df.user_id = ?"
        );
        $fa_stmt->execute([$offer_subject_id, $fac_uid]);
        $is_authorized = (int)$fa_stmt->fetchColumn() > 0;
        // …or they are the approved/admin-assigned teacher of the curriculum subject.
        if (!$is_authorized && $curriculum_id > 0) {
            $fa2 = db()->prepare(
                "SELECT COUNT(*) FROM faculty_subject_assignments fsa
                  WHERE fsa.faculty_user_id = ? AND fsa.course_id = ? AND fsa.status = 'approved'"
            );
            $fa2->execute([$fac_uid, $curriculum_id]);
            $is_authorized = (int)$fa2->fetchColumn() > 0;
        }
        if (!$is_authorized && $curriculum_id > 0) {
            $fa3 = db()->prepare(
                "SELECT COUNT(*) FROM course_curriculum cc
                   JOIN dept_faculty df ON df.id = cc.assigned_faculty_id
                  WHERE df.user_id = ? AND cc.id = ?"
            );
            $fa3->execute([$fac_uid, $curriculum_id]);
            $is_authorized = (int)$fa3->fetchColumn() > 0;
        }
        if (!$is_authorized) {
            $errors[] = 'You are not authorized to submit results for this subject.';
        }
    }

    // ── Duplicate guard ───────────────────────────────────────────────────────
    // A batch's marks for a given exam may only be entered once. If a sheet for
    // the same exam + offered subject has already been submitted (pending review)
    // or published, block re-entry. A sheet that was *returned* to the teacher
    // does not block, so it can be revised and re-submitted.
    if (empty($errors) && $exam_id > 0 && $offer_subject_id > 0) {
        $dup = db()->prepare(
            "SELECT workflow_status FROM result_mark_sheets
              WHERE exam_id = ? AND offer_subject_id = ?
                AND workflow_status IN ('pending', 'published')
                AND id <> ?
              LIMIT 1"
        );
        $dup->execute([$exam_id, $offer_subject_id, $sheet_id]);
        if ($dup->fetchColumn() !== false) {
            $errors[] = 'The marks for this batch for this exam have already been submitted. '
                      . 'You cannot mark again. If you want to mark again, please contact the '
                      . 'Controller of Examinations with the permission of the Department Head.';
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

        $is_new_sheet = ($sheet_id <= 0);

        if ($sheet_id > 0) {
            // Update existing sheet header
            $db->prepare(
                'UPDATE result_mark_sheets SET
                   dept_id=?, program_id=?, exam_id=?, semester=?,
                   curriculum_id=?, offer_subject_id=?,
                   subject_code=?, subject_title=?, credits=?,
                   updated_at=NOW()
                 WHERE id=?'
            )->execute([
                $dept_id, $program_id ?: null, $exam_id ?: null, $semester_label,
                $curriculum_id ?: null, $offer_subject_id ?: null,
                $subject_code ?: null, $subject_title,
                $credits !== '' ? (float)$credits : null,
                $sheet_id,
            ]);
        } else {
            // Insert new sheet (always starts as draft)
            $db->prepare(
                'INSERT INTO result_mark_sheets
                   (dept_id, program_id, exam_id, semester, curriculum_id, offer_subject_id,
                    subject_code, subject_title, credits, workflow_status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,\'draft\',?)'
            )->execute([
                $dept_id, $program_id ?: null, $exam_id ?: null, $semester_label,
                $curriculum_id ?: null, $offer_subject_id ?: null,
                $subject_code ?: null, $subject_title,
                $credits !== '' ? (float)$credits : null,
                $user['id'],
            ]);
            $sheet_id = (int)$db->lastInsertId();
            // Note: chain is not yet resolved for draft saves; history entry added at submit time
        }

        // ── Save grades ────────────────────────────────────────────────────────
        $sids    = (array)($_POST['student_sid']   ?? []);
        $names   = (array)($_POST['student_name']  ?? []);
        $id_pks  = (array)($_POST['student_id_pk'] ?? []);
        $absents = (array)($_POST['is_absent']     ?? []);

        // Fetch distribution max-marks for the selected curriculum (for is_absent + clamping)
        $curriculum_dist = [];
        if ($curriculum_id > 0) {
            try {
                $dist_st = db()->prepare(
                    'SELECT distribution_name, max_marks FROM cc_mark_distributions
                      WHERE curriculum_id = ? ORDER BY sort_order ASC, id ASC'
                );
                $dist_st->execute([$curriculum_id]);
                $curriculum_dist = $dist_st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $_e) {}
        }
        // Fallback legacy max values when no curriculum distribution is found
        $dist_max_values = !empty($curriculum_dist)
            ? array_map('floatval', array_column($curriculum_dist, 'max_marks'))
            : [(float)WF_MAX_ATTENDANCE, (float)WF_MAX_CLASS_TEST, (float)WF_MAX_MID_TERM, (float)WF_MAX_FINAL_EXAM];

        // Dynamic marks: $_POST['marks'] is a 2D array [dist_idx][row_idx]
        $marks_by_dist = [];
        foreach ($_POST['marks'] ?? [] as $dist_idx => $vals) {
            $marks_by_dist[(int)$dist_idx] = (array)$vals;
        }

        // Per-segment absent: $_POST['dist_absent'] is a 2D array [dist_idx][row_idx]
        $dist_absent_by_dist = [];
        foreach ($_POST['dist_absent'] ?? [] as $dist_idx => $vals) {
            $dist_absent_by_dist[(int)$dist_idx] = (array)$vals;
        }

        // Collect a per-student snapshot of the marks entered for the change log.
        $log_rows = [];

        foreach ($sids as $row_idx => $sid) {
            $sid = trim($sid);
            if ($sid === '') continue;
            // Build marks array for this row across all distributions
            $row_marks = [];
            foreach ($marks_by_dist as $dist_idx => $vals) {
                $v = $vals[$row_idx] ?? '';
                $row_marks[$dist_idx] = ($v !== '') ? (float)$v : null;
            }
            // Ensure contiguous array (fill missing indices with null)
            if (!empty($row_marks)) {
                $max_idx = max(array_keys($row_marks));
                for ($i = 0; $i <= $max_idx; $i++) {
                    if (!array_key_exists($i, $row_marks)) $row_marks[$i] = null;
                }
                ksort($row_marks);
                $row_marks = array_values($row_marks);
            }

            // Build per-segment absent flags for this row
            $row_absent_flags = [];
            foreach ($dist_absent_by_dist as $dist_idx => $vals) {
                $row_absent_flags[$dist_idx] = ($vals[$row_idx] ?? '0') === '1';
            }
            // Ensure contiguous array
            if (!empty($row_absent_flags)) {
                $max_idx = max(array_keys($row_absent_flags));
                for ($i = 0; $i <= $max_idx; $i++) {
                    if (!array_key_exists($i, $row_absent_flags)) $row_absent_flags[$i] = false;
                }
                ksort($row_absent_flags);
                $row_absent_flags = array_values($row_absent_flags);
            }

            // Derive is_absent: true if any absent segment has max_marks >= WF_HIGH_VALUE_THRESHOLD
            $derived_absent = 0;
            foreach ($row_absent_flags as $i => $flag) {
                if ($flag && ($dist_max_values[$i] ?? 0) >= WF_HIGH_VALUE_THRESHOLD) {
                    $derived_absent = 1;
                    break;
                }
            }

            wf_upsert_grade(
                $sheet_id,
                (int)($id_pks[$row_idx] ?? 0),
                $sid,
                trim($names[$row_idx] ?? ''),
                $derived_absent,
                $row_marks,
                $row_absent_flags,
                $dist_max_values
            );

            $log_rows[] = [
                'sid'    => $sid,
                'name'   => trim($names[$row_idx] ?? ''),
                'marks'  => $row_marks,
                'absent' => (bool)$derived_absent,
            ];
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

        // ── Change log: record every mark-entry action (create/update + marks) ──
        $log_action = $is_new_sheet ? 'CREATE' : 'UPDATE';
        $log_label  = trim(($subject_code !== '' ? $subject_code . ' — ' : '') . $subject_title);
        if ($log_label === '') $log_label = 'Mark Sheet #' . $sheet_id;
        $verb = ($action === 'submit') ? 'submitted for review' : 'saved as draft';
        $log_desc = sprintf(
            'Mark sheet %s and %s (%s) — %d student%s',
            $is_new_sheet ? 'created' : 'updated',
            $verb,
            $semester_label,
            count($log_rows),
            count($log_rows) === 1 ? '' : 's'
        );
        log_change(
            'results',
            $log_action,
            $sheet_id,
            $log_label,
            'marks',
            null,
            json_encode($log_rows),
            $log_desc
        );

        redirect(APP_URL . '/results/index.php?tab=my_sheets');
    }

    save_old(compact('dept_id','program_id','exam_id','offer_subject_id',
                     'subject_code','subject_title','credits'));
}

require_once __DIR__ . '/../includes/header.php';

// Active exams for the "Select Exam" dropdown (from the Exam Invigilation module).
$exam_options = db()->query(
    'SELECT id, exam_name, exam_year
       FROM ei_exams
      WHERE is_active = 1
      ORDER BY exam_year DESC, exam_name ASC'
)->fetchAll();

$v_dept_id          = $sheet ? $sheet['dept_id']          : (old('dept_id') ?: ($faculty_dept_id ?: null));
$v_program_id       = $sheet ? $sheet['program_id']        : old('program_id');
$v_exam_id          = $sheet ? $sheet['exam_id']           : old('exam_id');
$v_offer_subject_id = $sheet ? $sheet['offer_subject_id']  : old('offer_subject_id');
$v_curriculum_id    = $sheet ? $sheet['curriculum_id']     : old('curriculum_id');
$v_subject_code     = $sheet ? $sheet['subject_code']      : old('subject_code');
$v_subject_title    = $sheet ? $sheet['subject_title']     : old('subject_title');
$v_credits          = $sheet ? $sheet['credits']           : old('credits');
$is_edit            = $sheet !== null;
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

<div id="dup_warning" class="alert alert-warning d-none" role="alert">
    <strong><i class="fas fa-exclamation-triangle me-1"></i> Marks already submitted</strong>
    <div id="dup_warning_text" class="mt-1"></div>
</div>

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
                        <div class="col-md-4">
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
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Program</label>
                            <select name="program_id" id="prog_select" class="form-select" <?= $v_dept_id ? '' : 'disabled' ?>>
                                <option value="">— Select Program —</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Select Exam <span class="text-danger">*</span></label>
                            <select name="exam_id" id="exam_select" class="form-select" required>
                                <option value="">— Select Exam —</option>
                                <?php foreach ($exam_options as $ex): ?>
                                <option value="<?= (int)$ex['id'] ?>" <?= (int)$v_exam_id === (int)$ex['id'] ? 'selected' : '' ?>>
                                    <?= h($ex['exam_name'] . ' ' . $ex['exam_year']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <?php if (empty($exam_options)): ?>
                                <span class="text-danger">No active exams. Create one in the Exam Invigilation module first.</span>
                                <?php else: ?>
                                Pulled from the Exam module.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">
                            Subject
                            <?= (!is_super_admin() && !rm_can_create() && !rm_is_staff())
                                ? '<span class="badge bg-info text-dark ms-1" style="font-size:.7rem;"><i class="fas fa-lock me-1"></i>Assigned Only</span>'
                                : '' ?>
                            <span class="text-danger">*</span>
                        </label>
                        <select name="offer_subject_id" id="curriculum_select" class="form-select"
                                <?= ($v_dept_id && $v_program_id) ? '' : 'disabled' ?>>
                            <option value="">— Select Subject —</option>
                        </select>
                        <div class="form-text" id="subject_hint">
                            <?= (!is_super_admin() && !rm_can_create() && !rm_is_staff())
                                ? 'Only offered courses you teach are shown. Selecting one loads its registered students.'
                                : 'Subjects are pulled from Course Offers. Selecting one loads its active registered students.' ?>
                        </div>
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

        <!-- Actions & Reference (sticky, no gap below left card) -->
        <div class="col-lg-3">
            <div style="position:sticky;top:16px;">

                <!-- Actions card -->
                <div class="card mb-3" style="border-radius:12px;">
                    <div class="card-header py-2 px-3">
                        <h6 class="mb-0 fw-semibold" style="font-size:.82rem;">
                            <i class="fas fa-paper-plane me-1 text-muted"></i>Actions
                        </h6>
                    </div>
                    <div class="card-body p-3">
                        <?php if ($sheet): ?>
                        <div class="mb-2 p-2 bg-light rounded" style="font-size:.78rem;">
                            <div><strong>Status:</strong> <?= wf_status_badge($sheet['workflow_status']) ?></div>
                            <div class="mt-1 text-muted">Created: <?= date('d M Y', strtotime($sheet['created_at'])) ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="d-grid gap-2">
                            <button type="submit" name="action" value="save" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                                <i class="fas fa-save me-1"></i> Save Draft
                            </button>
                            <button type="submit" name="action" value="submit" id="btn_submit"
                                    class="btn btn-sm btn-success" style="border-radius:8px;"
                                    onclick="return confirm('Submit this mark sheet for review? It will be locked for editing after submission.');">
                                <i class="fas fa-paper-plane me-1"></i> Submit for Review
                            </button>
                            <a href="<?= APP_URL ?>/results/index.php" class="btn btn-sm btn-light" style="border-radius:8px;">Cancel</a>
                        </div>
                        <div id="chain_info" class="mt-2 px-2 py-1 border rounded text-muted" style="display:none;font-size:.72rem;">
                            <i class="fas fa-sitemap me-1"></i>
                            <span id="chain_info_text"></span>
                        </div>
                    </div>
                </div>

                <!-- Mark Distribution – loaded from curriculum when subject is selected -->
                <div class="card mb-3" style="border-radius:12px;">
                    <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between"
                         style="background:#0d6efd;border-radius:12px 12px 0 0;">
                        <h6 class="mb-0 fw-semibold text-white" style="font-size:.82rem;">
                            <i class="fas fa-chart-bar me-1"></i>Mark Distribution
                        </h6>
                        <small id="dist_ref_note" class="text-white-50 d-none" style="font-size:.68rem;">
                            <i class="fas fa-check-circle me-1"></i>From curriculum
                        </small>
                    </div>
                    <div class="card-body p-0" id="dist_ref_wrap">
                        <table class="table table-sm mb-0" id="dist_ref_table" style="font-size:.8rem;">
                            <thead style="background:#e8f0ff;">
                                <tr>
                                    <th class="ps-3 py-1" style="border-bottom:1px solid #c9d9f8;">Component</th>
                                    <th class="text-end pe-3 py-1" style="border-bottom:1px solid #c9d9f8;">Max</th>
                                </tr>
                            </thead>
                            <tbody id="dist_ref_tbody">
                                <tr><td class="ps-3 py-1">Attendance</td><td class="text-end pe-3 py-1">10</td></tr>
                                <tr><td class="ps-3 py-1">Class Test</td><td class="text-end pe-3 py-1">10</td></tr>
                                <tr><td class="ps-3 py-1">Mid Term</td><td class="text-end pe-3 py-1">30</td></tr>
                                <tr><td class="ps-3 py-1">Final Exam</td><td class="text-end pe-3 py-1">50</td></tr>
                                <tr style="background:#f0f4ff;border-top:2px solid #0d6efd;">
                                    <td class="ps-3 py-1 fw-semibold">Total</td>
                                    <td class="text-end pe-3 py-1 fw-bold text-primary">100</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Grading Scale – collapsible to save vertical space -->
                <div class="card" style="border-radius:12px;">
                    <button type="button"
                            class="card-header py-2 px-3 d-flex align-items-center justify-content-between w-100 text-start border-0 bg-transparent"
                            style="border-radius:12px;cursor:pointer;"
                            data-bs-toggle="collapse" data-bs-target="#grading_scale_body" aria-expanded="false">
                        <h6 class="mb-0 fw-semibold" style="font-size:.82rem;">
                            <i class="fas fa-star me-1 text-warning"></i>Grading Scale
                        </h6>
                        <i class="fas fa-chevron-down small text-muted"></i>
                    </button>
                    <div id="grading_scale_body" class="collapse">
                        <table class="table table-sm mb-0" style="font-size:.75rem;border-top:1px solid #dee2e6;">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Range</th>
                                    <th class="text-center">Grade</th>
                                    <th class="text-end pe-3">GPA</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (wf_grading_scale() as [$min, $max, $letter, $point]):
                                    $range = ($max === PHP_INT_MAX) ? '≥'.$min : $min.'–<'.$max; ?>
                                <tr>
                                    <td class="ps-3"><?= $range ?></td>
                                    <td class="text-center"><strong><?= h($letter) ?></strong></td>
                                    <td class="text-end pe-3"><?= number_format($point,2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /sticky wrapper -->
        </div>
    </div>
    <!-- Student Marks Table -->
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Student Marks</h6>
            <div class="d-flex gap-2">
                <button type="button" id="btn_reload_students" class="btn btn-sm btn-outline-primary"
                        style="border-radius:8px;" disabled title="Reload registered students for the selected subject">
                    <i class="fas fa-sync me-1"></i> Reload Registered
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
                    <thead class="table-light" id="marks_thead">
                        <tr>
                            <th class="ps-2 text-center" style="width:36px;">#</th>
                            <th style="min-width:80px;">Student ID</th>
                            <th style="min-width:120px;">Name</th>
                            <!-- dynamic mark-column headers inserted by JS -->
                            <th class="text-center col-total" style="width:68px;">Total</th>
                            <th class="text-center" style="width:58px;">Grade</th>
                            <th class="text-center" style="min-width:150px;">Remarks</th>
                            <th style="width:36px;"></th>
                        </tr>
                    </thead>
                    <tbody id="marks_tbody">
<?php
// Output existing rows (edit mode) with saved mark values as data attributes.
// Mark cells are injected by JS after the distribution loads.
if (!empty($grades)):
    foreach ($grades as $idx => $g):
        // Prefer marks_json for edit-mode pre-population; fall back to 4 legacy columns.
        $saved_marks = null;
        if (!empty($g['marks_json'])) {
            $saved_marks = json_decode($g['marks_json'], true);
        }
        if (!is_array($saved_marks)) {
            $saved_marks = [
                $g['is_absent'] ? null : ($g['attendance'] ?? null),
                $g['is_absent'] ? null : ($g['class_test'] ?? null),
                $g['is_absent'] ? null : ($g['mid_term']   ?? null),
                $g['is_absent'] ? null : ($g['final_exam'] ?? null),
            ];
        }
        // Per-segment absent flags (absent_json column)
        $saved_absent_flags = null;
        if (!empty($g['absent_json'])) {
            $saved_absent_flags = json_decode($g['absent_json'], true);
        }
?>
                        <tr class="grade-row <?= $g['is_absent'] ? 'table-warning' : '' ?>"
                            data-saved-marks="<?= h(json_encode($saved_marks)) ?>"
                            data-is-absent="<?= $g['is_absent'] ? '1' : '0' ?>"
                            data-absent-flags="<?= h(json_encode($saved_absent_flags)) ?>">
                            <td class="text-center row-num" style="font-size:.8rem;"><?= $idx + 1 ?></td>
                            <td>
                                <input type="hidden" name="student_id_pk[]" value="<?= (int)$g['student_id'] ?>">
                                <input type="hidden" name="is_absent[]" class="absent-flag" value="<?= $g['is_absent'] ? '1' : '0' ?>">
                                <input type="text" name="student_sid[]" class="form-control form-control-sm"
                                       value="<?= h($g['student_sid']) ?>" placeholder="ID" required
                                       style="font-size:.78rem;padding:.2rem .4rem;">
                            </td>
                            <td>
                                <input type="text" name="student_name[]" class="form-control form-control-sm"
                                       value="<?= h($g['student_name']) ?>" placeholder="Full Name"
                                       style="font-size:.78rem;padding:.2rem .4rem;">
                            </td>
                            <!-- mark cells injected by JS -->
                            <td class="text-center total-cell fw-semibold" style="font-size:.8rem;">—</td>
                            <td class="text-center grade-cell fw-bold" style="font-size:.8rem;">—</td>
                            <td class="text-center remarks-cell" style="font-size:.75rem;">—</td>
                            <td class="text-center">
<?php if ($can_remove_rows): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row p-0"
                                        style="width:24px;height:24px;line-height:1;border-radius:5px;">
                                    <i class="fas fa-times" style="font-size:.65rem;"></i>
                                </button>
<?php endif; ?>
                            </td>
                        </tr>
<?php
    endforeach;
else:
?>
                        <tr id="empty_row">
                            <td colspan="11" class="text-center text-muted py-3" id="empty_colspan">
                                Select an exam and a subject — the active registered students of that course load automatically. Use "Add Row" to add a student manually.
                            </td>
                        </tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</form>

<!-- Row template (hidden) – mark cells are injected dynamically by JS -->
<template id="row_template">
    <tr class="grade-row">
        <td class="text-center row-num" style="font-size:.8rem;"></td>
        <td>
            <input type="hidden" name="student_id_pk[]" value="0">
            <input type="hidden" name="is_absent[]" class="absent-flag" value="0">
            <input type="text" name="student_sid[]" class="form-control form-control-sm" placeholder="ID" required
                   style="font-size:.78rem;padding:.2rem .4rem;">
        </td>
        <td><input type="text" name="student_name[]" class="form-control form-control-sm" placeholder="Full Name"
                   style="font-size:.78rem;padding:.2rem .4rem;"></td>
        <!-- mark cells injected by JS -->
        <td class="text-center total-cell fw-semibold" style="font-size:.8rem;">—</td>
        <td class="text-center grade-cell fw-bold" style="font-size:.8rem;">—</td>
        <td class="text-center remarks-cell" style="font-size:.75rem;">—</td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row p-0"
                    style="width:24px;height:24px;line-height:1;border-radius:5px;">
                <i class="fas fa-times" style="font-size:.65rem;"></i>
            </button>
        </td>
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
    var examSel       = document.getElementById('exam_select');
    var currSel       = document.getElementById('curriculum_select');
    var subCode       = document.getElementById('subject_code');
    var subTitle      = document.getElementById('subject_title');
    var credits       = document.getElementById('credits_input');
    var tbody         = document.getElementById('marks_tbody');
    var emptyRow      = document.getElementById('empty_row');
    var btnReload     = document.getElementById('btn_reload_students');
    var btnAdd        = document.getElementById('btn_add_row');
    var template      = document.getElementById('row_template');
    var chainInfo     = document.getElementById('chain_info');
    var chainText     = document.getElementById('chain_info_text');

    var savedDept         = <?= (int)$v_dept_id ?>;
    var savedProg         = <?= (int)$v_program_id ?>;
    var savedOfferSubject = <?= (int)$v_offer_subject_id ?>;
    var savedCurr         = <?= (int)$v_curriculum_id ?>;
    var facultyDeptId = <?= $faculty_dept_id ?>;
    var isEditMode    = <?= $is_edit ? 'true' : 'false' ?>;
    var currentSheetId = <?= (int)$sheet_id ?>;
    // Only administrators may remove registered students from a sheet.
    var CAN_REMOVE_ROWS = <?= $can_remove_rows ? 'true' : 'false' ?>;

    // ── Mark distribution defaults ────────────────────────────────────────────
    /** Minimum component max-marks to be considered "high-value" (absent → Incom). */
    var HIGH_VALUE_THRESHOLD = <?= WF_HIGH_VALUE_THRESHOLD ?>;
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

    // HTML escape helper for safe DOM construction
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Dynamic mark column helpers ───────────────────────────────────────────

    /**
     * Rebuild the <thead> mark columns to match currentDist.
     * Static cols before marks: #, Student ID, Name (3).
     * Static cols after marks: Total, Grade, Remarks, Delete (4).
     */
    function rebuildTableHeader() {
        var thead = document.getElementById('marks_thead');
        if (!thead) return;
        var tr = thead.querySelector('tr');
        if (!tr) return;

        // Remove existing mark-col <th> elements
        Array.from(tr.querySelectorAll('th.mark-col')).forEach(function(th) { th.remove(); });

        // Reference to "Total" th (use class for reliability)
        var totalTh = tr.querySelector('th.col-total');
        currentDist.forEach(function(d) {
            var th = document.createElement('th');
            th.className = 'text-center mark-col';
            th.style.width = '72px';
            th.innerHTML = escHtml(d.name) + '<br><small class="text-muted">/' + d.max + '</small>';
            tr.insertBefore(th, totalTh);
        });

        // Update colspan of empty-row placeholder
        var emptyTd = document.getElementById('empty_colspan');
        if (emptyTd) emptyTd.setAttribute('colspan', String(3 + currentDist.length + 4));
    }

    /**
     * Build the mark-input <td> elements for a row using currentDist.
     * Returns an array of <td> elements to be inserted before the Total <td>.
     * @param {Array|null}  savedMarks   pre-filled mark values (indexed by dist position)
     * @param {boolean}     isGlobalAbsent  row is globally absent (all cells disabled)
     * @param {Array|null}  absentFlags  per-segment absent booleans (indexed by dist position)
     */
    function buildMarkCells(savedMarks, isGlobalAbsent, absentFlags) {
        return currentDist.map(function(d, i) {
            // Use per-segment flags when available; fall back to global flag only for legacy (no flags) data
            var isSegAbsent = (absentFlags !== null && absentFlags !== undefined)
                ? !!(absentFlags[i])
                : isGlobalAbsent;
            var td = document.createElement('td');
            td.style.cssText = 'vertical-align:middle;padding:.2rem .25rem;';

            // Hidden flag for this segment's absent state
            var segFlag = document.createElement('input');
            segFlag.type = 'hidden';
            segFlag.name = 'dist_absent[' + i + '][]';
            segFlag.className = 'dist-absent-flag';
            segFlag.setAttribute('data-dist-idx', i);
            segFlag.value = isSegAbsent ? '1' : '0';
            td.appendChild(segFlag);

            // Small "Abs" checkbox label
            var lbl = document.createElement('label');
            lbl.style.cssText = 'font-size:.65rem;display:flex;align-items:center;gap:2px;cursor:pointer;'
                              + 'color:' + (isSegAbsent ? '#dc3545' : '#aaa') + ';margin-bottom:2px;white-space:nowrap;';
            var segChk = document.createElement('input');
            segChk.type = 'checkbox';
            segChk.className = 'dist-absent-chk';
            segChk.setAttribute('data-dist-idx', i);
            segChk.style.cssText = 'transform:scale(0.75);cursor:pointer;';
            segChk.checked = isSegAbsent;
            lbl.appendChild(segChk);
            var lblTxt = document.createElement('span');
            lblTxt.textContent = 'Abs';
            lbl.appendChild(lblTxt);
            td.appendChild(lbl);

            // Mark number input
            var inp = document.createElement('input');
            inp.type = 'number';
            inp.name = 'marks[' + i + '][]';
            inp.className = 'form-control form-control-sm marks-input';
            inp.setAttribute('data-dist-idx', i);
            inp.min = '0';
            inp.max = String(d.max);
            inp.step = '0.5';
            inp.style.cssText = 'font-size:.78rem;padding:.2rem .3rem;text-align:center;';
            if (isSegAbsent) {
                inp.disabled = true;
                inp.placeholder = 'Abs';
            }
            // Enforce max: clamp value to [0, d.max] on input
            (function(maxVal) {
                inp.addEventListener('input', function() {
                    var v = parseFloat(this.value);
                    if (!isNaN(v)) {
                        if (v > maxVal) { this.value = maxVal; }
                        else if (v < 0) { this.value = 0; }
                    }
                });
            })(d.max);
            var sv = savedMarks ? savedMarks[i] : null;
            if (sv !== null && sv !== undefined && !isSegAbsent) inp.value = sv;
            td.appendChild(inp);
            return td;
        });
    }

    /**
     * Inject mark cells into every existing grade-row, preserving saved values.
     * Called after `rebuildTableHeader()` when distribution changes.
     */
    function rebuildRowMarkCells() {
        Array.from(tbody.querySelectorAll('tr.grade-row')).forEach(function(tr) {
            // Remove old mark-col tds
            Array.from(tr.querySelectorAll('td.mark-col')).forEach(function(td) { td.remove(); });

            var isAbsent = (tr.querySelector('.absent-flag') || {}).value === '1';
            var savedAttr = tr.getAttribute('data-saved-marks');
            var savedMarks = null;
            try { if (savedAttr) savedMarks = JSON.parse(savedAttr); } catch(e) {}

            var absentFlagsAttr = tr.getAttribute('data-absent-flags');
            var absentFlags = null;
            try { if (absentFlagsAttr && absentFlagsAttr !== 'null') absentFlags = JSON.parse(absentFlagsAttr); } catch(e) {}

            var totalTd = tr.querySelector('.total-cell');
            buildMarkCells(savedMarks, isAbsent, absentFlags).forEach(function(td) {
                td.classList.add('mark-col');
                tr.insertBefore(td, totalTd);
            });

            // Re-wire inputs
            wireMarkInputs(tr);
        });
    }

    // ── Apply mark distribution ───────────────────────────────────────────────
    function applyMarkDistribution(dists) {
        var fromCurriculum = dists && dists.length > 0;
        currentDist = fromCurriculum
            ? dists.map(function(d) { return { name: d.distribution_name, max: parseFloat(d.max_marks) }; })
            : defaultDist.slice();

        // Update sidebar reference panel (show ALL distributions, no limit)
        var refNote = document.getElementById('dist_ref_note');
        var refBody = document.getElementById('dist_ref_tbody');
        if (refBody) {
            var total = 0;
            currentDist.forEach(function(d) { total += d.max; });
            var rows = '';
            currentDist.forEach(function(d) {
                rows += '<tr><td class="ps-3 py-1">' + escHtml(d.name) + '</td>'
                      + '<td class="text-end pe-3 py-1">' + escHtml(String(d.max)) + '</td></tr>';
            });
            rows += '<tr style="background:#f0f4ff;border-top:2px solid #0d6efd;">'
                  + '<td class="ps-3 py-1 fw-semibold">Total</td>'
                  + '<td class="text-end pe-3 py-1 fw-bold text-primary">' + escHtml(String(total)) + '</td></tr>';
            refBody.innerHTML = rows;
        }
        if (refNote) {
            if (fromCurriculum) { refNote.classList.remove('d-none'); }
            else                { refNote.classList.add('d-none'); }
        }

        // Rebuild table header and existing row cells
        rebuildTableHeader();
        rebuildRowMarkCells();
    }

    function loadMarkDistribution(curriculumId) {
        if (!curriculumId) { applyMarkDistribution([]); return; }
        fetch(APP_URL + '/results/get-mark-distribution.php?curriculum_id=' + curriculumId)
            .then(function(r) { return r.json(); })
            .then(function(data) { applyMarkDistribution(data); });
    }

    // ── Chain info display ────────────────────────────────────────────────────
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
        if (btnReload) btnReload.disabled = true;
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
                if (selectId) {
                    // In edit mode the saved rows are already rendered by PHP; keep
                    // them (skipReload) so the registered-student fetch does not wipe
                    // manually adjusted grades.
                    loadOfferSubjects(deptId, selectId, savedOfferSubject, isEditMode);
                }
            });
    }

    // ── Subject selector (offered courses) ────────────────────────────────────
    function loadOfferSubjects(deptId, progId, selectId, skipReload) {
        currSel.innerHTML = '<option value="">— Select Subject —</option>';
        currSel.disabled  = true;
        if (btnReload) btnReload.disabled = true;
        if (!deptId || !progId) return;
        fetch(APP_URL + '/results/get-offer-subjects.php?dept_id=' + encodeURIComponent(deptId)
                      + '&program_id=' + encodeURIComponent(progId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.length) {
                    var o = document.createElement('option');
                    o.value = ''; o.textContent = '— No offered courses found —';
                    o.disabled = true;
                    currSel.appendChild(o);
                    currSel.disabled = false;
                    return;
                }

                data.forEach(function(s) {
                    var o = document.createElement('option');
                    o.value = s.offer_subject_id;
                    var label = (s.course_code ? s.course_code + ' – ' : '') + s.course_name;
                    var ctx = [];
                    if (s.batch_name) ctx.push(s.batch_name);
                    if (s.semester)   ctx.push(s.semester);
                    if (ctx.length)   label += ' (' + ctx.join(' · ') + ')';
                    label += '  •  ' + (parseInt(s.registered_count, 10) || 0) + ' registered';
                    o.textContent = label;
                    o.dataset.curriculumId = s.curriculum_id || '';
                    o.dataset.code         = s.course_code   || '';
                    o.dataset.title        = s.course_name   || '';
                    o.dataset.credits      = s.credit        || '';
                    if (s.offer_subject_id == selectId) o.selected = true;
                    currSel.appendChild(o);
                });
                currSel.disabled = false;
                if (selectId) fillFromOffer(skipReload);
            });
    }

    function fillFromOffer(skipReload) {
        var sel = currSel.options[currSel.selectedIndex];
        if (!sel || !sel.value) {
            subCode.value = ''; subTitle.value = ''; credits.value = '';
            applyMarkDistribution([]);
            if (btnReload) btnReload.disabled = true;
            return;
        }
        subCode.value  = sel.dataset.code    || '';
        subTitle.value = sel.dataset.title   || '';
        credits.value  = sel.dataset.credits || '';
        if (btnReload) btnReload.disabled = false;

        var curriculumId = sel.dataset.curriculumId || '';
        loadMarkDistribution(curriculumId);

        // Load the active registered students of the selected offered course.
        // In edit mode the first render keeps the saved rows.
        if (!skipReload) loadRegisteredStudents(sel.value, false);
    }

    // ── Load active registered students of the selected offer subject ─────────
    function loadRegisteredStudents(offerSubjectId, showAlert) {
        if (!offerSubjectId) return;
        fetch(APP_URL + '/results/get-offer-students.php?offer_subject_id=' + encodeURIComponent(offerSubjectId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                // Intentional: always reset the table so it reflects only the
                // selected course's registered students (an empty roster clears
                // any rows carried over from a previously selected subject).
                clearRows();
                if (!data.length) {
                    if (showAlert) alert('No active registered students found for this course.');
                    return;
                }
                data.forEach(function(s) { appendRow(s.student_id, s.full_name, s.id, null, null, true); });
                renumber();
            });
    }

    // Dept change (admin only)
    if (deptSel && deptSel.tagName === 'SELECT') {
        deptSel.addEventListener('change', function() {
            loadPrograms(this.value, 0);
            updateChainInfo(this.value, 0);
        });
    }

    progSel.addEventListener('change', function() {
        loadOfferSubjects(getDeptId(), this.value, 0, false);
        updateChainInfo(getDeptId(), this.value);
    });

    currSel.addEventListener('change', function() { fillFromOffer(false); });

    // ── Duplicate guard (proactive) ───────────────────────────────────────────
    // Warn the teacher — and disable the Save/Submit buttons — as soon as they
    // pick an exam + subject whose marks have already been submitted/published.
    var dupWarning     = document.getElementById('dup_warning');
    var dupWarningText = document.getElementById('dup_warning_text');
    var actionButtons  = Array.prototype.slice.call(
        document.querySelectorAll('#markEntryForm button[name="action"]'));

    function setActionsDisabled(disabled) {
        actionButtons.forEach(function(b) { b.disabled = disabled; });
    }

    function checkDuplicate() {
        var examId  = examSel ? examSel.value : '';
        var offerId = currSel ? currSel.value : '';
        if (!examId || !offerId) {
            if (dupWarning) dupWarning.classList.add('d-none');
            setActionsDisabled(false);
            return;
        }
        fetch(APP_URL + '/results/check-duplicate-sheet.php?exam_id=' + encodeURIComponent(examId)
                      + '&offer_subject_id=' + encodeURIComponent(offerId)
                      + '&exclude_id=' + encodeURIComponent(currentSheetId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.duplicate) {
                    if (dupWarningText) {
                        dupWarningText.textContent =
                            'The marks for this batch for this exam have already been submitted. '
                          + 'You cannot mark again. If you want to mark again, please contact the '
                          + 'Controller of Examinations with the permission of the Department Head.';
                    }
                    if (dupWarning) dupWarning.classList.remove('d-none');
                    setActionsDisabled(true);
                } else {
                    if (dupWarning) dupWarning.classList.add('d-none');
                    setActionsDisabled(false);
                }
            })
            .catch(function() { /* fail open — server-side guard still applies */ });
    }

    if (examSel) examSel.addEventListener('change', checkDuplicate);
    currSel.addEventListener('change', checkDuplicate);


    // Manual "Reload Registered" button
    if (btnReload) {
        btnReload.addEventListener('click', function() {
            if (!currSel.value) { alert('Please select a subject first.'); return; }
            loadRegisteredStudents(currSel.value, true);
        });
    }

    // Initial load: programs (and subjects + students if editing)
    var initDept = facultyDeptId || savedDept;
    if (initDept) {
        loadPrograms(initDept, savedProg);
    }
    if (!savedDept && !facultyDeptId) updateChainInfo(0, 0);
    // Load mark distribution for existing sheet (edit mode)
    if (savedCurr) loadMarkDistribution(savedCurr);

    function clearRows() {
        Array.from(tbody.querySelectorAll('tr.grade-row')).forEach(function(r) { r.remove(); });
        if (emptyRow) emptyRow.style.display = '';
    }

    /**
     * Append a student row with mark cells for the current distribution.
     * @param {string}      sid
     * @param {string}      name
     * @param {number|string} idPk
     * @param {Array|null}  savedMarks    pre-filled mark values
     * @param {Array|null}  absentFlags   per-segment absent flags
     */
    function appendRow(sid, name, idPk, savedMarks, absentFlags, isRegistered) {
        if (emptyRow) emptyRow.style.display = 'none';
        var clone = template.content.cloneNode(true);
        var tr    = clone.querySelector('tr');
        tr.querySelector('[name="student_id_pk[]"]').value = idPk || 0;
        tr.querySelector('[name="student_sid[]"]').value   = sid  || '';
        tr.querySelector('[name="student_name[]"]').value  = name || '';

        // Registered students may only be removed by administrators.
        if (isRegistered && !CAN_REMOVE_ROWS) {
            var rmBtn = tr.querySelector('.btn-remove-row');
            if (rmBtn) rmBtn.remove();
        }

        // Inject mark cells before Total
        var totalTd = tr.querySelector('.total-cell');
        buildMarkCells(savedMarks || null, false, absentFlags || null).forEach(function(td) {
            td.classList.add('mark-col');
            tr.insertBefore(td, totalTd);
        });

        wireRow(tr);
        tbody.appendChild(tr);
    }

    btnAdd.addEventListener('click', function() {
        if (emptyRow) emptyRow.style.display = 'none';
        appendRow('', '', 0, null);
        renumber();
    });

    /**
     * Wire up absent-checkbox, mark-input events, and remove-button for a row.
     * Call this after mark cells have been injected.
     */
    function wireRow(tr) {
        var flag  = tr.querySelector('.absent-flag');
        var chk   = tr.querySelector('.absent-chk');
        var total = tr.querySelector('.total-cell');
        var grd   = tr.querySelector('.grade-cell');

        function getMarkInputs() {
            return Array.from(tr.querySelectorAll('.marks-input'));
        }

        function updateTotal() {
            var inputs = getMarkInputs();
            var remarksCell = tr.querySelector('.remarks-cell');

            // Check if any high-value (max >= HIGH_VALUE_THRESHOLD) distribution segment is absent
            var absentNames = [];
            currentDist.forEach(function(d, i) {
                if (d.max >= HIGH_VALUE_THRESHOLD) {
                    var segFlag = tr.querySelector('.dist-absent-flag[data-dist-idx="' + i + '"]');
                    if (segFlag && segFlag.value === '1') {
                        absentNames.push(d.name);
                    }
                }
            });

            if (absentNames.length > 0) {
                // High-value segment absent → Incom
                if (flag) flag.value = '1';
                total.innerHTML = '<span class="text-muted">—</span>';
                grd.innerHTML   = '<span class="text-warning fw-bold">Incom</span>';
                if (remarksCell) {
                    var txt = absentNames.join(', ') + ' marked as absent – no grade shown';
                    remarksCell.innerHTML = '<small class="text-danger">' + escHtml(txt) + '</small>';
                }
                return;
            }

            // Legacy: row was globally marked absent in older data
            if (flag && flag.value === '1') {
                total.innerHTML = '<span class="text-muted">—</span>';
                grd.innerHTML   = '<span class="text-warning fw-bold">Incom</span>';
                if (remarksCell) remarksCell.innerHTML = '<small class="text-danger">Marked as absent – no grade shown</small>';
                return;
            }

            if (flag) flag.value = '0';
            if (remarksCell) remarksCell.textContent = '—';

            var hasEnteredMark = false;
            var hasAnyData     = false;
            var sum            = 0;
            inputs.forEach(function(inp) {
                if (inp.disabled) { hasAnyData = true; return; } // absent segment contributes 0
                var v = parseFloat(inp.value);
                if (!isNaN(v)) { sum += v; hasEnteredMark = true; hasAnyData = true; }
            });
            if (!hasAnyData) { total.textContent = '—'; grd.textContent = '—'; return; }
            total.textContent = hasEnteredMark ? sum.toFixed(1) : '0.0';
            grd.textContent   = grade(sum);
        }

        // Global absent checkbox: removed from UI; handler kept for safety (chk is null for new rows)
        if (chk && flag) {
            chk.addEventListener('change', function() {
                flag.value = this.checked ? '1' : '0';
                tr.classList.toggle('table-warning', this.checked);
                tr.querySelectorAll('.marks-input').forEach(function(inp) { inp.disabled = chk.checked; });
                tr.querySelectorAll('.dist-absent-chk').forEach(function(sc) { sc.disabled = chk.checked; });
                updateTotal();
            });
        }

        // Per-segment absent checkboxes (wired via event delegation on tr)
        tr.addEventListener('change', function(e) {
            if (!e.target.classList.contains('dist-absent-chk')) return;
            var segChk  = e.target;
            var distIdx = segChk.getAttribute('data-dist-idx');
            // Update hidden flag
            var segFlag = tr.querySelector('.dist-absent-flag[data-dist-idx="' + distIdx + '"]');
            if (segFlag) segFlag.value = segChk.checked ? '1' : '0';
            // Update label colour
            var lbl = segChk.closest('label');
            if (lbl) lbl.style.color = segChk.checked ? '#dc3545' : '#aaa';
            // Disable/enable the corresponding mark input
            var inp = tr.querySelector('.marks-input[data-dist-idx="' + distIdx + '"]');
            if (inp) {
                inp.disabled    = segChk.checked;
                inp.placeholder = segChk.checked ? 'Abs' : '';
                if (segChk.checked) inp.value = '';
            }
            updateTotal();
        });

        // Wire current mark inputs
        function wireMarkInputs_local() {
            getMarkInputs().forEach(function(inp) { inp.addEventListener('input', updateTotal); });
        }
        wireMarkInputs_local();

        var btn = tr.querySelector('.btn-remove-row');
        if (btn) btn.addEventListener('click', function() {
            tr.remove(); renumber();
            if (!tbody.querySelector('tr.grade-row') && emptyRow) emptyRow.style.display = '';
        });
    }

    /**
     * Re-wire mark-input change listeners on a row (called after distribution rebuild).
     * Also triggers an immediate updateTotal so Remarks/Grade cells are up-to-date.
     */
    function wireMarkInputs(tr) {
        var total = tr.querySelector('.total-cell');
        var grd   = tr.querySelector('.grade-cell');
        var chk   = tr.querySelector('.absent-chk');
        var flag  = tr.querySelector('.absent-flag');
        var remarksCell = tr.querySelector('.remarks-cell');

        function updateTotal() {
            var inputs = Array.from(tr.querySelectorAll('.marks-input'));

            // Check if any high-value (max >= HIGH_VALUE_THRESHOLD) distribution segment is absent
            var absentNames = [];
            currentDist.forEach(function(d, i) {
                if (d.max >= HIGH_VALUE_THRESHOLD) {
                    var segFlag = tr.querySelector('.dist-absent-flag[data-dist-idx="' + i + '"]');
                    if (segFlag && segFlag.value === '1') {
                        absentNames.push(d.name);
                    }
                }
            });

            if (absentNames.length > 0) {
                if (flag) flag.value = '1';
                total.innerHTML = '<span class="text-muted">—</span>';
                grd.innerHTML   = '<span class="text-warning fw-bold">Incom</span>';
                if (remarksCell) {
                    var txt = absentNames.join(', ') + ' marked as absent – no grade shown';
                    remarksCell.innerHTML = '<small class="text-danger">' + escHtml(txt) + '</small>';
                }
                return;
            }

            // Legacy: row was globally marked absent (older data with is_absent=1)
            if (flag && flag.value === '1') {
                total.innerHTML = '<span class="text-muted">—</span>';
                grd.innerHTML   = '<span class="text-warning fw-bold">Incom</span>';
                if (remarksCell) remarksCell.innerHTML = '<small class="text-danger">Marked as absent – no grade shown</small>';
                return;
            }

            if (remarksCell) remarksCell.textContent = '—';

            var hasEnteredMark = false, hasAnyData = false, sum = 0;
            inputs.forEach(function(inp) {
                if (inp.disabled) { hasAnyData = true; return; }
                var v = parseFloat(inp.value);
                if (!isNaN(v)) { sum += v; hasEnteredMark = true; hasAnyData = true; }
            });
            if (!hasAnyData) { total.textContent = '—'; grd.textContent = '—'; return; }
            total.textContent = hasEnteredMark ? sum.toFixed(1) : '0.0';
            grd.textContent   = grade(sum);
        }
        Array.from(tr.querySelectorAll('.marks-input')).forEach(function(inp) {
            inp.addEventListener('input', updateTotal);
        });
        // Initialise display immediately after wiring
        updateTotal();
    }

    // Wire existing rows (edit mode) – mark cells will be injected when distribution loads
    Array.from(tbody.querySelectorAll('tr.grade-row')).forEach(wireRow);

    function renumber() {
        Array.from(tbody.querySelectorAll('tr.grade-row')).forEach(function(tr, i) {
            var n = tr.querySelector('.row-num'); if (n) n.textContent = i + 1;
        });
    }
    renumber();

    // Apply initial default distribution (4 components) so mark columns appear immediately
    applyMarkDistribution([]);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
