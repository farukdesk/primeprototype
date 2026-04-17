<?php
/**
 * AJAX: return courses from course_curriculum for a given program_id.
 * Used in result subjects import / create to auto-fill course details.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!rm_can_view()) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json');
$program_id = (int)($_GET['program_id'] ?? 0);
if ($program_id <= 0) { echo '[]'; exit; }

$stmt = db()->prepare(
    'SELECT id, course_code, course_name, credit, semester
     FROM course_curriculum
     WHERE program_id = ?
     ORDER BY semester ASC, sort_order ASC, sl_no ASC'
);
$stmt->execute([$program_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
