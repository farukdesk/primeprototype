<?php
/**
 * AJAX: return programs for a given dept_id (used in result exam create/edit).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();

header('Content-Type: application/json');
$dept_id = (int)($_GET['dept_id'] ?? 0);
if ($dept_id <= 0) { echo '[]'; exit; }

$stmt = db()->prepare(
    'SELECT id, program_name
     FROM dept_academic_programs
     WHERE dept_id = ? AND is_active = 1
     ORDER BY sort_order ASC, program_name ASC'
);
$stmt->execute([$dept_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
