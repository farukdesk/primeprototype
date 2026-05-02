<?php
/**
 * AJAX: return curriculum courses for the mark-entry subject selector.
 *
 * Admin / staff (can_create)  → all curriculum courses, all marked is_assigned=true.
 * Faculty                     → all curriculum courses; is_assigned=true only for
 *                               their approved / admin-assigned subjects.
 *                               Assigned subjects sort first.
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

// Admins / staff with create rights see all subjects as fully assigned
if (is_super_admin() || rm_can_create() || rm_is_staff()) {
    $stmt = db()->prepare(
        'SELECT cc.id, cc.course_code, cc.course_name, cc.credit, 1 AS is_assigned
         FROM course_curriculum cc
         WHERE cc.program_id = ?
         ORDER BY cc.semester ASC, cc.course_name ASC'
    );
    $stmt->execute([$program_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Faculty: fetch only subjects assigned/approved for this user
$user_id = (int)auth_user()['id'];

// Collect assigned curriculum IDs from two sources:
// 1. faculty_subject_assignments (status = approved)
$st = db()->prepare(
    "SELECT fsa.course_id
       FROM faculty_subject_assignments fsa
      WHERE fsa.faculty_user_id = ? AND fsa.status = 'approved'"
);
$st->execute([$user_id]);
$assigned_ids = $st->fetchAll(PDO::FETCH_COLUMN);

// 2. Admin-assigned via course_curriculum.assigned_faculty_id → dept_faculty
$st2 = db()->prepare(
    "SELECT cc.id
       FROM course_curriculum cc
       JOIN dept_faculty df ON df.id = cc.assigned_faculty_id
      WHERE df.user_id = ?"
);
$st2->execute([$user_id]);
foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    $assigned_ids[] = $cid;
}

$assigned_ids = array_unique(array_map('intval', $assigned_ids));

if (empty($assigned_ids)) { echo '[]'; exit; }

$phs  = implode(',', array_fill(0, count($assigned_ids), '?'));
$stmt = db()->prepare(
    "SELECT cc.id, cc.course_code, cc.course_name, cc.credit, 1 AS is_assigned
       FROM course_curriculum cc
      WHERE cc.program_id = ? AND cc.id IN ($phs)
      ORDER BY cc.semester ASC, cc.course_name ASC"
);
$stmt->execute(array_merge([$program_id], $assigned_ids));
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
