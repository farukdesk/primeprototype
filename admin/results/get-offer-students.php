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

// ── Mid-term marks pull ───────────────────────────────────────────────────────
// When the selected exam is a FINAL exam, return each student's mid-term marks
// previously entered for the SAME offer subject in a mid-term exam sheet
// (pending/published) as `prev_marks`, so the final-entry sheet pre-fills them
// and lets the teacher modify them. Only mid-component indices are included.
if ($exam_id > 0 && $students) {
    try {
        $ex = db()->prepare('SELECT exam_name FROM ei_exams WHERE id = ? LIMIT 1');
        $ex->execute([$exam_id]);
        $sel_exam_name = strtolower((string)$ex->fetchColumn());
        if (strpos($sel_exam_name, 'final') !== false && !preg_match('/mid\\s*-?\\s*term|midterm/', $sel_exam_name)) {
            $mq2 = db()->prepare(
                "SELECT g.student_sid, g.student_id, g.marks_json, g.mid_term,
                        ms.curriculum_id
                   FROM result_mark_sheets ms
                   JOIN result_sheet_grades g ON g.sheet_id = ms.id
                   JOIN ei_exams e            ON e.id = ms.exam_id
                  WHERE ms.offer_subject_id = ?
                    AND ms.workflow_status IN ('pending', 'published')
                    AND LOWER(e.exam_name) REGEXP 'mid[[:space:]]*-?[[:space:]]*term|midterm'
                    AND LOWER(e.exam_name) NOT LIKE '%final%'
                  ORDER BY ms.updated_at ASC"
            );
            $mq2->execute([$offer_subject_id]);

            // Mid-component indices per curriculum (default: index 2 = legacy Mid Term)
            $mid_idx_cache = [];
            $mid_indices = static function (int $cid) use (&$mid_idx_cache): array {
                if (isset($mid_idx_cache[$cid])) return $mid_idx_cache[$cid];
                $idx = [];
                if ($cid > 0) {
                    try {
                        $ds = db()->prepare(
                            'SELECT distribution_name FROM cc_mark_distributions
                              WHERE curriculum_id = ? ORDER BY sort_order ASC, id ASC'
                        );
                        $ds->execute([$cid]);
                        foreach ($ds->fetchAll(PDO::FETCH_COLUMN) as $i => $nm) {
                            if (stripos((string)$nm, 'mid') !== false) $idx[] = $i;
                        }
                    } catch (Throwable $_e) {}
                }
                if (empty($idx)) $idx = [2]; // legacy layout: Att, CT, Mid, Final
                return $mid_idx_cache[$cid] = $idx;
            };

            $prev_by_pk = []; $prev_by_sid = [];
            foreach ($mq2->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $marks = json_decode((string)$m['marks_json'], true);
                $mids  = $mid_indices((int)($m['curriculum_id'] ?? 0));
                $prev  = [];
                foreach ($mids as $i) {
                    $v = is_array($marks) ? ($marks[$i] ?? null) : null;
                    if ($v === null && $i === 2 && $m['mid_term'] !== null) $v = (float)$m['mid_term'];
                    if ($v !== null) $prev[$i] = (float)$v;
                }
                if (empty($prev)) continue;
                // Null-padded array aligned by distribution index (latest sheet wins)
                $out = array_fill(0, max(array_keys($prev)) + 1, null);
                foreach ($prev as $i => $v) $out[$i] = $v;
                if (!empty($m['student_id']))               $prev_by_pk[(int)$m['student_id']] = $out;
                if (trim((string)$m['student_sid']) !== '') $prev_by_sid[trim((string)$m['student_sid'])] = $out;
            }

            if ($prev_by_pk || $prev_by_sid) {
                foreach ($students as &$s) {
                    $s['prev_marks'] = $prev_by_pk[(int)$s['id']]
                                    ?? ($prev_by_sid[trim((string)$s['student_id'])] ?? null);
                }
                unset($s);
            }
        }
    } catch (Throwable $_e) { /* prefill unavailable — return plain roster */ }
}

echo json_encode($students);
