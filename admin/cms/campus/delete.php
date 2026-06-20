<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/campus/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM cms_campus_items WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    flash_set('error', 'Campus item not found.');
    redirect(APP_URL . '/cms/campus/index.php');
}

if ($item['image']) {
    $path = UPLOAD_DIR . '/campus/' . $item['image'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM cms_campus_items WHERE id = ?')->execute([$id]);

flash_set('success', 'Campus item deleted successfully.');
redirect(APP_URL . '/cms/campus/index.php');
