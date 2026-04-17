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

// Track associated files so we can clean up unreferenced files after delete.
$files_stmt = db()->prepare('SELECT DISTINCT stored_name FROM student_files WHERE student_id = ?');
$files_stmt->execute([$id]);
$stored_names = array_map(fn($r) => (string)$r['stored_name'], $files_stmt->fetchAll());

$label = $student['full_name'] . ' (' . $student['student_id'] . ')';

try {
    db()->beginTransaction();

    // Delete the student (cascades to qualifications, files, comments).
    db()->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);

    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    flash_set('error', 'Could not delete student right now. Please try again.');
    redirect(APP_URL . '/students/index.php');
}

if (!empty($stored_names)) {
    $ref_stmt = db()->prepare('SELECT COUNT(*) FROM student_files WHERE stored_name = ?');
    foreach ($stored_names as $stored_name) {
        $ref_stmt->execute([$stored_name]);
        if ((int)$ref_stmt->fetchColumn() === 0) {
            $fp = UPLOAD_DIR . '/students/files/' . $stored_name;
            if (is_file($fp)) @unlink($fp);
        }
    }
}

log_change('students', 'DELETE', $id, $label, null, null, null, 'Student deleted: ' . $label);

flash_set('success', 'Student <strong>' . h($student['full_name']) . '</strong> has been deleted.');
redirect(APP_URL . '/students/index.php');
