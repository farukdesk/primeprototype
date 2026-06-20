<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/file-manager/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id < 1) { flash_set('error', 'Invalid file.'); redirect(APP_URL . '/file-manager/index.php'); }

$stmt = db()->prepare('SELECT * FROM file_manager_files WHERE id = ?');
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) { flash_set('error', 'File not found.'); redirect(APP_URL . '/file-manager/index.php'); }

// Delete all page uploaded files
$pages = fm_get_pages($id);
foreach ($pages as $p) {
    fm_delete_page_file($p['uploaded_file']);
}

// Delete the main digital copy
fm_delete_file($file['uploaded_file']);

// Delete DB record (pages/tagged users/transfers cascade via FK)
db()->prepare('DELETE FROM file_manager_files WHERE id = ?')->execute([$id]);

log_change('file-manager', 'DELETE', $id, $file['file_name']);
flash_set('success', 'File <strong>' . h($file['file_name']) . '</strong> deleted.');
redirect(APP_URL . '/file-manager/index.php');
