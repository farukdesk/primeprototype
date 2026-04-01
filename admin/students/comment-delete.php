<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/students/index.php');
}

csrf_check();

$comment_id = (int)($_POST['id']         ?? 0);
$student_id = (int)($_POST['student_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM student_comments WHERE id = ? AND student_id = ?');
$stmt->execute([$comment_id, $student_id]);
$comment = $stmt->fetch();

if (!$comment) {
    flash_set('error', 'Comment not found.');
    redirect(APP_URL . '/students/view.php?id=' . $student_id . '#comments');
}

$user = auth_user();

// Only super admin, staff with can_delete, or the comment owner may delete
if (!sm_can_delete() && (int)$comment['user_id'] !== (int)$user['id']) {
    flash_set('error', 'You do not have permission to delete this comment.');
    redirect(APP_URL . '/students/view.php?id=' . $student_id . '#comments');
}

db()->prepare('DELETE FROM student_comments WHERE id = ?')->execute([$comment_id]);

$student = sm_get_student($student_id);
log_change('students', 'UPDATE', $student_id,
    $student['full_name'] . ' (' . $student['student_id'] . ')',
    'comment_delete', null, null,
    'Comment deleted by ' . $user['full_name']);

flash_set('success', 'Comment deleted.');
redirect(APP_URL . '/students/view.php?id=' . $student_id . '#comments');
