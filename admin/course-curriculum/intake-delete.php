<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!cc_is_staff()) {
    flash_set('danger', 'You do not have permission to delete intakes.');
    redirect(APP_URL . '/course-curriculum/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/course-curriculum/index.php');
}
csrf_check();

$id         = (int)($_POST['id']         ?? 0);
$dept_id    = (int)($_POST['dept_id']    ?? 0);
$program_id = (int)($_POST['program_id'] ?? 0);

$intake = $id > 0 ? cc_get_intake($id) : null;

if (!$intake) {
    flash_set('danger', 'Intake not found.');
    redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id);
}

// Courses are deleted via ON DELETE CASCADE on the FK
db()->prepare("DELETE FROM course_curriculum_intakes WHERE id = ?")->execute([$id]);

flash_set('success', 'Intake <strong>' . h($intake['batch_name']) . '</strong> and all its courses deleted.');
redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id);
