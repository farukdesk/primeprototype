<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!cc_is_staff()) {
    flash_set('danger', 'You do not have permission to delete courses.');
    redirect(APP_URL . '/course-curriculum/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/course-curriculum/index.php');
}
csrf_check();

$id         = (int)($_POST['id']         ?? 0);
$dept_id    = (int)($_POST['dept_id']    ?? 0);
$program_id = (int)($_POST['program_id'] ?? 0);

// Fetch and verify the course row exists
$st = db()->prepare("SELECT * FROM course_curriculum WHERE id = ? LIMIT 1");
$st->execute([$id]);
$course = $st->fetch();

if (!$course) {
    flash_set('danger', 'Course not found.');
    redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id);
}

db()->prepare("DELETE FROM course_curriculum WHERE id = ?")->execute([$id]);

flash_set('success', 'Course <strong>' . h($course['course_name']) . '</strong> deleted.');
redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id);
