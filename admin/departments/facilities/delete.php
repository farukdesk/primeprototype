<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-facilities', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_facilities WHERE id = ?');
$stmt->execute([$id]);
$facility = $stmt->fetch();

if (!$facility) {
    flash_set('error', 'Facility not found.');
    redirect(APP_URL . '/departments/facilities/index.php?dept_id=' . $dept_id);
}
$dept_id = (int)$facility['dept_id'];
require_access_dept($dept_id);

if ($facility['image']) {
    $path = UPLOAD_DIR . '/departments/' . $facility['image'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM dept_facilities WHERE id = ?')->execute([$id]);
flash_set('success', 'Facility deleted.');
redirect(APP_URL . '/departments/facilities/index.php?dept_id=' . ($dept_id ?: $facility['dept_id']));
