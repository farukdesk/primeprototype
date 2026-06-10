<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = auth_user();
if (!$user) { echo '[]'; exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo '[]'; exit; }

$like = "%$q%";

// Search students who have a ready_for_admission application.
// Match by form number (app_number), applicant name, or student ID.
$stmt = db()->prepare(
    'SELECT s.id, s.student_id, s.full_name, a.app_number
     FROM admissions_applications a
     INNER JOIN students s ON s.full_name = a.student_name
     WHERE (a.app_number LIKE ? OR a.student_name LIKE ? OR s.student_id LIKE ?)
       AND a.status = \'ready_for_admission\'
       AND s.status = \'Active\'
     ORDER BY a.student_name
     LIMIT 15'
);
$stmt->execute([$like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows);
