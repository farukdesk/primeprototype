<?php
/**
 * Student Portal API – GET /api/student/results.php
 * ==================================================
 * Published results of the signed-in student – the SAME data and rules as the
 * web portal page students/my-results.php, so the app never differs from it:
 *
 *   • result_mark_sheets (workflow_status = 'published') + result_sheet_grades
 *       live results of the Results workflow; only sheets whose exam is over
 *   • sr_results / sr_result_entries
 *       result sets uploaded through the Spring Result module
 *   • student_results
 *       archived / imported rows (incl. the Final Result Publish CGPA row)
 *   • co_registrations
 *       registered courses of a completed exam that have no published grade
 *       yet → listed as "Not published yet" (is_pending = true) with the
 *       course teacher, exactly like the web page
 *
 * Rules (identical to my-results.php)
 *   • One card per semester (term such as "Spring 2026").
 *   • Same course published more than once in a term (e.g. a mid-term sheet
 *     carrying only the mid-term marks and, later, the final sheet): only the
 *     highest-ranked result is shown – final > unspecified > mid-term, then
 *     more mark components entered, then the most recently published.
 *   • F and Incom courses are listed but not counted; the semester GPA is
 *     withheld (null, gpa_incomplete = true) while the term contains one.
 *   • CGPA is credit-weighted over completed courses; a retaken course counts
 *     its latest attempt only. A published Final Result CGPA takes precedence.
 *   • Marks are never returned.
 *
 * Optional query: ?result_id=<id> restricts the response to one card
 * (use the id from a previous response).
 *
 * Success response:
 *   { "ok": true, "student_id": "...", "student_name": "...",
 *     "cgpa": 3.45, "cgpa_is_final": false, "credits_counted": 36,
 *     "semesters_published": 3, "pending_count": 2,
 *     "results": [ { id, title, semester, exam, published_at, course_count,
 *                    published_count, pending_count,
 *                    credits, gpa, gpa_incomplete, gpa_status, cgpa,
 *                    entries: [ { course_code, course_title, credit,
 *                                 letter_grade, grade_point, remarks,
 *                                 is_pending, teachers }, ... ] }, ... ] }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx        = sp_api_auth();
$student    = $ctx['student'];
$student_pk = (int)($student['student_db_id'] ?? 0);
$sid        = trim((string)($student['student_id'] ?? ''));
$sname      = (string)($student['student_name'] ?? '');

$filter_result_id = (int)($_GET['result_id'] ?? 0);
$today            = date('Y-m-d');

/** Same CGPA policy as my-results.php: a retaken course counts its latest attempt only. */
const SP_CGPA_LATEST_ATTEMPT_ONLY = true;

// ── Helpers (ported from students/my-results.php) ───────────────────────────────

/** "Spring-2026" / "spring 2026" / "Fall2025" → ['label' => 'Spring 2026', 'sort' => 20261]. */
function sp_parse_term(?string $raw): ?array
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (!preg_match('/\b(spring|summer|fall|autumn|winter)\b\s*[-_\/]?\s*(\d{2,4})/i', $raw, $m)) return null;
    $season = ucfirst(strtolower($m[1]));
    if ($season === 'Autumn') $season = 'Fall';
    $year = (int)$m[2];
    if ($year < 100) $year += 2000;
    $order = ['Spring' => 1, 'Summer' => 2, 'Fall' => 3, 'Winter' => 4][$season] ?? 0;
    return ['label' => $season . ' ' . $year, 'sort' => $year * 10 + $order];
}

/** 2 = final examination, 1 = unspecified, 0 = mid-term (from an exam name / result title). */
function sp_exam_kind(?string $name): int
{
    $n = strtolower(trim((string)$name));
    if ($n === '') return 1;
    if (preg_match('/\bfinal\b/', $n)) return 2;
    if (preg_match('/\bmid\s*-?\s*term\b|\bmidterm\b|\bmid\b/', $n)) return 0;
    return 1;
}

