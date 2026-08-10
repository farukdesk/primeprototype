<?php
/**
 * AJAX: return the active registered students of a course-offer subject.
 *
 * Used by mark-entry: once a subject is selected, its active registered students
 * (from `co_registrations`, filtered to students with status = 'Active') are
 * loaded automatically into the marks table.
 *
 * When `exam_id` is provided, each student also carries a `marked_by` field:
 * the name of the faculty member who already entered that student's marks in
 * another submitted (pending) or published sheet for the same exam + subject,
 * or null when the student has not been marked yet. This lets several teachers
 * share one subject — rows already marked by a colleague are locked in the UI.
 *
 * GET params:
 *   offer_subject_id  (int, required)
 *   exam_id           (int, optional)  — enables the marked_by lookup
 *   exclude_sheet_id  (int, optional)  — sheet currently being edited
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!rm_can_view()) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json');

$offer_subject_id = (int)($_GET['offer_subject_id'] ?? 0);
$exam_id          = (int)($_GET['exam_id'] ?? 0);
$exclude_sheet_id = (int)($_GET['exclude_sheet_id'] ?? 0);
if ($offer_subject_id <= 0) { echo '[]'; exit; }

$stmt = db()->prepare(
    "SELECT s.id, s.student_id, s.full_name, s.batch,
            s.dept_id, d.name AS dept_name
       FROM co_registrations r
       JOIN students s ON s.id = r.student_id
       LEFT JOIN dept_departments d ON d.id = s.dept_id
      WHERE r.offer_subject_id = ?
        AND s.status = 'Active'
      ORDER BY s.student_id ASC"
);
$stmt->execute([$offer_subject_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Natural (serial-wise) ordering: IDs sharing the same group/prefix sort
// numerically (…-1, …-2, …-10) instead of as plain strings (…-1, …-10, …-2).
usort($students, static fn($a, $b) => strnatcasecmp((string)$a['student_id'], (string)$b['student_id']));

// Already-marked lookup (multi-teacher support): flag students whose marks for
// this exam + subject were already entered in another pending/published sheet.
if ($exam_id > 0 && $students) {
    try {
        $mq = db()->prepare(
            "SELECT g.student_sid, g.student_id, u.full_name AS marked_by
               FROM result_mark_sheets ms
               JOIN result_sheet_grades g ON g.sheet_id = ms.id
               LEFT JOIN users u ON u.id = ms.created_by
              WHERE ms.exam_id = ? AND ms.offer_subject_id = ?
                AND ms.workflow_status IN ('pending', 'published')
                AND ms.id <> ?
                AND (g.marks_json IS NOT NULL OR g.is_absent = 1)"
        );
        $mq->execute([$exam_id, $offer_subject_id, $exclude_sheet_id]);

        $marked_by_pk  = []; // students.id  => faculty name
        $marked_by_sid = []; // student_sid  => faculty name
        foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $name = trim((string)($m['marked_by'] ?? '')) ?: 'another faculty member';
            if (!empty($m['student_id']))               $marked_by_pk[(int)$m['student_id']] = $name;
            if (trim((string)$m['student_sid']) !== '') $marked_by_sid[trim((string)$m['student_sid'])] = $name;
        }

        foreach ($students as &$s) {
            $s['marked_by'] = $marked_by_pk[(int)$s['id']]
                           ?? ($marked_by_sid[trim((string)$s['student_id'])] ?? null);
        }
        unset($s);
    } catch (Throwable $_e) { /* lookup unavailable — return plain roster */ }
}

echo json_encode($students);
