<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-routine', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/exam-routine/index.php');
}
csrf_check();

// Accepts a single id (row delete button) and/or ids[] (bulk delete).
$ids = [];
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    foreach ($_POST['ids'] as $v) {
        $v = (int)$v;
        if ($v > 0) $ids[] = $v;
    }
}
$single = (int)($_POST['id'] ?? 0);
if ($single > 0) {
    $ids[] = $single;
}
$ids = array_values(array_unique($ids));

if ($ids) {
    // Items cascade-delete with the routine (fk_eri_routine).
    $ph = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("DELETE FROM exam_routines WHERE id IN ($ph)")->execute($ids);
    flash_set('success', count($ids) === 1
        ? 'Exam routine deleted.'
        : count($ids) . ' exam routines deleted.');
} else {
    flash_set('error', 'No routines selected.');
}
redirect(APP_URL . '/exam-routine/index.php');
