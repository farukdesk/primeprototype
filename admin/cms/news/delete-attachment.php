<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/news/index.php');
}
csrf_check();

$att_id  = (int)($_POST['id']      ?? 0);
$news_id = (int)($_POST['news_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM cms_news_attachments WHERE id = ? AND news_id = ?');
$stmt->execute([$att_id, $news_id]);
$att = $stmt->fetch();

if (!$att) {
    flash_set('error', 'Attachment not found.');
    redirect(APP_URL . '/cms/news/edit.php?id=' . $news_id);
}

// Remove file from disk
$path = UPLOAD_DIR . '/news/' . $att['stored_name'];
if (file_exists($path)) @unlink($path);

// Remove DB record
db()->prepare('DELETE FROM cms_news_attachments WHERE id = ?')->execute([$att_id]);

flash_set('success', 'Attachment <strong>' . h($att['original_name']) . '</strong> deleted.');
redirect(APP_URL . '/cms/news/edit.php?id=' . $news_id);
