<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-alumni', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_alumni WHERE id = ?');
$stmt->execute([$id]);
$alumni = $stmt->fetch();

if (!$alumni) {
    flash_set('error', 'Alumni not found.');
    redirect(APP_URL . '/departments/alumni/index.php?dept_id=' . $dept_id);
}
$dept_id = (int)$alumni['dept_id'];
require_access_dept($dept_id);

if ($alumni['photo']) {
    $path = UPLOAD_DIR . '/departments/' . $alumni['photo'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM dept_alumni WHERE id = ?')->execute([$id]);
flash_set('success', 'Alumni deleted.');
redirect(APP_URL . '/departments/alumni/index.php?dept_id=' . ($dept_id ?: $alumni['dept_id']));