/** Number of mark components (attendance, class test, mid-term, final …) actually entered. */
function sp_marks_filled(?string $marks_json): int
{
    if ($marks_json === null || $marks_json === '') return 0;
    $m = json_decode($marks_json, true);
    if (!is_array($m)) return 0;
    return count(array_filter($m, static fn($v) => $v !== null && $v !== ''));
}

/** Key identifying a course (code, else title) for duplicate / retake detection. */
function sp_course_key(string $code, string $title): string
{
    $c = strtoupper(preg_replace('/\s+/', '', $code));
    return $c !== '' ? $c : 'T:' . strtoupper(preg_replace('/\s+/', '', $title));
}

function sp_is_incom_letter(string $g): bool
{
    return in_array(strtoupper(trim($g)), ['INCOM', 'I', 'INC'], true);
}

/** An F grade (or a zero grade point) means the course is not completed. */
function sp_is_fail(string $grade, ?float $point): bool
{
    return strtoupper(trim($grade)) === 'F' || ($point !== null && $point <= 0.0);
}

/** Grade point for a letter grade when the source row does not store one. */
function sp_grade_point(string $letter): ?float
{
    return match (strtoupper(trim($letter))) {
        'A+' => 4.00, 'A' => 3.75, 'A-' => 3.50,
        'B+' => 3.25, 'B' => 3.00, 'B-' => 2.75,
        'C+' => 2.50, 'C' => 2.25, 'D'  => 2.00,
        'F'  => 0.00,
        default => null,
    };
}

/** A sheet is visible only when it is tagged with an exam whose end date has passed. */
function sp_exam_done(?string $exam_id, ?string $end_date, string $today): bool
{
    return !empty($exam_id) && !empty($end_date) && $end_date < $today;
}

/**
 * Add one course row to its semester card. When the same course is already
 * listed in that term, the higher-ranked row replaces the lower one
 * (rank = [exam kind, mark components entered, published_at, source id]).
 */
function sp_add_course(array &$terms, array &$slots, array $term, array $row, array $rank,
                       string $exam = '', ?string $published_at = null): void
{
    $key = $term['label'];
    if (!isset($terms[$key])) {
        $terms[$key] = [
            'label'        => $key,
            'sort'         => (int)$term['sort'],
            'exam'         => '',
            'exam_kind'    => -1,
            'published_at' => null,
            'courses'      => [],
        ];
    }
    if ($published_at && ($terms[$key]['published_at'] === null || $published_at > $terms[$key]['published_at'])) {
        $terms[$key]['published_at'] = $published_at;
    }
    if ($exam !== '' && $rank[0] > $terms[$key]['exam_kind']) {
        $terms[$key]['exam_kind'] = $rank[0];
        $terms[$key]['exam']      = $exam;
    }

    $slot = $key . '|' . sp_course_key($row['code'], $row['title']);
    if (isset($slots[$slot])) {
        if (($rank <=> $slots[$slot]['rank']) > 0) {
            $terms[$key]['courses'][$slots[$slot]['idx']] = $row;
            $slots[$slot]['rank'] = $rank;
        }
        return;
    }
    $terms[$key]['courses'][] = $row;
    $slots[$slot] = ['idx' => array_key_last($terms[$key]['courses']), 'rank' => $rank];
}

$terms = []; // 'Spring 2026' => ['label','sort','exam','exam_kind','published_at','courses' => []]
$slots = []; // 'Spring 2026|CODE' => ['idx' => position in courses, 'rank' => [...]]
$published_offer_subjects = []; // offer_subject_id => true (already has a visible published grade)

// ── Source 1: published Results-workflow mark sheets ────────────────────────────

