<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/menus/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM cms_menus WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    flash_set('error', 'Menu item not found.');
    redirect(APP_URL . '/cms/menus/index.php');
}

// Delete item; its children will have parent_id set to NULL (SET NULL FK)
db()->prepare('DELETE FROM cms_menus WHERE id = ?')->execute([$id]);

flash_set('success', 'Menu item <strong>' . h($item['label']) . '</strong> deleted. Its child items (if any) are now top-level.');
redirect(APP_URL . '/cms/menus/index.php');
