<?php
/**
 * AJAX: return the active registered students of a course-offer subject.
 *
 * Used by mark-entry: once a subject is selected, its active registered students
 * (from `co_registrations`, filtered to students with status = 'Active') are
 * loaded automatically into the marks table.
 *
 * GET params:
 *   offer_subject_id  (int, required)
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!rm_can_view()) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json');

$offer_subject_id = (int)($_GET['offer_subject_id'] ?? 0);
if ($offer_subject_id <= 0) { echo '[]'; exit; }

$stmt = db()->prepare(
    "SELECT s.id, s.student_id, s.full_name, s.batch
       FROM co_registrations r
       JOIN students s ON s.id = r.student_id
      WHERE r.offer_subject_id = ?
        AND s.status = 'Active'
      ORDER BY s.student_id ASC"
);
$stmt->execute([$offer_subject_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
