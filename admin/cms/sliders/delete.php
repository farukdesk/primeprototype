<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/sliders/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM cms_sliders WHERE id = ?');
$stmt->execute([$id]);
$slider = $stmt->fetch();

if (!$slider) {
    flash_set('error', 'Slide not found.');
    redirect(APP_URL . '/cms/sliders/index.php');
}

// Delete image file from disk
$path = UPLOAD_DIR . '/sliders/' . $slider['image'];
if (file_exists($path)) @unlink($path);

db()->prepare('DELETE FROM cms_sliders WHERE id = ?')->execute([$id]);

flash_set('success', 'Slide deleted successfully.');
redirect(APP_URL . '/cms/sliders/index.php');
