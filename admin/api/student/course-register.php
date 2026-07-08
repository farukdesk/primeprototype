<?php
/**
 * Student Portal API – POST /api/student/course-register.php
 * ===========================================================
 * Lets a student self-register for (or drop) a subject of a course offer that
 * targets their batch, while self-registration is open.
 *
 * Body (form-urlencoded or JSON):
 *   offer_subject_id = <co_offer_subjects.id>   (required)
 *   action           = "register" | "drop"      (default "register")
 *
 * Success response:
 *   { "ok": true, "offer_subject_id": N, "registered": true|false }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sp_api_error(405, 'Method Not Allowed. Use POST.');
}

$ctx      = sp_api_auth();
$student  = $ctx['student'];
$batch_id = (int)($student['batch_id'] ?? 0);
$sid      = (int)$student['student_db_id'];

// Accept JSON or form-encoded bodies.
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $input = $decoded;
    }
}

$offer_subject_id = (int)($input['offer_subject_id'] ?? 0);
$action           = ($input['action'] ?? 'register') === 'drop' ? 'drop' : 'register';

if ($offer_subject_id <= 0) {
    sp_api_error(422, 'A valid offer_subject_id is required.');
}
if ($batch_id <= 0) {
    sp_api_error(403, 'No batch is assigned to your profile yet.');
}

// Verify the subject belongs to an active offer for THIS student's batch and
// that self-registration is currently open.
$st = db()->prepare(
    "SELECT o.registration_open
       FROM co_offer_subjects cos
       JOIN co_offers o ON o.id = cos.offer_id
      WHERE cos.id = ? AND o.batch_id = ? AND o.status = 'active'
      LIMIT 1"
);
$st->execute([$offer_subject_id, $batch_id]);
$row = $st->fetch();

if (!$row) {
    sp_api_error(404, 'This course is not available for your batch.');
}
if ((int)$row['registration_open'] !== 1) {
    sp_api_error(403, 'Registration is currently closed for this course.');
}

if ($action === 'drop') {
    $del = db()->prepare(
        "DELETE FROM co_registrations WHERE offer_subject_id = ? AND student_id = ?"
    );
    $del->execute([$offer_subject_id, $sid]);
    sp_api_ok(['offer_subject_id' => $offer_subject_id, 'registered' => false]);
    return;
}

// Register (idempotent).
$ins = db()->prepare(
    "INSERT IGNORE INTO co_registrations
         (offer_subject_id, student_id, source, registered_by)
     VALUES (?, ?, 'self', NULL)"
);
$ins->execute([$offer_subject_id, $sid]);

sp_api_ok(['offer_subject_id' => $offer_subject_id, 'registered' => true]);
