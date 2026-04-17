<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('results', 'can_delete');
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(APP_URL . '/results/index.php'); }
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$exam_id = (int)($_POST['exam_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM result_subjects WHERE id = ? AND exam_id = ?');
$stmt->execute([$id, $exam_id]);
$subject = $stmt->fetch();

if (!$subject) {
    flash_set('error', 'Subject not found.');
    redirect(APP_URL . '/results/view.php?id=' . $exam_id . '&tab=subjects');
}

db()->prepare('DELETE FROM result_subjects WHERE id = ?')->execute([$id]);

flash_set('success', 'Subject <strong>' . h($subject['course_title']) . '</strong> deleted.');
redirect(APP_URL . '/results/view.php?id=' . $exam_id . '&tab=subjects');
