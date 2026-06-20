<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-clubs', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_clubs WHERE id = ?');
$stmt->execute([$id]);
$club = $stmt->fetch();

if (!$club) {
    flash_set('error', 'Club not found.');
    redirect(APP_URL . '/departments/clubs/index.php?dept_id=' . $dept_id);
}
$dept_id = (int)$club['dept_id'];
require_access_dept($dept_id);

if ($club['logo']) {
    $path = UPLOAD_DIR . '/departments/' . $club['logo'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM dept_clubs WHERE id = ?')->execute([$id]);
flash_set('success', 'Club deleted.');
redirect(APP_URL . '/departments/clubs/index.php?dept_id=' . ($dept_id ?: $club['dept_id']));
