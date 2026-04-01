<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('students', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/students/index.php');
}

csrf_check();

$file_id    = (int)($_POST['id']         ?? 0);
$student_id = (int)($_POST['student_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM student_files WHERE id = ? AND student_id = ?');
$stmt->execute([$file_id, $student_id]);
$file = $stmt->fetch();

if (!$file) {
    flash_set('error', 'File not found.');
    redirect(APP_URL . '/students/view.php?id=' . $student_id . '#files');
}

// Remove physical file
$path = UPLOAD_DIR . '/students/files/' . $file['stored_name'];
if (is_file($path)) @unlink($path);

db()->prepare('DELETE FROM student_files WHERE id = ?')->execute([$file_id]);

$student = sm_get_student($student_id);
log_change('students', 'UPDATE', $student_id,
    $student['full_name'] . ' (' . $student['student_id'] . ')',
    'file_delete', $file['file_name'], null,
    'File deleted: ' . $file['file_name']);

flash_set('success', 'File <strong>' . h($file['file_name']) . '</strong> deleted.');
redirect(APP_URL . '/students/view.php?id=' . $student_id . '#files');
