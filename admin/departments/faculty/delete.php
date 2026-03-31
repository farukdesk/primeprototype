<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_faculty WHERE id = ?');
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member) {
    flash_set('error', 'Faculty member not found.');
    redirect(APP_URL . '/departments/faculty/index.php?dept_id=' . $dept_id);
}

if ($member['photo']) {
    $path = UPLOAD_DIR . '/departments/' . $member['photo'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM dept_faculty WHERE id = ?')->execute([$id]);
flash_set('success', 'Faculty member deleted.');
redirect(APP_URL . '/departments/faculty/index.php?dept_id=' . ($dept_id ?: $member['dept_id']));
