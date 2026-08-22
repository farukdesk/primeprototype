<?php
/**
 * Student Portal API – GET /api/student/admit-card-download.php?card=ID
 * ======================================================================
 * Streams the signed-in student's admit card as a PDF (Bearer token auth).
 * Mirrors admin/admit-card/download.php: the same dept/program ownership,
 * exam-enrollment and due-amount checks apply, and the identical dompdf
 * card (with verification QR) is generated.
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx         = sp_api_auth();
$student_ctx = $ctx['student'];
$student_id  = (int)$student_ctx['student_db_id'];
$card_id     = (int)($_GET['card'] ?? 0);

if ($card_id <= 0) {
    sp_api_error(400, 'card is required.');
}

try {
    require_once dirname(__DIR__, 2) . '/admit-card/helpers.php';
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
} catch (Throwable $e) {
    sp_api_error(503, 'Admit card module is not available.');
}

$card = ac_get_card($card_id);
if (!$card || !$card['is_active']) {
    sp_api_error(404, 'Admit card not found or not active.');
}

// Fetch the full student record (photo, names) for the PDF.
$stmt = db()->prepare(
    'SELECT s.*, d.name AS dept_name, p.program_name
     FROM students s
     JOIN dept_departments d ON d.id = s.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = s.program_id
     WHERE s.id = ?'
);
$stmt->execute([$student_id]);
$student = $stmt->fetch();
if (!$student) {
    sp_api_error(404, 'Student not found.');
}

// The card must belong to the student's department + program.
if ((int)$student['dept_id'] !== (int)$card['dept_id']
    || (int)$student['program_id'] !== (int)$card['program_id']) {
    sp_api_error(403, 'You do not have access to this admit card.');
}

// Enrollment + due-amount check (same rules as the web portal).
$access = ac_check_access($card_id, $student_id);
if (!$access['allowed']) {
    sp_api_error(403, (string)($access['reason'] ?? 'You are not eligible to download this admit card.'));
}

// Courses merged across sibling cards of the same exam; lab courses are
// never listed on the admit card PDF (same rule as the admin download).
$courses = ac_get_merged_courses_for_student($card_id, $student_id);
$courses = array_values(array_filter($courses, static function ($c) {
    return !preg_match('/\\blab\\b/i', (string)($c['course_title'] ?? ''));
}));

$token      = ac_get_or_create_token($card_id, $student_id);
$verify_url = ac_verify_url($token);
$qr_uri     = ac_qr_data_uri($verify_url);

$html = ac_build_html($card, $student, $courses, $qr_uri);

$dompdf = new \Dompdf\Dompdf([
    'isRemoteEnabled'         => false,
    'isFontSubsettingEnabled' => true,
    'chroot'                  => dirname(__DIR__, 3),
]);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

header('Content-Type: application/pdf');
$filename = 'admit-card-' . preg_replace('/[^A-Za-z0-9\\-]+/', '-', strtolower((string)$student['student_id'])) . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
