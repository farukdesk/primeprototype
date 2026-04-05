<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-academic-programs', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_academic_programs WHERE id = ?');
$stmt->execute([$id]);
$program = $stmt->fetch();

if (!$program) {
    flash_set('error', 'Program not found.');
    redirect(APP_URL . '/departments/academic-programs/index.php?dept_id=' . $dept_id);
}
$dept_id = (int)$program['dept_id'];
require_access_dept($dept_id);

// Delete attachment file if it exists
if (!empty($program['attachment'])) {
    $file = UPLOAD_DIR . '/departments/' . $program['attachment'];
    if (file_exists($file)) @unlink($file);
}

db()->prepare('DELETE FROM dept_academic_programs WHERE id = ?')->execute([$id]);
flash_set('success', 'Academic program deleted.');
redirect(APP_URL . '/departments/academic-programs/index.php?dept_id=' . ($dept_id ?: $program['dept_id']));
