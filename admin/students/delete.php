<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('students', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/students/index.php');
}

csrf_check();

$id      = (int)($_POST['id'] ?? 0);
$student = sm_get_student($id);

// Remove photo
if ($student['photo']) {
    $p = UPLOAD_DIR . '/students/photos/' . $student['photo'];
    if (is_file($p)) @unlink($p);
}

// Remove only unshared associated files.
$files_stmt = db()->prepare('SELECT DISTINCT stored_name FROM student_files WHERE student_id = ?');
$files_stmt->execute([$id]);
foreach ($files_stmt->fetchAll() as $f) {
    $ref_stmt = db()->prepare(
        'SELECT COUNT(*) FROM student_files WHERE stored_name = ? AND student_id <> ?'
    );
    $ref_stmt->execute([$f['stored_name'], $id]);
    if ((int)$ref_stmt->fetchColumn() === 0) {
        $fp = UPLOAD_DIR . '/students/files/' . $f['stored_name'];
        if (is_file($fp)) @unlink($fp);
    }
}

$label = $student['full_name'] . ' (' . $student['student_id'] . ')';

// Delete the student (cascades to qualifications, files, comments)
db()->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);

log_change('students', 'DELETE', $id, $label, null, null, null, 'Student deleted: ' . $label);

flash_set('success', 'Student <strong>' . h($student['full_name']) . '</strong> has been deleted.');
redirect(APP_URL . '/students/index.php');
