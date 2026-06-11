<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = auth_user();
if (!$user) { echo '[]'; exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo '[]'; exit; }

$like = "%$q%";

// Search students who have a ready_for_admission or admission_complete application.
// Students are created in the students table only after admission_complete, so
// both statuses must be included for form-number search to work.
// Match by form number (app_number), applicant name, or student ID.
$stmt = db()->prepare(
    'SELECT s.id, s.student_id, s.full_name, MAX(a.app_number) AS app_number
     FROM admissions_applications a
     INNER JOIN students s ON s.full_name = a.student_name
     WHERE (a.app_number LIKE ? OR a.student_name LIKE ? OR s.student_id LIKE ?)
       AND a.status IN (\'ready_for_admission\', \'admission_complete\')
       AND s.status = \'Active\'
     GROUP BY s.id, s.student_id, s.full_name
     ORDER BY s.full_name
     LIMIT 15'
);
$stmt->execute([$like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows);
