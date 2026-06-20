<?php
/**
 * Delete a single page from a file.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/file-manager/index.php');
}
csrf_check();

$page_id = (int)($_POST['page_id'] ?? 0);
$file_id = (int)($_POST['file_id'] ?? 0);
if ($page_id < 1 || $file_id < 1) {
    flash_set('error', 'Invalid request.'); redirect(APP_URL . '/file-manager/index.php');
}

$stmt = db()->prepare('SELECT * FROM file_manager_pages WHERE id = ? AND file_id = ?');
$stmt->execute([$page_id, $file_id]);
$page = $stmt->fetch();
if (!$page) { flash_set('error', 'Page not found.'); redirect(APP_URL . '/file-manager/view.php?id=' . $file_id); }

fm_delete_page_file($page['uploaded_file']);
db()->prepare('DELETE FROM file_manager_pages WHERE id = ?')->execute([$page_id]);

log_change('file-manager', 'DELETE', $file_id, 'Page ' . $page['page_number'] . ' deleted');
flash_set('success', 'Page deleted.');
redirect(APP_URL . '/file-manager/view.php?id=' . $file_id);
