<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-notices', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_notices WHERE id = ?');
$stmt->execute([$id]);
$notice = $stmt->fetch();

if (!$notice) {
    flash_set('error', 'Notice not found.');
    redirect(APP_URL . '/departments/notices/index.php?dept_id=' . $dept_id);
}
$dept_id = (int)$notice['dept_id'];
require_access_dept($dept_id);

if ($notice['attachment']) {
    $path = UPLOAD_DIR . '/departments/' . $notice['attachment'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM dept_notices WHERE id = ?')->execute([$id]);
flash_set('success', 'Notice deleted.');
redirect(APP_URL . '/departments/notices/index.php?dept_id=' . ($dept_id ?: $notice['dept_id']));