$sheet_sql = static function (bool $with_remarks): string {
    $remarks = $with_remarks ? 'g.remarks' : 'NULL AS remarks';
    return "SELECT ms.id AS sheet_id, ms.offer_subject_id, ms.semester, ms.subject_code, ms.subject_title,
                   COALESCE(ms.credits, cc.credit) AS credits,
                   ms.exam_id, e.exam_name, e.exam_year, e.end_date AS exam_end_date,
                   g.letter_grade, g.grade_point, g.is_absent, g.marks_json, $remarks,
                   (SELECT MAX(h.acted_at) FROM wf_sheet_history h
                     WHERE h.sheet_id = ms.id AND h.action = 'published') AS published_at
              FROM result_sheet_grades g
              JOIN result_mark_sheets ms      ON ms.id = g.sheet_id
              LEFT JOIN course_curriculum cc  ON cc.id = ms.curriculum_id
              LEFT JOIN ei_exams e            ON e.id  = ms.exam_id
             WHERE ms.workflow_status = 'published'
               AND (g.student_id = ? OR g.student_sid = ?)
             ORDER BY ms.id ASC";
};

$sheet_rows = [];
// The remarks column may not exist on older deployments – retry without it.
foreach ([true, false] as $with_remarks) {
    try {
        $st = db()->prepare($sheet_sql($with_remarks));
        $st->execute([$student_pk, $sid]);
        $sheet_rows = $st->fetchAll(PDO::FETCH_ASSOC);
        break;
    } catch (Throwable $e) {
        if (!$with_remarks) {
            error_log('Student results: mark sheet query failed – ' . $e->getMessage());
        }
    }
}

foreach ($sheet_rows as $c) {
    $letter = trim((string)($c['letter_grade'] ?? ''));
    $graded = ($c['marks_json'] !== null) || (int)$c['is_absent'] === 1 || $letter !== '';
    if (!$graded) continue; // roster row with no marks entered
    if (!sp_exam_done((string)($c['exam_id'] ?? ''), $c['exam_end_date'] ?? null, $today)) continue;

    $is_incom   = ((int)$c['is_absent'] === 1) || sp_is_incom_letter($letter);
    $exam_label = trim((string)($c['exam_name'] ?? '') . ' ' . (string)($c['exam_year'] ?? ''));
    $term       = sp_parse_term($c['semester'] ?? null) ?: [
        'label' => $exam_label !== '' ? $exam_label : (string)$c['semester'],
        'sort'  => ((int)($c['exam_year'] ?? 0)) * 10 + 5,
    ];

    $row = [
        'code'     => (string)($c['subject_code'] ?? ''),
        'title'    => (string)($c['subject_title'] ?? ''),
        'credits'  => ($c['credits'] !== null && $c['credits'] !== '') ? (float)$c['credits'] : null,
        'grade'    => $is_incom ? 'Incom' : $letter,
        'point'    => $is_incom ? null : ($c['grade_point'] !== null ? (float)$c['grade_point'] : null),
        'is_incom' => $is_incom,
        'remarks'  => trim((string)($c['remarks'] ?? '')),
    ];
    $rank = [
        sp_exam_kind($c['exam_name'] ?? null),
        sp_marks_filled($c['marks_json'] ?? null),
        (string)($c['published_at'] ?? ''),
        (int)$c['sheet_id'],
    ];
    sp_add_course($terms, $slots, $term, $row, $rank, $exam_label, $c['published_at'] ?? null);
    if (!empty($c['offer_subject_id'])) $published_offer_subjects[(int)$c['offer_subject_id']] = true;
}

// ── Source 2: Spring Result sets (sr_results) ───────────────────────────────────────

