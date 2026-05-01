<?php
/**
 * AJAX: return curriculum courses for the mark-entry subject selector.
 *
 * Admin / staff (can_create)  → all curriculum courses for the given program.
 * Faculty                     → only their approved + admin-assigned subjects.
 *
 * GET params:
 *   program_id  (int, required)
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

$program_id = (int)($_GET['program_id'] ?? 0);
if ($program_id <= 0) { echo '[]'; exit; }

// Admins / staff with create rights see all subjects
if (is_super_admin() || rm_can_create() || rm_is_staff()) {
    $stmt = db()->prepare(
        'SELECT cc.id, cc.course_code, cc.course_name, cc.credit
         FROM course_curriculum cc
         WHERE cc.program_id = ?
         ORDER BY cc.semester ASC, cc.course_name ASC'
    );
    $stmt->execute([$program_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Faculty: approved assignments + admin-assigned via dept_faculty
$user_id  = (int)auth_user()['id'];
$subjects = [];
$seen_ids = [];

// 1. From faculty_subject_assignments (status = approved)
$st = db()->prepare(
    "SELECT cc.id, cc.course_code, cc.course_name, cc.credit
       FROM faculty_subject_assignments fsa
       JOIN course_curriculum cc ON cc.id = fsa.course_id
      WHERE fsa.faculty_user_id = ? AND fsa.status = 'approved'
        AND cc.program_id = ?
      ORDER BY cc.course_name ASC"
);
$st->execute([$user_id, $program_id]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $subjects[] = $row;
    $seen_ids[(int)$row['id']] = true;
}

// 2. Admin-assigned via course_curriculum.assigned_faculty_id → dept_faculty
$st2 = db()->prepare(
    "SELECT cc.id, cc.course_code, cc.course_name, cc.credit
       FROM course_curriculum cc
       JOIN dept_faculty df ON df.id = cc.assigned_faculty_id
      WHERE df.user_id = ? AND cc.program_id = ?
      ORDER BY cc.course_name ASC"
);
$st2->execute([$user_id, $program_id]);
foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (!isset($seen_ids[(int)$row['id']])) {
        $subjects[] = $row;
        $seen_ids[(int)$row['id']] = true;
    }
}

usort($subjects, fn($a, $b) => strcmp($a['course_name'], $b['course_name']));
echo json_encode($subjects);
