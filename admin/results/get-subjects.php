<?php
/**
 * AJAX: return course_curriculum subjects for a given program + semester.
 * Used in the mark-entry workflow form.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();

header('Content-Type: application/json');

$program_id = (int)($_GET['program_id'] ?? 0);
$semester   = trim($_GET['semester'] ?? '');   // e.g. "Fall-2025" → convert to semester int
$sem_int    = (int)($_GET['sem_int'] ?? 0);    // academic semester number 1-12 (direct)

if ($program_id <= 0) { echo '[]'; exit; }

// Build query – if sem_int provided use it; otherwise return all subjects for the program
$params = [$program_id];
$where  = 'cc.program_id = ?';
if ($sem_int > 0) {
    $where   .= ' AND cc.semester = ?';
    $params[] = $sem_int;
}

$stmt = db()->prepare(
    "SELECT cc.id, cc.course_code, cc.course_name, cc.credit, cc.semester
     FROM course_curriculum cc
     WHERE $where
     ORDER BY cc.semester ASC, cc.sort_order ASC, cc.id ASC"
);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
