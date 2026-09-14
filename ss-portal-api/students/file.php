<?php
/**
 * SS Portal – students/file.php
 * Serves an internal document (storage/files/, portal-only) to signed-in
 * users only.  ?id=<file id>[&download=1]
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/internal_data.php';

ssp_require_login();

$f = ssp_student_file_find((int)($_GET['id'] ?? 0));
if ($f === null) {
    http_response_code(404);
    exit('No file.');
}
$path = ssp_file_path((string)$f['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('No file.');
}
$mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!isset(SSP_FILE_MIMES[$mime])) {
    $mime = 'application/octet-stream';
}
$inline = empty($_GET['download']) && ($mime === 'application/pdf' || strpos($mime, 'image/') === 0);
$name   = str_replace(['"', "\r", "\n"], '', (string)$f['original_name']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
