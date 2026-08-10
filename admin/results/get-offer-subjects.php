<?php
/**
 * AJAX: return offered subjects (course-offer subjects) for the mark-entry
 * subject selector.
 *
 * Each row is one `co_offer_subjects` record belonging to an active `co_offers`
 * offer that matches the requested department/program. Selecting a row uniquely
 * identifies the offered course, so its active registered students can be loaded
 * from `co_registrations` (see get-offer-students.php).
 *
 * Super admin / results staff with create rights and no faculty profile → every
 * offered subject for the dept/program.
 * Faculty (any user with a faculty_profiles record) → only offered subjects they
 * are authorized for: subjects they teach (co_offer_subject_teachers), their
 * approved subject assignments (faculty_subject_assignments, status = approved),
 * or curriculum courses admin-assigned to them (course_curriculum.assigned_faculty_id).
 *
 * GET params:
 *   dept_id     (int, required)
 *   program_id  (int, required)
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!rm_can_view()) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json');

$dept_id    = (int)($_GET['dept_id']    ?? 0);
$program_id = (int)($_GET['program_id'] ?? 0);
if ($dept_id <= 0 || $program_id <= 0) { echo '[]'; exit; }

$user_id = (int)auth_user()['id'];

// Determine whether this user is a faculty member (has a faculty_profiles record).
// Faculty members only see offered subjects they teach, even when they also hold
// results admin permissions.
$is_faculty_member = false;
if (!is_super_admin()) {
    $fac_check = db()->prepare('SELECT id FROM faculty_profiles WHERE user_id = ? LIMIT 1');
    $fac_check->execute([$user_id]);
    $is_faculty_member = (bool)$fac_check->fetch();
    // An active dept_faculty record also marks the user as a faculty member,
    // so course teachers without a faculty profile are restricted too.
    if (!$is_faculty_member) {
        try {
            $df_check = db()->prepare('SELECT id FROM dept_faculty WHERE user_id = ? AND is_active = 1 LIMIT 1');
            $df_check->execute([$user_id]);
            $is_faculty_member = (bool)$df_check->fetch();
        } catch (Throwable $_e) {}
    }
}
$restrict_to_teacher = !is_super_admin()
    && (!(rm_can_create() || rm_is_staff()) || $is_faculty_member);

$params = [$dept_id, $program_id];
$teacher_filter = '';
if ($restrict_to_teacher) {
    // dept_faculty.user_id is optional, so also match the faculty record by the
    // logged-in user's email when no user account is linked.
    $user_email = trim((string)(auth_user()['email'] ?? ''));

    // Faculty may enter marks for an offered subject when they are authorized via
    // ANY of the three sources below. These mirror the server-side authorization
    // check in mark-entry.php and the approved subjects shown on my-profile.php:
    //   1. assigned as a teacher on the course offer (co_offer_subject_teachers)
    //   2. an approved subject assignment (faculty_subject_assignments, approved)
    //   3. admin-assigned directly on the curriculum (course_curriculum.assigned_faculty_id)
    $teacher_filter = "AND (
        EXISTS (
            SELECT 1 FROM co_offer_subject_teachers t
            JOIN dept_faculty df ON df.id = t.faculty_id
            WHERE t.offer_subject_id = cos.id
              AND (df.user_id = ?
                   OR (? <> '' AND df.email IS NOT NULL AND df.email = ?))
        )
        OR EXISTS (
            SELECT 1 FROM faculty_subject_assignments fsa
            WHERE fsa.course_id = cos.curriculum_id
              AND fsa.faculty_user_id = ?
              AND fsa.status = 'approved'
        )
        OR EXISTS (
            SELECT 1 FROM course_curriculum cc2
            JOIN dept_faculty df2 ON df2.id = cc2.assigned_faculty_id
            WHERE cc2.id = cos.curriculum_id
              AND (df2.user_id = ?
                   OR (? <> '' AND df2.email IS NOT NULL AND df2.email = ?))
        )
    )";
    $params[] = $user_id;
    $params[] = $user_email;
    $params[] = $user_email;
    $params[] = $user_id;
    $params[] = $user_id;
    $params[] = $user_email;
    $params[] = $user_email;
}

$sql = "SELECT cos.id            AS offer_subject_id,
               cos.curriculum_id,
               c.course_code,
               c.course_name,
               c.credit,
               o.id              AS offer_id,
               o.semester,
               o.academic_intake,
               b.name            AS batch_name,
               (SELECT COUNT(*)
                  FROM co_registrations r
                  JOIN students s ON s.id = r.student_id
                 WHERE r.offer_subject_id = cos.id
                   AND s.status = 'Active') AS registered_count
          FROM co_offer_subjects cos
          JOIN co_offers          o ON o.id = cos.offer_id AND o.status = 'active'
          JOIN course_curriculum  c ON c.id = cos.curriculum_id
          LEFT JOIN student_batches b ON b.id = o.batch_id
         WHERE o.dept_id = ? AND o.program_id = ?
               $teacher_filter
         ORDER BY b.sort_order ASC, b.name ASC, c.course_name ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
