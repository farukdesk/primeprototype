<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/notice-board/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM cms_notices WHERE id = ?');
$stmt->execute([$id]);
$notice = $stmt->fetch();

if (!$notice) {
    flash_set('error', 'Notice not found.');
    redirect(APP_URL . '/cms/notice-board/index.php');
}

$db = db();

// Delete associated news row if any
if ($notice['news_id']) {
    $db->prepare('DELETE FROM cms_news WHERE id = ?')->execute([$notice['news_id']]);
}

// Delete attachment file from disk
if ($notice['attachment']) {
    $path = UPLOAD_DIR . '/notices/' . $notice['attachment'];
    if (file_exists($path)) @unlink($path);
}

// Delete the notice record
$db->prepare('DELETE FROM cms_notices WHERE id = ?')->execute([$id]);

flash_set('success', 'Notice <strong>' . h($notice['title']) . '</strong> deleted.');
redirect(APP_URL . '/cms/notice-board/index.php');
