<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$st = db()->prepare('SELECT * FROM dept_hero_slides WHERE id = ?');
$st->execute([$id]);
$slide = $st->fetch();

if (!$slide) {
    flash_set('error', 'Slide not found.');
    redirect(APP_URL . '/departments/index.php');
}

$dept_id = $dept_id ?: (int)$slide['dept_id'];

// Delete image file from disk
$path = UPLOAD_DIR . '/departments/' . $slide['image'];
if (file_exists($path)) @unlink($path);

db()->prepare('DELETE FROM dept_hero_slides WHERE id = ?')->execute([$id]);

flash_set('success', 'Hero slide deleted.');
redirect(APP_URL . '/departments/hero-slides/index.php?dept_id=' . $dept_id);
