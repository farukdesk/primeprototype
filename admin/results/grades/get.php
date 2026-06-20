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
    'SELECT subject_id, marks, letter_grade, grade_point,
            marked_by, reviewed_by, approved_by, id AS grade_id
     FROM result_grades
     WHERE exam_id = ? AND student_sid = ?'
);
$stmt->execute([$exam_id, $student_sid]);
$grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($grades)) { echo '[]'; exit; }

// Fetch per-category details for all these grades
$grade_ids = array_column($grades, 'grade_id');
$placeholders = implode(',', array_fill(0, count($grade_ids), '?'));
$det_stmt = db()->prepare(
    'SELECT grade_id, category_id, marks_obtained
     FROM result_grade_details
     WHERE grade_id IN (' . $placeholders . ')'
);
$det_stmt->execute($grade_ids);
$details_rows = $det_stmt->fetchAll(PDO::FETCH_ASSOC);

$details_keyed = [];
foreach ($details_rows as $d) {
    $details_keyed[(int)$d['grade_id']][(int)$d['category_id']] = (float)$d['marks_obtained'];
}

foreach ($grades as &$g) {
    $g['category_marks'] = $details_keyed[(int)$g['grade_id']] ?? [];
}
unset($g);

echo json_encode(array_values($grades));
