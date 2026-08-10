<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-routine', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/exam-routine/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    // Items cascade-delete with the routine (fk_eri_routine).
    db()->prepare('DELETE FROM exam_routines WHERE id = ?')->execute([$id]);
    flash_set('success', 'Exam routine deleted.');
} else {
    flash_set('error', 'Routine not found.');
}
redirect(APP_URL . '/exam-routine/index.php');
