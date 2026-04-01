<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/programs/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id FROM cms_programs WHERE id = ?');
$stmt->execute([$id]);
$program = $stmt->fetch();

if (!$program) {
    flash_set('error', 'Program not found.');
    redirect(APP_URL . '/cms/programs/index.php');
}

db()->prepare('DELETE FROM cms_programs WHERE id = ?')->execute([$id]);

flash_set('success', 'Program deleted successfully.');
redirect(APP_URL . '/cms/programs/index.php');
