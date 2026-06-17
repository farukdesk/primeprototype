<?php
/**
 * Admit Card – QR Code Image Endpoint
 * Outputs a QR code image for the given URL parameter.
 * Used for on-screen display in the student portal.
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once __DIR__ . '/phpqrcode.php';

$url = trim($_GET['url'] ?? '');
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit;
}

// Allow only our own domain or localhost to prevent abuse
$parsed       = parse_url($url);
$url_host     = strtolower($parsed['host'] ?? '');
$allowed_host = defined('SITE_URL') ? strtolower(parse_url(SITE_URL, PHP_URL_HOST) ?? '') : '';
$server_host  = strtolower($_SERVER['HTTP_HOST'] ?? '');

$is_allowed = ($allowed_host === '' || $url_host === $allowed_host || $url_host === $server_host
               || $url_host === 'localhost' || $url_host === '127.0.0.1');
if (!$is_allowed) {
    http_response_code(403);
    exit;
}

// Generate to temp file to avoid header() conflicts
$png_f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr_' . uniqid('', true) . '.png';

try {
    QRcode::png($url, $png_f, QR_ECLEVEL_M, 4, 4);
    if (is_file($png_f) && filesize($png_f) > 0) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . filesize($png_f));
        readfile($png_f);
        @unlink($png_f);
        exit;
    }
} catch (Throwable $e) {
    // fall through to SVG
}
@unlink($png_f);

// SVG fallback
$svg_f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr_' . uniqid('', true) . '.svg';
try {
    QRcode::svg($url, $svg_f, QR_ECLEVEL_M, 4, 4);
    if (is_file($svg_f) && filesize($svg_f) > 0) {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . filesize($svg_f));
        readfile($svg_f);
        @unlink($svg_f);
        exit;
    }
} catch (Throwable $e) {
    // ignore
}
@unlink($svg_f);

http_response_code(500);
exit;
