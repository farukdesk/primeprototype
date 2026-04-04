<?php
/**
 * Approve a newly created news article or notice that is pending approval.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/pending-changes/index.php');
}
csrf_check();

$module   = in_array($_POST['module'] ?? '', ['news','notice'], true) ? $_POST['module'] : '';
$id       = (int)($_POST['id'] ?? 0);
$reviewer = auth_user();

if (!$module || !$id) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/cms/pending-changes/index.php');
}

$db = db();

if ($module === 'news') {
    $stmt = $db->prepare('SELECT id, title, is_approved FROM cms_news WHERE id = ?');
    $stmt->execute([$id]);
    $rec = $stmt->fetch();
    if (!$rec || $rec['is_approved']) {
        flash_set('error', 'Article not found or already approved.');
        redirect(APP_URL . '/cms/news/index.php?approval=pending');
    }
    $db->prepare(
        'UPDATE cms_news SET is_approved=1, approved_by=?, approved_at=NOW() WHERE id=?'
    )->execute([$reviewer['id'], $id]);
    log_change('cms-news', 'UPDATE', $id, $rec['title'], 'is_approved', '0', '1',
        'New article approved by ' . $reviewer['name'] . '.');
    flash_set('success', 'Article <strong>' . h($rec['title']) . '</strong> approved.');
    redirect(APP_URL . '/cms/news/index.php?approval=pending');

} else {
    $stmt = $db->prepare('SELECT id, title, is_approved FROM cms_notices WHERE id = ?');
    $stmt->execute([$id]);
    $rec = $stmt->fetch();
    if (!$rec || $rec['is_approved']) {
        flash_set('error', 'Notice not found or already approved.');
        redirect(APP_URL . '/cms/notice-board/index.php?approval=pending');
    }
    $db->prepare(
        'UPDATE cms_notices SET is_approved=1, approved_by=?, approved_at=NOW() WHERE id=?'
    )->execute([$reviewer['id'], $id]);
    log_change('cms-notice-board', 'UPDATE', $id, $rec['title'], 'is_approved', '0', '1',
        'New notice approved by ' . $reviewer['name'] . '.');
    flash_set('success', 'Notice <strong>' . h($rec['title']) . '</strong> approved.');
    redirect(APP_URL . '/cms/notice-board/index.php?approval=pending');
}
