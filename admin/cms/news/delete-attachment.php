<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_access('cms-news', 'can_edit');

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

// Only super admins may delete attachments directly; others need edit permission on the article
$is_super    = is_super_admin();
$current_user = auth_user();
if (!$is_super) {
    $rec = db()->prepare('SELECT created_by FROM cms_news WHERE id = ?');
    $rec->execute([$news_id]);
    $row = $rec->fetch();
    if (!$row || (int)($row['created_by'] ?? 0) !== (int)$current_user['id']) {
        flash_set('error', 'You do not have permission to delete this attachment.');
        redirect(APP_URL . '/cms/news/edit.php?id=' . $news_id);
    }
}

// Remove file from disk
$path = UPLOAD_DIR . '/news/' . $att['stored_name'];
if (file_exists($path)) @unlink($path);

// Remove DB record
db()->prepare('DELETE FROM cms_news_attachments WHERE id = ?')->execute([$att_id]);

log_change('cms-news', 'UPDATE', $news_id, null, 'attachment', $att['original_name'], null,
    'Attachment "' . $att['original_name'] . '" deleted from article #' . $news_id . '.');

flash_set('success', 'Attachment <strong>' . h($att['original_name']) . '</strong> deleted.');
redirect(APP_URL . '/cms/news/edit.php?id=' . $news_id);
