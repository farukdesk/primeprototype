<?php
/**
 * AJAX: search students from the students table.
 * Filters by dept_id and optionally program_id and batch.
 * Used in the grade entry "Add Student" tab.
 *
 * Special mode: if load_all=1, returns all students for dept+program (no query needed).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!rm_can_view()) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json');

$q          = trim($_GET['q']          ?? '');
$dept_id    = (int)($_GET['dept_id']   ?? 0);
$program_id = (int)($_GET['program_id'] ?? 0);
$batch      = trim($_GET['batch']      ?? '');
$load_all   = isset($_GET['load_all']) && $_GET['load_all'] === '1';

$where  = [];
$params = [];

if ($load_all) {
    // Load all active students for a dept/program (no text search required)
    if ($dept_id <= 0 && $program_id <= 0) { echo '[]'; exit; }
    if ($dept_id > 0)    { $where[] = 's.dept_id = ?';    $params[] = $dept_id; }
    if ($program_id > 0) { $where[] = 's.program_id = ?'; $params[] = $program_id; }
    if ($batch !== '')   { $where[] = 's.batch = ?';       $params[] = $batch; }
    $where[] = "s.status = 'Active'";
    $limit = 500;
} else {
    if (mb_strlen($q) < 2) { echo '[]'; exit; }
    $like    = '%' . $q . '%';
    $where[] = '(s.student_id LIKE ? OR s.full_name LIKE ?)';
    $params  = [$like, $like];
    if ($dept_id > 0)    { $where[] = 's.dept_id = ?';    $params[] = $dept_id; }
    if ($program_id > 0) { $where[] = 's.program_id = ?'; $params[] = $program_id; }
    if ($batch !== '')   { $where[] = 's.batch = ?';       $params[] = $batch; }
    $limit = 20;
}

$sql = 'SELECT s.id, s.student_id, s.full_name, s.batch, s.admitted_semester,
               d.name AS dept_name, p.program_name
        FROM students s
        JOIN dept_departments d ON d.id = s.dept_id
        LEFT JOIN dept_academic_programs p ON p.id = s.program_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY s.full_name ASC
        LIMIT ' . $limit;

$stmt = db()->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
