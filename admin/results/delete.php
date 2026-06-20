<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('results', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/results/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$exam = rm_get_exam($id);

db()->prepare('DELETE FROM result_exams WHERE id = ?')->execute([$id]);

flash_set('success', 'Result exam <strong>' . h($exam['exam_title']) . '</strong> deleted.');
redirect(APP_URL . '/results/index.php');
