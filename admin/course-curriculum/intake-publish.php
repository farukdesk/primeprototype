<?php
/**
 * Toggle publish status for a course curriculum intake.
 * Publishing an intake automatically unpublishes all others for the same program.
 * Calling this on an already-published intake unpublishes it (toggle).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!cc_is_staff()) {
    flash_set('danger', 'You do not have permission to publish intakes.');
    redirect(APP_URL . '/course-curriculum/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/course-curriculum/index.php');
}
csrf_check();

$id         = (int)($_POST['id']         ?? 0);
$dept_id    = (int)($_POST['dept_id']    ?? 0);
$program_id = (int)($_POST['program_id'] ?? 0);
$intake_id  = (int)($_POST['intake_id']  ?? 0); // used to return to curriculum view

$intake = $id > 0 ? cc_get_intake($id) : null;

if (!$intake) {
    flash_set('danger', 'Intake not found.');
    redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id);
}

$is_currently_published = (bool)$intake['is_published'];

if ($is_currently_published) {
    // Unpublish
    db()->prepare("UPDATE course_curriculum_intakes SET is_published = 0 WHERE id = ?")
        ->execute([$id]);
    flash_set('success', 'Intake <strong>' . h($intake['batch_name']) . '</strong> unpublished.');
} else {
    // Publish this one; unpublish all others for the same program
    $pdo = db();
    $pdo->prepare(
        "UPDATE course_curriculum_intakes SET is_published = 0 WHERE program_id = ?"
    )->execute([(int)$intake['program_id']]);
    $pdo->prepare(
        "UPDATE course_curriculum_intakes SET is_published = 1 WHERE id = ?"
    )->execute([$id]);
    flash_set('success', 'Intake <strong>' . h($intake['batch_name']) . '</strong> is now published.');
}

// Return to curriculum view if intake_id was passed, otherwise to intake list
$back = APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id;
if ($intake_id > 0) {
    $back .= '&intake_id=' . $intake_id;
}
redirect($back);
