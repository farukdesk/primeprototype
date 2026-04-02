<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/why-choose-us/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, title FROM cms_features WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    flash_set('error', 'Feature card not found.');
    redirect(APP_URL . '/cms/why-choose-us/index.php');
}

db()->prepare('DELETE FROM cms_features WHERE id = ?')->execute([$id]);

flash_set('success', 'Feature card <strong>' . h($item['title']) . '</strong> deleted.');
redirect(APP_URL . '/cms/why-choose-us/index.php');
