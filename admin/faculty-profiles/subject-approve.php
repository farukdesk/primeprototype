<?php
/**
 * Approve or reject a faculty subject assignment request.
 * Only Head of Department (for their dept) or super admins may action this.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/fp-helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if (!fp_can_approve_subjects()) {
    flash_set('error', 'You do not have permission to approve subject assignments.');
    redirect(APP_URL . '/faculty-profiles/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/faculty-profiles/pending-subjects.php');
}
csrf_check();

$assignment_id = (int)($_POST['id']     ?? 0);
$action        = trim($_POST['action']  ?? '');
$notes         = trim($_POST['notes']   ?? '');

if (!in_array($action, ['approve', 'reject'], true) || $assignment_id <= 0) {
    flash_set('danger', 'Invalid request.');
    redirect(APP_URL . '/faculty-profiles/pending-subjects.php');
}

// Fetch the assignment
$st = db()->prepare(
    "SELECT fsa.*,
            cc.course_name, cc.course_code,
            dap.dept_id,
            u.full_name AS faculty_name
       FROM faculty_subject_assignments fsa
       JOIN course_curriculum cc ON cc.id = fsa.course_id
       JOIN dept_academic_programs dap ON dap.id = cc.program_id
       JOIN users u ON u.id = fsa.faculty_user_id
      WHERE fsa.id = ? LIMIT 1"
);
$st->execute([$assignment_id]);
$asgn = $st->fetch();

if (!$asgn) {
    flash_set('danger', 'Assignment request not found.');
    redirect(APP_URL . '/faculty-profiles/pending-subjects.php');
}

if ($asgn['status'] !== 'pending') {
    flash_set('warning', 'This request has already been ' . $asgn['status'] . '.');
    redirect(APP_URL . '/faculty-profiles/pending-subjects.php');
}

// Scope check: HoD can only approve subjects in their own department(s)
$dept_ids = fp_head_dept_ids();
if ($dept_ids !== null && !empty($dept_ids) && !in_array((int)$asgn['dept_id'], $dept_ids, true)) {
    flash_set('error', 'You are not the head of the department that owns this subject.');
    redirect(APP_URL . '/faculty-profiles/pending-subjects.php');
}

$reviewer_id = (int)auth_user()['id'];
$new_status  = $action === 'approve' ? 'approved' : 'rejected';

db()->prepare(
    "UPDATE faculty_subject_assignments
        SET status=?, reviewed_by=?, reviewed_at=NOW(), notes=?
      WHERE id=?"
)->execute([$new_status, $reviewer_id, $notes ?: null, $assignment_id]);

// When approved, update course_curriculum.assigned_faculty_id to this faculty.
// When rejected, clear the assignment only if it was set to this faculty.
$df_st = db()->prepare(
    "SELECT id FROM dept_faculty WHERE user_id = ? AND dept_id = ? AND is_active = 1 LIMIT 1"
);
$df_st->execute([(int)$asgn['faculty_user_id'], (int)$asgn['dept_id']]);
$df_row = $df_st->fetch();

if ($df_row) {
    $df_id = (int)$df_row['id'];
    if ($action === 'approve') {
        db()->prepare(
            "UPDATE course_curriculum SET assigned_faculty_id = ? WHERE id = ?"
        )->execute([$df_id, (int)$asgn['course_id']]);
    } else {
        // Only remove the assignment if it currently belongs to this faculty member.
        db()->prepare(
            "UPDATE course_curriculum SET assigned_faculty_id = NULL
              WHERE id = ? AND assigned_faculty_id = ?"
        )->execute([(int)$asgn['course_id'], $df_id]);
    }
} elseif ($action === 'approve') {
    // No active dept_faculty record found; mark the assignment back to pending and notify.
    db()->prepare(
        "UPDATE faculty_subject_assignments SET status='pending', reviewed_by=NULL, reviewed_at=NULL WHERE id=?"
    )->execute([$assignment_id]);
    flash_set('danger',
        'Cannot approve: <strong>' . h($asgn['faculty_name']) . '</strong> does not have an active faculty record '
        . 'in this department. Please add them to the department faculty list first.'
    );
    redirect(APP_URL . '/faculty-profiles/pending-subjects.php');
}

$label = trim(($asgn['course_code'] ? $asgn['course_code'] . ' – ' : '') . $asgn['course_name']);

log_change(
    'faculty-subject-assignments',
    'UPDATE',
    $assignment_id,
    $label,
    'status',
    'pending',
    $new_status,
    'Assignment for faculty "' . $asgn['faculty_name'] . '" ' . $new_status . ' by reviewer #' . $reviewer_id
        . ($notes ? '. Notes: ' . $notes : '')
);

$msg_type = $action === 'approve' ? 'success' : 'warning';
$msg_text = $action === 'approve'
    ? '<strong>' . h($asgn['faculty_name']) . '</strong> approved to teach <strong>' . h($label) . '</strong>.'
    : 'Assignment rejected for <strong>' . h($asgn['faculty_name']) . '</strong> – <strong>' . h($label) . '</strong>.';

flash_set($msg_type, $msg_text);
redirect(APP_URL . '/faculty-profiles/pending-subjects.php');
