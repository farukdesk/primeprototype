<?php
/**
 * Student Portal API – GET /api/student/id-card.php
 * ===================================================
 * Returns the signed-in student's generated ID card (from the admin ID Card
 * module) rendered as front/back SVG, ready to be displayed in the mobile
 * app exactly as the printed card looks. Uses the same renderer as the
 * admin print preview (idc_render_front_svg / idc_render_back_svg), so the
 * app always matches the physical card design.
 *
 * Success response:
 *   { "ok": true, "has_card": true, "card": { id, id_number, full_name,
 *     blood_group, issue_date, expiry_date, print_status,
 *     print_status_label, front_svg, back_svg } }
 * When the student has no generated card:
 *   { "ok": true, "has_card": false, "card": null }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx     = sp_api_auth();
$student = $ctx['student'];

try {
    require_once dirname(__DIR__, 2) . '/id-card/helpers.php';
} catch (Throwable $e) {
    sp_api_error(503, 'ID card module is not available.');
}

try {
    // Latest active student card, matched by the linked student record or by
    // the printed Student ID (cards created before linking existed).
    $st = db()->prepare(
        'SELECT * FROM idc_cards
          WHERE card_type = ?
            AND is_active = 1
            AND (student_ref_id = ? OR id_number = ?)
          ORDER BY id DESC
          LIMIT 1'
    );
    $st->execute(['student', (int)$student['student_db_id'], (string)$student['student_id']]);
    $card = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Student id-card: query failed – ' . $e->getMessage());
    sp_api_error(500, 'Could not load your ID card. Please try again.');
}

if (!$card) {
    sp_api_ok(['has_card' => false, 'card' => null]);
    exit;
}

// Same rule as the admin print preview: always use the CURRENT photo from
// the student profile (falls back to the photo stored on the card).
$live_photo = trim((string)($student['photo'] ?? ''));
if ($live_photo !== '') {
    $card['photo'] = $live_photo;
}

$front_svg = idc_render_front_svg($card);
$back_svg  = idc_render_back_svg((string)$card['card_type']);

if ($front_svg === '') {
    // Template missing on the server – let the app fall back to the QR card.
    sp_api_ok(['has_card' => false, 'card' => null]);
    exit;
}

// Strip the XML prolog so the SVGs can be embedded straight into HTML.
$front_svg = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $front_svg);
if ($back_svg !== '') {
    $back_svg = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $back_svg);
}

sp_api_ok([
    'has_card' => true,
    'card'     => [
        'id'                 => (int)$card['id'],
        'id_number'          => (string)$card['id_number'],
        'full_name'          => (string)$card['full_name'],
        'blood_group'        => (string)($card['blood_group'] ?? ''),
        'issue_date'         => (string)($card['issue_date'] ?? ''),
        'expiry_date'        => (string)($card['expiry_date'] ?? ''),
        'print_status'       => (string)($card['print_status'] ?? ''),
        'print_status_label' => idc_print_status_label($card['print_status'] ?? null),
        'front_svg'          => $front_svg,
        'back_svg'           => $back_svg,
    ],
]);