if ($sid !== '') {
    try {
        $st = db()->prepare(
            "SELECT r.id, r.title, r.semester, r.created_at
               FROM sr_results r
              WHERE r.is_published = 1
                AND EXISTS (SELECT 1 FROM sr_result_entries e
                             WHERE e.result_id = r.id AND e.student_id = ?)
              ORDER BY r.created_at ASC, r.id ASC"
        );
        $st->execute([$sid]);
        $sets = $st->fetchAll(PDO::FETCH_ASSOC);

        $est = db()->prepare(
            'SELECT student_name, course_code, course_title, letter_grade, grade_point, credit
               FROM sr_result_entries
              WHERE result_id = ? AND student_id = ?
              ORDER BY course_code ASC, course_title ASC'
        );
        foreach ($sets as $res) {
            $est->execute([(int)$res['id'], $sid]);
            $entries = $est->fetchAll(PDO::FETCH_ASSOC);
            if (empty($entries)) continue;

            $title = trim((string)($res['title'] ?? ''));
            $term  = sp_parse_term($res['semester'] ?? null)
                  ?: sp_parse_term($title)
                  ?: ['label' => $title !== '' ? $title : 'Result set #' . (int)$res['id'], 'sort' => 0];
            $kind  = sp_exam_kind($title);

            foreach ($entries as $e) {
                if ($sname === '' && !empty($e['student_name'])) $sname = (string)$e['student_name'];

                $letter   = trim((string)($e['letter_grade'] ?? ''));
                $is_incom = sp_is_incom_letter($letter);
                $point    = $is_incom ? null
                          : ($e['grade_point'] !== null ? (float)$e['grade_point'] : sp_grade_point($letter));
                $row = [
                    'code'     => (string)($e['course_code'] ?? ''),
                    'title'    => (string)($e['course_title'] ?? ''),
                    'credits'  => ($e['credit'] !== null && $e['credit'] !== '') ? (float)$e['credit'] : null,
                    'grade'    => $is_incom ? 'Incom' : $letter,
                    'point'    => $point,
                    'is_incom' => $is_incom,
                    'remarks'  => '',
                ];
                $rank = [$kind, 0, (string)($res['created_at'] ?? ''), (int)$res['id']];
                sp_add_course($terms, $slots, $term, $row, $rank, $title, $res['created_at'] ?? null);
            }
        }
    } catch (Throwable $e) {
        error_log('Student results: sr_results query failed – ' . $e->getMessage());
    }
}

// ── Source 3: archived / imported rows (student_results) ────────────────────────────

$final_cgpa = null;
if ($student_pk > 0) {
    try {
        $st = db()->prepare(
            'SELECT * FROM student_results WHERE student_id = ? ORDER BY semester_year ASC, semester ASC, id ASC'
        );
        $st->execute([$student_pk]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (strcasecmp(trim((string)($r['subject'] ?? '')), 'Final Result') === 0) {
                if (is_numeric($r['cgpa'] ?? null)) $final_cgpa = (float)$r['cgpa']; // Final Result Publish row
                continue;
            }
            $raw  = trim((string)($r['semester'] ?? '') . ' ' . (string)($r['semester_year'] ?? ''));
            $term = sp_parse_term($raw) ?: [
                'label' => $raw !== '' ? $raw : 'Archived results',
                'sort'  => ((int)($r['semester_year'] ?? 0)) * 10,
            ];
            $letter   = trim((string)($r['grade'] ?? ''));
            $is_incom = sp_is_incom_letter($letter);
            $row = [
                'code'     => (string)($r['subject_code'] ?? ''),
                'title'    => (string)($r['subject'] ?? ''),
                'credits'  => is_numeric($r['credits'] ?? null) ? (float)$r['credits'] : null,
                'grade'    => $is_incom ? 'Incom' : $letter,
                'point'    => $is_incom ? null : sp_grade_point($letter),
                'is_incom' => $is_incom,
                'remarks'  => '',
            ];
            $rank = [1, 0, '', (int)($r['id'] ?? 0)];
            sp_add_course($terms, $slots, $term, $row, $rank, '', $r['created_at'] ?? null);
        }
    } catch (Throwable $e) {
        // legacy table may not exist on every deployment
    }
}

// ── Source 4: registered courses whose result is NOT published yet ──────────────
// Same rule as my-results.php: every course the student registered for (Course
// Offers) that has no published grade is listed in its semester as
// "Not published yet" with the course teacher's name – but only for terms whose
// exam is already over. Workflow state (draft / pending) is never revealed.

