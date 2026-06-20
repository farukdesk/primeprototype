<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/alumni/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM cms_alumni WHERE id = ?');
$stmt->execute([$id]);
$al = $stmt->fetch();

if (!$al) {
    flash_set('error', 'Alumni record not found.');
    redirect(APP_URL . '/cms/alumni/index.php');
}

if ($al['photo']) {
    $path = UPLOAD_DIR . '/alumni/' . $al['photo'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM cms_alumni WHERE id = ?')->execute([$id]);

flash_set('success', 'Alumni deleted successfully.');
redirect(APP_URL . '/cms/alumni/index.php');
