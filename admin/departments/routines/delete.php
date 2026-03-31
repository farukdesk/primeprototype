<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_routines WHERE id = ?');
$stmt->execute([$id]);
$routine = $stmt->fetch();

if (!$routine) {
    flash_set('error', 'Routine not found.');
    redirect(APP_URL . '/departments/routines/index.php?dept_id=' . $dept_id);
}

if ($routine['file_path']) {
    $path = UPLOAD_DIR . '/departments/' . $routine['file_path'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM dept_routines WHERE id = ?')->execute([$id]);
flash_set('success', 'Routine deleted.');
redirect(APP_URL . '/departments/routines/index.php?dept_id=' . ($dept_id ?: $routine['dept_id']));