$done_terms = []; // 'Spring 2026' => true  (term has at least one completed exam)
try {
    // (a) the exam name / year itself carries the term, e.g. "Final Examination Spring 2026"
    $st = db()->query("SELECT exam_name, exam_year FROM ei_exams WHERE end_date IS NOT NULL AND end_date < CURDATE()");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $t = sp_parse_term(trim((string)$e['exam_name'] . ' ' . (string)($e['exam_year'] ?? '')));
        if ($t) $done_terms[$t['label']] = true;
    }
    // (b) any mark sheet linked to a completed exam reveals that exam's term
    $st = db()->query(
        "SELECT DISTINCT o.semester
           FROM result_mark_sheets ms
           JOIN ei_exams e            ON e.id  = ms.exam_id
           JOIN co_offer_subjects cos ON cos.id = ms.offer_subject_id
           JOIN co_offers o           ON o.id  = cos.offer_id
          WHERE e.end_date IS NOT NULL AND e.end_date < CURDATE()
            AND o.semester IS NOT NULL AND o.semester <> ''"
    );
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $t = sp_parse_term((string)$s);
        if ($t) $done_terms[$t['label']] = true;
    }
} catch (Throwable $e) {
    error_log('Student results: completed-terms query failed – ' . $e->getMessage());
}

if ($student_pk > 0 && !empty($done_terms)) {
    try {
        $st = db()->prepare(
            "SELECT cos.id AS offer_subject_id, o.semester,
                    c.course_code, c.course_name, c.credit,
                    (SELECT GROUP_CONCAT(f.name ORDER BY t.sort_order SEPARATOR ', ')
                       FROM co_offer_subject_teachers t
                       JOIN dept_faculty f ON f.id = t.faculty_id
                      WHERE t.offer_subject_id = cos.id) AS teachers
               FROM co_registrations r
               JOIN co_offer_subjects cos ON cos.id = r.offer_subject_id
               JOIN co_offers o           ON o.id  = cos.offer_id
               JOIN course_curriculum c   ON c.id  = cos.curriculum_id
              WHERE r.student_id = ?
              ORDER BY o.id DESC, cos.sort_order ASC, cos.id ASC"
        );
        $st->execute([$student_pk]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $reg) {
            if (isset($published_offer_subjects[(int)$reg['offer_subject_id']])) continue;

            $term = sp_parse_term($reg['semester'] ?? null);
            // Only terms whose exam is already over are listed; a course of a term
            // with no completed exam (or an unparseable term) is not "due" yet.
            if (!$term || !isset($done_terms[$term['label']])) continue;

            $row = [
                'code'     => (string)($reg['course_code'] ?? ''),
                'title'    => (string)($reg['course_name'] ?? ''),
                'credits'  => ($reg['credit'] !== null && $reg['credit'] !== '') ? (float)$reg['credit'] : null,
                'grade'    => 'Not published yet',
                'point'    => null,
                'is_incom' => false,
                'remarks'  => '',
                'pending'  => true,
                'teachers' => trim((string)($reg['teachers'] ?? '')),
            ];
            // Lowest possible rank: a published result of the same course in the
            // term always wins, and a course registered twice is listed only once.
            sp_add_course($terms, $slots, $term, $row, [-1, 0, '', 0]);
        }
    } catch (Throwable $e) {
        error_log('Student results: registrations query failed – ' . $e->getMessage());
    }
}

// ── Semester GPA + running CGPA (chronological, same rules as the web page) ───────

uasort($terms, static fn($a, $b) => ($a['sort'] <=> $b['sort']) ?: strcmp($a['label'], $b['label']));

$cum_points    = 0.0;
$cum_credits   = 0.0;
$attempts      = []; // course key => ['credits','point'] currently counted in the CGPA
$pending_total = 0;  // registered courses with no published result yet

