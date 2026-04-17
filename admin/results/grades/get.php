<?php
/**
 * AJAX: return existing grades for a student in an exam.
 * Used to pre-fill the grade entry form when editing an existing student's grades.
 */
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../helpers.php';
if (!rm_can_view()) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json');

$exam_id     = (int)($_GET['exam_id']     ?? 0);
$student_sid = trim($_GET['student_sid']  ?? '');

if (!$exam_id || $student_sid === '') { echo '[]'; exit; }

$stmt = db()->prepare(
    'SELECT subject_id, marks, letter_grade, grade_point
     FROM result_grades
     WHERE exam_id = ? AND student_sid = ?'
);
$stmt->execute([$exam_id, $student_sid]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
