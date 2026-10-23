<?php
/**
 * Download a profile file attachment (Issues/Complaints or Evaluation Record).
 * Accessible to admins (staff-profile can_edit/can_view admins) and to the
 * employee the file belongs to.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/sp-helpers.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(404);
    exit('File not found.');
}

sp_ensure_files_table();
$st = db()->prepare('SELECT * FROM staff_profile_files WHERE id = ?');
$st->execute([$id]);
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    exit('File not found.');
}

$is_owner = (int)$row['user_id'] === (int)auth_user()['id'];
if (!$is_owner && !sp_is_admin()) {
    require_access('staff-profile', 'can_edit');
}

$abs = UPLOAD_DIR . '/' . SP_FILES_SUBDIR . '/' . basename($row['file_name']);
if (!is_file($abs) || !is_readable($abs)) {
    http_response_code(404);
    exit('File is missing from storage.');
}

$download_name = $row['original_name'] ?: $row['file_name'];
// Keep a safe ASCII fallback name alongside the UTF-8 one.
$ascii = preg_replace('/[^\x20-\x7E]/', '_', $download_name) ?: 'file';

header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $ascii) . '"; '
    . "filename*=UTF-8''" . rawurlencode($download_name));
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;
