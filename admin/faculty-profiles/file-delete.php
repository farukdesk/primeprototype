<?php
/**
 * Delete a faculty file.
 * Only Register Office staff and super admins can delete files.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/fp-helpers.php';

if (!fp_can_delete_files()) {
    flash_set('error', 'Access denied.');
    redirect(APP_URL . '/faculty-profiles/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/faculty-profiles/index.php');
}

csrf_check();

$file_id = (int)($_POST['file_id'] ?? 0);
$user_id = (int)($_POST['user_id'] ?? 0);

if (!$file_id) {
    flash_set('error', 'Invalid file.');
    redirect(APP_URL . '/faculty-profiles/index.php');
}

// Load file record
$stmt = db()->prepare(
    'SELECT ff.*, u.full_name AS faculty_name
     FROM faculty_files ff
     JOIN users u ON u.id = ff.user_id
     WHERE ff.id = ?'
);
$stmt->execute([$file_id]);
$file = $stmt->fetch();

if (!$file) {
    flash_set('error', 'File not found.');
    $redirect = $user_id ? APP_URL . '/faculty-profiles/files.php?user_id=' . $user_id : APP_URL . '/faculty-profiles/index.php';
    redirect($redirect);
}

// Remove physical file
$path = UPLOAD_DIR . '/faculty-profiles/files/' . basename($file['stored_name']);
if (file_exists($path)) @unlink($path);

// Delete DB record
db()->prepare('DELETE FROM faculty_files WHERE id = ?')->execute([$file_id]);

log_change('faculty-files', 'DELETE', $file_id,
    $file['faculty_name'] . ' – ' . $file['file_name'],
    'file_delete', $file['file_name'], null,
    'File deleted: ' . $file['file_name']);

flash_set('success', 'File "' . h($file['file_name']) . '" deleted.');

$uid = (int)$file['user_id'];
redirect(APP_URL . '/faculty-profiles/files.php?user_id=' . $uid . '#files');
