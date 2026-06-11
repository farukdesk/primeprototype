<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = auth_user();
if (!$user) { echo '[]'; exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo '[]'; exit; }

$like = "%$q%";

// Search all students regardless of status or admission application record.
// A correlated subquery fetches the most-recent app_number for display purposes
// only (does not filter results). App-number search is still supported via an
// EXISTS subquery.
$stmt = db()->prepare(
    'SELECT DISTINCT
            s.id, s.student_id, s.full_name, s.status,
            (SELECT a2.app_number
               FROM admissions_applications a2
              WHERE TRIM(a2.student_name) = TRIM(s.full_name)
              ORDER BY a2.id DESC
              LIMIT 1) AS app_number
     FROM students s
     WHERE (
           s.student_id LIKE ?
           OR s.full_name LIKE ?
           OR EXISTS (
               SELECT 1 FROM admissions_applications a
               WHERE TRIM(a.student_name) = TRIM(s.full_name)
                 AND a.app_number LIKE ?
           )
       )
     ORDER BY s.full_name
     LIMIT 15'
);
$stmt->execute([$like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows);
