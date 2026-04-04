<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_access('cms-news', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/news/index.php');
}
csrf_check();

$id           = (int)($_POST['id'] ?? 0);
$is_super     = is_super_admin();
$current_user = auth_user();

$stmt = db()->prepare('SELECT * FROM cms_news WHERE id = ?');
$stmt->execute([$id]);
$news = $stmt->fetch();

if (!$news) {
    flash_set('error', 'Article not found.');
    redirect(APP_URL . '/cms/news/index.php');
}

// Non-super admins may only request deletion of their own articles
if (!$is_super && (int)($news['created_by'] ?? 0) !== (int)$current_user['id']) {
    flash_set('error', 'You do not have permission to delete this article.');
    redirect(APP_URL . '/cms/news/index.php');
}

$db = db();

if ($is_super) {
    // ── Super admin: delete immediately ────────────────────────────────
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

    log_change('cms-news', 'DELETE', $id, $news['title'], null, null, null,
        'Article deleted directly by super admin.');

    // Delete DB record (cascade removes attachments)
    $db->prepare('DELETE FROM cms_news WHERE id = ?')->execute([$id]);

    flash_set('success', 'Article <strong>' . h($news['title']) . '</strong> deleted.');
} else {
    // ── Non-super admin: queue delete request for approval ───────────
    // Prevent duplicate pending delete requests
    $existing = $db->prepare(
        "SELECT id FROM cms_pending_changes
         WHERE module='news' AND record_id=? AND action='DELETE' AND status='pending'"
    );
    $existing->execute([$id]);
    if ($existing->fetch()) {
        flash_set('info', 'A deletion request for this article is already awaiting approval.');
        redirect(APP_URL . '/cms/news/index.php');
    }

    $db->prepare(
        "INSERT INTO cms_pending_changes
         (module, record_id, record_title, action, requested_by)
         VALUES ('news', ?, ?, 'DELETE', ?)"
    )->execute([$id, $news['title'], $current_user['id']]);

    log_change('cms-news', 'DELETE', $id, $news['title'], null, null, null,
        'Delete request submitted by ' . $current_user['name'] . ' – awaiting super-admin approval.');

    flash_set('success', 'Deletion request for <strong>' . h($news['title']) . '</strong> submitted for super-admin approval.');
}

redirect(APP_URL . '/cms/news/index.php');
