<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('modules', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/modules/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM modules WHERE id = ?');
$stmt->execute([$id]);
$module = $stmt->fetch();

if (!$module) {
    flash_set('error', 'Module not found.');
    redirect(APP_URL . '/modules/index.php');
}

// Core modules should not be deleted
$core = ['dashboard', 'users', 'user-groups', 'modules', 'access'];
if (in_array($module['slug'], $core, true)) {
    flash_set('error', 'Core system modules cannot be deleted.');
    redirect(APP_URL . '/modules/index.php');
}

db()->prepare('DELETE FROM modules WHERE id = ?')->execute([$id]);
flash_set('success', 'Module deleted.');
redirect(APP_URL . '/modules/index.php');
