<?php
/**
 * SS Portal – students/photo.php
 * Serves a locally stored student photo to signed-in users only
 * (storage/ is not web-accessible).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sync.php';

ssp_require_login();

$s = ssp_student_find((int)($_GET['id'] ?? 0));
if ($s === null || empty($s['photo_path'])) {
    http_response_code(404);
    exit('No photo.');
}
$file = ssp_photo_file((string)$s['photo_path']);
$mime = ssp_photo_mime($file);
if ($mime === null) {
    http_response_code(404);
    exit('No photo.');
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($file);
