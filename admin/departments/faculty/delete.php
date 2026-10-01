<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-faculty', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_faculty WHERE id = ?');
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member) {
    flash_set('error', 'Faculty member not found.');
    redirect(APP_URL . '/departments/faculty/index.php?dept_id=' . $dept_id);
}
$dept_id = (int)$member['dept_id'];
require_access_dept($dept_id);

// Block deletion when this faculty member is assigned as a course teacher.
// Deleting the row cascade-removes co_offer_subject_teachers entries and
// nulls course_curriculum.assigned_faculty_id, which silently removes the
// teacher from result sheets, grade sheets and marks entry (including for
// already published results).
$refs = [];
try {
    $st = db()->prepare('SELECT COUNT(*) FROM co_offer_subject_teachers WHERE faculty_id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) $refs[] = 'course offer subjects';
} catch (Throwable $_e) {}
try {
    $st = db()->prepare('SELECT COUNT(*) FROM course_curriculum WHERE assigned_faculty_id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) $refs[] = 'course curriculum subjects';
} catch (Throwable $_e) {}
if ($refs) {
    flash_set('error', 'Cannot delete <strong>' . h($member['name']) . '</strong>: this faculty member is assigned as a teacher on ' .
        h(implode(' and ', $refs)) . '. Deleting would remove the teacher from result sheets, grade sheets and marks entry. ' .
        'Mark the member as <strong>Inactive</strong> instead, or remove the teaching assignments first.');
    redirect(APP_URL . '/departments/faculty/index.php?dept_id=' . $dept_id);
}

if ($member['photo']) {
    $path = UPLOAD_DIR . '/departments/' . $member['photo'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM dept_faculty WHERE id = ?')->execute([$id]);
flash_set('success', 'Faculty member deleted.');
redirect(APP_URL . '/departments/faculty/index.php?dept_id=' . ($dept_id ?: $member['dept_id']));
