<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-references', 'can_delete');

csrf_check();

$valid_types = ['batches', 'exams', 'boards', 'groups'];
$type = $_POST['type'] ?? '';
$id   = (int)($_POST['id'] ?? 0);

if (!in_array($type, $valid_types, true) || $id <= 0) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/student-references/index.php');
}

$table_map = [
    'batches' => 'student_batches',
    'exams'   => 'student_exam_titles',
    'boards'  => 'student_boards',
    'groups'  => 'student_groups',
];

$table = $table_map[$type];
db()->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);

flash_set('success', 'Record deleted.');
redirect(APP_URL . '/student-references/index.php?tab=' . urlencode($type));
