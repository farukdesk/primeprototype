<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_prime_pride WHERE id = ?');
$stmt->execute([$id]);
$pp = $stmt->fetch();

if (!$pp) {
    flash_set('error', 'Entry not found.');
    redirect(APP_URL . '/departments/prime-pride/index.php?dept_id=' . $dept_id);
}

if ($pp['image']) {
    $path = UPLOAD_DIR . '/departments/' . $pp['image'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM dept_prime_pride WHERE id = ?')->execute([$id]);
flash_set('success', 'Prime Pride entry deleted.');
redirect(APP_URL . '/departments/prime-pride/index.php?dept_id=' . ($dept_id ?: $pp['dept_id']));
