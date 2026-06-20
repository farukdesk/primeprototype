<?php
/**
 * Delete or request deletion of a faculty file.
 * Super admins delete directly; Register Office staff submit a delete request
 * that must be approved by a super admin.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/fp-helpers.php';

if (!fp_can_request_delete()) {
    flash_set('error', 'Access denied.');
    redirect(APP_URL . '/faculty-profiles/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/faculty-profiles/index.php');
}

csrf_check();

$action  = trim($_POST['action'] ?? 'delete'); // 'delete' (legacy/super-admin) or 'request_delete'
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

$uid = (int)$file['user_id'];

// ── Super admin: direct deletion ─────────────────────────────────────────────
if (is_super_admin()) {
    $path = UPLOAD_DIR . '/faculty-profiles/files/' . basename($file['stored_name']);
    if (file_exists($path)) @unlink($path);

    db()->prepare('DELETE FROM faculty_files WHERE id = ?')->execute([$file_id]);

    log_change('faculty-files', 'DELETE', $file_id,
        $file['faculty_name'] . ' – ' . $file['file_name'],
        'file_delete', $file['file_name'], null,
        'File deleted: ' . $file['file_name']);

    flash_set('success', 'File "' . h($file['file_name']) . '" deleted.');
    redirect(APP_URL . '/faculty-profiles/files.php?user_id=' . $uid . '#files');
}

// ── Non-super-admin: queue a delete request ───────────────────────────────────
$current_user = auth_user();
$request_note = trim($_POST['request_note'] ?? '');

// Check for an existing pending request for this file
$existing = db()->prepare(
    "SELECT id FROM faculty_file_delete_requests WHERE file_id = ? AND status = 'pending'"
);
$existing->execute([$file_id]);
if ($existing->fetch()) {
    flash_set('error', 'A delete request for this file is already pending approval.');
    redirect(APP_URL . '/faculty-profiles/files.php?user_id=' . $uid . '#files');
}

db()->prepare(
    'INSERT INTO faculty_file_delete_requests
       (file_id, file_name, faculty_user_id, faculty_name, stored_name, requested_by, request_note)
     VALUES (?,?,?,?,?,?,?)'
)->execute([
    $file_id,
    $file['file_name'],
    $uid,
    $file['faculty_name'],
    $file['stored_name'],
    $current_user['id'],
    $request_note ?: null,
]);

log_change('faculty-files', 'DELETE_REQUEST', $file_id,
    $file['faculty_name'] . ' – ' . $file['file_name'],
    'file_delete_request', $file['file_name'], null,
    'Delete request submitted by ' . $current_user['full_name']);

flash_set('success', 'Delete request for "' . h($file['file_name']) . '" submitted. A super administrator will review it.');
redirect(APP_URL . '/faculty-profiles/files.php?user_id=' . $uid . '#files');
