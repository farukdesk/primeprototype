<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/code-of-conduct/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id FROM cms_coc_items WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    flash_set('error', 'Item not found.');
    redirect(APP_URL . '/cms/code-of-conduct/index.php');
}

db()->prepare('DELETE FROM cms_coc_items WHERE id = ?')->execute([$id]);

flash_set('success', 'Conduct item deleted.');
redirect(APP_URL . '/cms/code-of-conduct/index.php');
