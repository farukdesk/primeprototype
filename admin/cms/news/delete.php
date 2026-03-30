<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/news/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM cms_news WHERE id = ?');
$stmt->execute([$id]);
$news = $stmt->fetch();

if (!$news) {
    flash_set('error', 'Article not found.');
    redirect(APP_URL . '/cms/news/index.php');
}

$db = db();

// Delete all attachment files from disk
$atts = $db->prepare('SELECT stored_name FROM cms_news_attachments WHERE news_id = ?');
$atts->execute([$id]);
foreach ($atts->fetchAll() as $att) {
    $path = UPLOAD_DIR . '/news/' . $att['stored_name'];
    if (file_exists($path)) @unlink($path);
}

// Delete featured image from disk
if ($news['featured_image']) {
    $path = UPLOAD_DIR . '/news/' . $news['featured_image'];
    if (file_exists($path)) @unlink($path);
}

// Delete DB record (cascade removes attachments)
$db->prepare('DELETE FROM cms_news WHERE id = ?')->execute([$id]);

flash_set('success', 'Article <strong>' . h($news['title']) . '</strong> deleted.');
redirect(APP_URL . '/cms/news/index.php');
