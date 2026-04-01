<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../helpers.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Invalid resource ID.');
    redirect(APP_URL . '/library/digital/index.php');
}

$stmt = db()->prepare('SELECT * FROM library_digital_resources WHERE id = ? AND is_active = 1');
$stmt->execute([$id]);
$resource = $stmt->fetch();

if (!$resource) {
    flash_set('error', 'Resource not found or not active.');
    redirect(APP_URL . '/library/digital/index.php');
}

// Access level check
$user = auth_user();
$access = $resource['access_level'];
$allowed = false;

if ($access === 'Public') {
    $allowed = true;
} elseif ($access === 'Admin') {
    $allowed = is_super_admin();
} elseif ($access === 'Staff') {
    $allowed = is_super_admin() || lib_is_staff();
} elseif ($access === 'Faculty') {
    $allowed = is_super_admin() || lib_is_staff() || can_access('faculty-profiles', 'can_view');
} elseif ($access === 'Students') {
    $allowed = true; // Any authenticated admin user
}

if (!$allowed) {
    flash_set('error', 'You do not have permission to access this resource.');
    redirect(APP_URL . '/library/digital/index.php');
}

// Increment download count
db()->prepare('UPDATE library_digital_resources SET download_count = download_count + 1 WHERE id = ?')->execute([$id]);

// Audit
lib_audit('DIGITAL_DOWNLOAD', 'library-digital', $id, $resource['title'], 'Downloaded: ' . $resource['original_name']);

// Serve file
$file_path = UPLOAD_DIR . '/library/digital/' . $resource['file_name'];

if (!is_file($file_path)) {
    flash_set('error', 'File not found on server.');
    redirect(APP_URL . '/library/digital/index.php');
}

$mime = $resource['mime_type'] ?: 'application/octet-stream';
$original = $resource['original_name'] ?: $resource['file_name'];

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addslashes($original) . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
readfile($file_path);
exit;
