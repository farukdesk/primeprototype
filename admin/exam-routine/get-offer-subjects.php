<?php
/**
 * AJAX: registered courses of a course offer (Exam Routine builder).
 * Returns course code, title, credit and the number of active registered
 * students so the routine rows can auto-fill.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
if (!can_access('exam-routine')) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json');
$offer_id = (int)($_GET['offer_id'] ?? 0);
if ($offer_id <= 0) { echo '[]'; exit; }

$stmt = db()->prepare(
    "SELECT cos.id AS offer_subject_id,
            c.course_code, c.course_name, c.credit,
            (SELECT GROUP_CONCAT(f.name ORDER BY t.sort_order SEPARATOR ', ')
               FROM co_offer_subject_teachers t
               JOIN dept_faculty f ON f.id = t.faculty_id
              WHERE t.offer_subject_id = cos.id) AS teachers,
            (SELECT COUNT(*)
               FROM co_registrations r
               JOIN students s ON s.id = r.student_id
              WHERE r.offer_subject_id = cos.id
                AND s.status = 'Active') AS registered_count
       FROM co_offer_subjects cos
       JOIN course_curriculum c ON c.id = cos.curriculum_id
      WHERE cos.offer_id = ?
      ORDER BY c.course_code ASC, c.course_name ASC"
);
$stmt->execute([$offer_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
