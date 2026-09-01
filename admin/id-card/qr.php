<?php
/**
 * ID Card – QR Code Endpoint
 * Encodes the card's identity for on-card verification.
 * Reuses the phpqrcode library that ships with the Admit Card module.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('id-card');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../admit-card/phpqrcode.php';

$card_id = (int)($_GET['card_id'] ?? 0);
$card    = $card_id > 0 ? idc_get_card($card_id) : false;
if (!$card) {
    http_response_code(404);
    exit;
}

$payload = 'PU-ID|' . strtoupper($card['card_type']) . '|' . $card['id_number'] . '|' . $card['full_name'];

$png_f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'idcqr_' . uniqid('', true) . '.png';
try {
    QRcode::png($payload, $png_f, QR_ECLEVEL_M, 3, 2);
    if (is_file($png_f) && filesize($png_f) > 0) {
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=600');
        header('Content-Length: ' . filesize($png_f));
        readfile($png_f);
        @unlink($png_f);
        exit;
    }
} catch (Throwable $e) {
    // fall through
}
@unlink($png_f);
http_response_code(500);
