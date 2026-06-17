<?php
/**
 * Admit Card – Download PDF
 * Admin: can download for any student (no due check).
 * Student portal: subject to due-amount check.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/../vendor/autoload.php';

$card_id    = (int)($_GET['card']    ?? 0);
$student_id = (int)($_GET['student'] ?? 0);

// Resolve student for portal users
if (is_portal_student()) {
    $me = db()->prepare('SELECT id FROM students WHERE portal_user_id = ? LIMIT 1');
    $me->execute([auth_user()['id']]);
    $me_row = $me->fetch();
    if (!$me_row) {
        http_response_code(403);
        die('Student profile not found.');
    }
    $student_id = (int)$me_row['id']; // Always force own student ID for portal users
}

// Admin must have view access; portal students are allowed via their own route
if (!is_portal_student() && !ac_can_view()) {
    http_response_code(403);
    die('Access denied.');
}

$card = ac_get_card($card_id);
if (!$card || !$card['is_active']) {
    http_response_code(404);
    die('Admit card not found or not active.');
}

// Fetch student record
$student_stmt = db()->prepare(
    'SELECT s.*, d.name AS dept_name, p.program_name
     FROM students s
     JOIN dept_departments d ON d.id = s.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = s.program_id
     WHERE s.id = ?'
);
$student_stmt->execute([$student_id]);
$student = $student_stmt->fetch();
if (!$student) {
    http_response_code(404);
    die('Student not found.');
}

// Portal students must belong to the card's dept+program
if (is_portal_student()) {
    if ((int)$student['dept_id'] !== (int)$card['dept_id']
        || (int)$student['program_id'] !== (int)$card['program_id']) {
        http_response_code(403);
        die('You do not have access to this admit card.');
    }

    // Due check
    $access = ac_check_access($card_id, $student_id);
    if (!$access['allowed']) {
        http_response_code(403);
        die(htmlspecialchars($access['reason'] ?? 'Due amount exceeds limit.'));
    }
}

$courses = ac_get_courses($card_id);

// Get/create token and QR
$token      = ac_get_or_create_token($card_id, $student_id);
$verify_url = ac_verify_url($token);
$qr_uri     = ac_qr_data_uri($verify_url);

$html = ac_build_html($card, $student, $courses, $qr_uri);

// Generate PDF
$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'admit-card-' . preg_replace('/[^A-Za-z0-9\-]+/', '-', strtolower($student['student_id'])) . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
