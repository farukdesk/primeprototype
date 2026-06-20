<?php
/**
 * Faculty Subject Assignment handler.
 * Faculty members submit which subjects they teach; the request goes to 'pending'
 * and must be approved by a Head of Department or super admin.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('faculty-profile', 'can_edit');
require_once __DIR__ . '/fp-helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/faculty-profiles/my-profile.php');
}
csrf_check();

$user_id   = (int)auth_user()['id'];
$course_id = (int)($_POST['course_id'] ?? 0);

if ($course_id <= 0) {
    flash_set('danger', 'Please select a subject.');
    redirect(APP_URL . '/faculty-profiles/my-profile.php?tab=subjects');
}

// Verify the course exists
$st = db()->prepare(
    "SELECT cc.id, cc.course_name, cc.course_code, dap.dept_id
       FROM course_curriculum cc
       JOIN dept_academic_programs dap ON dap.id = cc.program_id
      WHERE cc.id = ? LIMIT 1"
);
$st->execute([$course_id]);
$course = $st->fetch();

if (!$course) {
    flash_set('danger', 'Subject not found.');
    redirect(APP_URL . '/faculty-profiles/my-profile.php?tab=subjects');
}

// Verify the faculty member belongs to the same department as the subject
$fp = db()->prepare("SELECT dept_id FROM faculty_profiles WHERE user_id = ? LIMIT 1");
$fp->execute([$user_id]);
$profile = $fp->fetch();

if (!$profile || (int)$profile['dept_id'] !== (int)$course['dept_id']) {
    flash_set('danger', 'You can only request subjects from your own department.');
    redirect(APP_URL . '/faculty-profiles/my-profile.php?tab=subjects');
}

// Check for existing assignment
$ex = db()->prepare(
    "SELECT id, status FROM faculty_subject_assignments
      WHERE faculty_user_id = ? AND course_id = ? LIMIT 1"
);
$ex->execute([$user_id, $course_id]);
$existing = $ex->fetch();

if ($existing) {
    $msg = match ($existing['status']) {
        'pending'  => 'You already have a pending request for this subject.',
        'approved' => 'This subject is already assigned to you.',
        'rejected' => 'Your previous request for this subject was rejected. Please contact your department head.',
        default    => 'An assignment already exists for this subject.',
    };
    flash_set('warning', $msg);
    redirect(APP_URL . '/faculty-profiles/my-profile.php?tab=subjects');
}

// Insert pending assignment
db()->prepare(
    "INSERT INTO faculty_subject_assignments (faculty_user_id, course_id, status)
     VALUES (?, ?, 'pending')"
)->execute([$user_id, $course_id]);
$new_id = (int)db()->lastInsertId();

$label = trim(($course['course_code'] ? $course['course_code'] . ' – ' : '') . $course['course_name']);

log_change(
    'faculty-subject-assignments',
    'CREATE',
    $new_id,
    $label,
    'status',
    null,
    'pending',
    'Faculty user #' . $user_id . ' requested subject assignment for "' . $label . '"'
);

flash_set('success', 'Your request to teach <strong>' . h($label) . '</strong> has been submitted and is awaiting approval.');
redirect(APP_URL . '/faculty-profiles/my-profile.php?tab=subjects');
