<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-vc', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/office-of-vc/former-vc-index.php');
}

csrf_check();

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/office-of-vc/former-vc-index.php');
}

$stmt = db()->prepare('SELECT * FROM vc_former_vcs WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$rec = $stmt->fetch();

if (!$rec) {
    flash_set('error', 'Record not found.');
    redirect(APP_URL . '/office-of-vc/former-vc-index.php');
}

// Remove photo file
if ($rec['photo']) {
    $path = UPLOAD_DIR . '/office-of-vc/' . $rec['photo'];
    if (is_file($path)) @unlink($path);
}

db()->prepare('DELETE FROM vc_former_vcs WHERE id = ?')->execute([$id]);

flash_set('success', 'Former VC <strong>' . h($rec['name']) . '</strong> removed.');
redirect(APP_URL . '/office-of-vc/former-vc-index.php');
