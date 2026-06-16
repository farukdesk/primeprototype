<?php
/**
 * Admit Card – QR Code Image Endpoint
 * Outputs a PNG QR code for the given URL parameter.
 * Used for on-screen display in the student portal.
 */
require_once __DIR__ . '/phpqrcode.php';

$url = trim($_GET['url'] ?? '');
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit;
}

// Allow only our own domain to prevent abuse
$parsed = parse_url($url);
$allowed_host = parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST);
if ($allowed_host && ($parsed['host'] ?? '') !== $allowed_host) {
    http_response_code(403);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
QRcode::png($url, false, QR_ECLEVEL_M, 4, 4);
exit;
