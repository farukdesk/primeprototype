<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = auth_user();
if (!$user) { echo '[]'; exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo '[]'; exit; }

$like = "%$q%";

// Search enrolled students who have a ready_for_admission or admission_complete
// application. Students are only created in the students table after the
// admission_complete transition, so both statuses must be included for
// form-number search to work.
//
// The admissions_applications table has no FK to students; the only link is
// the student name (full_name = student_name), because acc_create_student_from_applicant
// populates students.full_name directly from admissions_applications.student_name.
// TRIM() guards against accidental leading/trailing whitespace differences.
//
// The most-recent app_number per student is resolved via a correlated subquery
// (ORDER BY id DESC) to avoid arbitrary GROUP BY / MAX behaviour when a student
// has more than one application.
$stmt = db()->prepare(
    'SELECT DISTINCT
            s.id, s.student_id, s.full_name,
            (SELECT a2.app_number
               FROM admissions_applications a2
              WHERE TRIM(a2.student_name) = TRIM(s.full_name)
                AND a2.status IN (\'ready_for_admission\', \'admission_complete\')
              ORDER BY a2.id DESC
              LIMIT 1) AS app_number
     FROM admissions_applications a
     INNER JOIN students s
             ON TRIM(s.full_name) = TRIM(a.student_name)
     WHERE (a.app_number LIKE ? OR a.student_name LIKE ? OR s.student_id LIKE ?)
       AND a.status IN (\'ready_for_admission\', \'admission_complete\')
       AND s.status = \'Active\'
     ORDER BY s.full_name
     LIMIT 15'
);
$stmt->execute([$like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows);
