<?php
/**
 * AJAX: active course offers of a dept + program (Exam Routine builder).
 * Each offer carries batch / semester / section / shift / intake, so choosing
 * one fixes the whole class context of the routine.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
if (!can_access('exam-routine')) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json');
$dept_id    = (int)($_GET['dept_id']    ?? 0);
$program_id = (int)($_GET['program_id'] ?? 0);
if ($dept_id <= 0 || $program_id <= 0) { echo '[]'; exit; }

$stmt = db()->prepare(
    "SELECT o.id, o.semester, o.academic_intake, o.shift, o.section,
            b.name AS batch_name,
            (SELECT COUNT(*) FROM co_offer_subjects cos WHERE cos.offer_id = o.id) AS subject_count
       FROM co_offers o
  LEFT JOIN student_batches b ON b.id = o.batch_id
      WHERE o.dept_id = ? AND o.program_id = ? AND o.status = 'active'
      ORDER BY b.sort_order ASC, b.name ASC, o.semester ASC, o.id DESC"
);
$stmt->execute([$dept_id, $program_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