foreach ($terms as &$t) {
    // Published courses first, then not-yet-published; natural order by code inside each group.
    usort($t['courses'], static fn($a, $b) =>
        ((int)!empty($a['pending']) <=> (int)!empty($b['pending']))
        ?: strnatcasecmp($a['code'] . $a['title'], $b['code'] . $b['title']));

    $sem_points = 0.0; $sem_credits = 0.0; $sem_incom = 0; $sem_f = 0; $sem_pending = 0;
    foreach ($t['courses'] as $c) {
        if (!empty($c['pending'])) { $sem_pending++; $pending_total++; continue; } // never counted
        if ($c['is_incom'] || $c['point'] === null) { $sem_incom++; continue; }
        if (sp_is_fail($c['grade'], $c['point']))   { $sem_f++;     continue; } // not completed → not counted
        $cr = $c['credits'] ?? 0.0;
        if ($cr <= 0) continue; // cannot weight without credits

        $sem_points  += $cr * $c['point'];
        $sem_credits += $cr;

        $ck = sp_course_key($c['code'], $c['title']);
        if (SP_CGPA_LATEST_ATTEMPT_ONLY && isset($attempts[$ck])) {
            $prev = $attempts[$ck];
            $cum_points  -= $prev['credits'] * $prev['point'];
            $cum_credits -= $prev['credits'];
        }
        $attempts[$ck] = ['credits' => $cr, 'point' => $c['point']];
        $cum_points  += $cr * $c['point'];
        $cum_credits += $cr;
    }

    $t['gpa']       = ($sem_credits > 0 && $sem_f === 0 && $sem_incom === 0) ? round($sem_points / $sem_credits, 2) : null;
    $t['fails']     = $sem_f;
    $t['incom']     = $sem_incom;
    $t['pending']   = $sem_pending;
    $t['published'] = count($t['courses']) - $sem_pending;
    $t['credits']   = $sem_credits;
    // The running CGPA is shown only once the term has a published course (the web page shows "—").
    $t['cgpa']      = ($t['published'] > 0 && $cum_credits > 0) ? round($cum_points / $cum_credits, 2) : null;
}
unset($t);

$overall_cgpa        = $cum_credits > 0 ? round($cum_points / $cum_credits, 2) : null;
$published_sem_count = count(array_filter($terms, static fn($s) => ($s['published'] ?? 0) > 0));

// ── Output (newest semester first) ──────────────────────────────────────────────────

$results = [];
foreach (array_reverse($terms, true) as $t) {
    $id = (int)(crc32($t['label']) & 0x7fffffff); // stable per semester label
    if ($filter_result_id > 0 && $id !== $filter_result_id) continue;

    $withheld = $t['fails'] > 0 || $t['incom'] > 0;
    $reason   = [];
    if ($t['fails'] > 0) $reason[] = 'F grade';
    if ($t['incom'] > 0) $reason[] = 'Incom';

    $entries = [];
    foreach ($t['courses'] as $c) {
        $entries[] = [
            'course_code'  => $c['code'],
            'course_title' => $c['title'],
            'credit'       => $c['credits'],
            'letter_grade' => $c['grade'],
            'grade_point'  => $c['point'],
            'remarks'      => $c['remarks'],
            'is_pending'   => !empty($c['pending']),
            'teachers'     => (string)($c['teachers'] ?? ''),
        ];
    }

    $exam = ($t['exam'] !== '' && $t['exam'] !== $t['label']) ? $t['exam'] : '';

    $results[] = [
        'id'             => $id,
        'title'          => $t['label'],
        'semester'       => $exam,          // shown under the title in the app
        'exam'           => $exam,
        'published_at'    => (string)($t['published_at'] ?? ''),
        'course_count'    => count($entries),
        'published_count' => (int)$t['published'],
        'pending_count'   => (int)$t['pending'],
        'credits'         => $t['credits'],
        'gpa'            => $t['gpa'],
        'gpa_incomplete' => $withheld,
        'gpa_status'     => $withheld ? implode(' / ', $reason) : '',
        'cgpa'           => $t['cgpa'],
        'entries'        => $entries,
    ];
}

sp_api_ok([
    'student_id'      => $sid,
    'student_name'    => $sname,
    'cgpa'            => $final_cgpa ?? $overall_cgpa,
    'cgpa_is_final'   => $final_cgpa !== null,
    'credits_counted' => $cum_credits,
    'semesters_published' => $published_sem_count,
    'pending_count'   => $pending_total,
    'results'         => $results,
]);
