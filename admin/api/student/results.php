<?php
/**
 * Student Portal API – GET /api/student/results.php
 * ==================================================
 * Published semester results of the signed-in student. Mirrors the public
 * web result page (spring-result.php): same sr_results / sr_result_entries
 * tables, same ordering and the same GPA rule (credit-weighted average;
 * reported as incomplete when any course is graded F or INCOM).
 *
 * Duplicate rule: when the same course appears in more than one published
 * result set of the same term (e.g. a mid-term set that only carries the
 * mid-term marks and, later, the final set), only the highest-priority entry
 * is returned – final > unspecified > mid-term, then the most recently
 * created set. The final result therefore always replaces the mid-term one.
 *
 * Optional query: ?result_id=<id> to restrict the response to one result set
 * (the duplicate rule is still applied across all of the student's sets first).
 *
 * Success response:
 *   { "ok": true,
 *     "student_id": "193020101021", "student_name": "...",
 *     "results": [ { id, title, semester, published_at, course_count,
 *                    gpa, gpa_incomplete,
 *                    entries: [ { course_code, course_title, credit,
 *                                 letter_grade, grade_point }, ... ] }, ... ] }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx     = sp_api_auth();
$student = $ctx['student'];
$sid     = trim((string)($student['student_id'] ?? ''));
$sname   = (string)($student['student_name'] ?? '');

if ($sid === '') {
    sp_api_ok(['student_id' => '', 'student_name' => $sname, 'results' => []]);
    exit;
}

$filter_result_id = (int)($_GET['result_id'] ?? 0);

// ── Helpers (same rules as spring-result.php) ─────────────────────────────────

function sp_results_has_fail_or_incom(array $entries): bool
{
    foreach ($entries as $e) {
        $g = strtoupper(trim((string)$e['letter_grade']));
        if ($g === 'F' || $g === 'INCOM') {
            return true;
        }
    }
    return false;
}

function sp_results_gpa(array $entries): ?float
{
    // Credit-weighted GPA: Σ(grade_point × credit) / Σ(credit)
    $total_points  = 0.0;
    $total_credits = 0.0;
    foreach ($entries as $e) {
        if ($e['grade_point'] !== null && $e['credit'] !== null && (float)$e['credit'] > 0) {
            $credit         = (float)$e['credit'];
            $total_points  += (float)$e['grade_point'] * $credit;
            $total_credits += $credit;
        }
    }
    if ($total_credits > 0) {
        return round($total_points / $total_credits, 2);
    }
    // Fallback: simple average when no credits are stored
    $total = 0.0;
    $count = 0;
    foreach ($entries as $e) {
        if ($e['grade_point'] !== null) {
            $total += (float)$e['grade_point'];
            $count++;
        }
    }
    return $count > 0 ? round($total / $count, 2) : null;
}

// ── Helpers (duplicate rule: final result over mid-term) ───────────────────────

/**
 * Mid-term vs final priority of a result set, derived from its title:
 * 2 = final, 1 = unspecified, 0 = mid-term.
 */
function sp_results_kind(string $title): int
{
    $t = strtolower(trim($title));
    if ($t === '') return 1;
    if (preg_match('/\bfinal\b/', $t)) return 2;
    if (preg_match('/\bmid\s*-?\s*term\b|\bmidterm\b|\bmid\b/', $t)) return 0;
    return 1;
}

/**
 * Normalised term label ("Spring 2026") of a result set so that the mid-term
 * and final sets of the same semester can be matched. Falls back to the raw
 * semester / title when no season + year can be recognised.
 */
function sp_results_term_key(array $res): string
{
    foreach ([(string)($res['semester'] ?? ''), (string)($res['title'] ?? '')] as $raw) {
        if (preg_match('/\b(spring|summer|fall|autumn|winter)\b\s*[-_\/]?\s*(\d{2,4})/i', $raw, $m)) {
            $season = ucfirst(strtolower($m[1]));
            if ($season === 'Autumn') $season = 'Fall';
            $year = (int)$m[2];
            if ($year < 100) $year += 2000;
            return $season . ' ' . $year;
        }
    }
    $raw = trim((string)($res['semester'] ?? ''));
    return strtolower($raw !== '' ? $raw : (string)($res['title'] ?? ''));
}

/** Course key used to detect the same course listed twice (code, else title). */
function sp_results_course_key(array $e): string
{
    $code = strtoupper(preg_replace('/\s+/', '', (string)($e['course_code'] ?? '')));
    if ($code !== '') return $code;
    return 'T:' . strtoupper(preg_replace('/\s+/', '', (string)($e['course_title'] ?? '')));
}

// ── Query ─────────────────────────────────────────────────────────────────────

try {
    // All published sets of the student are loaded (even when ?result_id= is
    // given) so that the duplicate rule can compare mid-term and final sets.
    $stmt = db()->prepare(
        "SELECT r.id, r.title, r.semester, r.created_at
         FROM sr_results r
         WHERE r.is_published = 1
           AND EXISTS (
               SELECT 1 FROM sr_result_entries e
               WHERE e.result_id = r.id AND e.student_id = ?
           )
         ORDER BY r.created_at DESC"
    );
    $stmt->execute([$sid]);
    $result_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $estmt = db()->prepare(
        'SELECT student_name, course_code, course_title, letter_grade, grade_point, credit
         FROM sr_result_entries
         WHERE result_id = ? AND student_id = ?
         ORDER BY course_code ASC, course_title ASC'
    );
} catch (Throwable $e) {
    error_log('Student results: query failed – ' . $e->getMessage());
    sp_api_error(500, 'Could not load results. Please try again.');
}

// ── Load every published set with the student's entries ───────────────────────

$sets = []; // [ ['res' => row, 'entries' => rows], ... ]

foreach ($result_rows as $res) {
    try {
        $estmt->execute([(int)$res['id'], $sid]);
        $entries = $estmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Student results: entries query failed – ' . $e->getMessage());
        continue;
    }
    if (empty($entries)) {
        continue;
    }

    // Prefer the name stored on the result sheet, as the web page does.
    if ($sname === '') {
        foreach ($entries as $e) {
            if (!empty($e['student_name'])) {
                $sname = (string)$e['student_name'];
                break;
            }
        }
    }

    $sets[] = ['res' => $res, 'entries' => $entries];
}

// ── Same course published more than once in one term ─────────────────────────
// Keep only the highest-ranked entry per (term, course): a final result set
// always wins over a mid-term one; otherwise the most recently created set.

$best = []; // "Term|COURSE" => ['set' => i, 'entry' => j, 'rank' => [...]]

foreach ($sets as $i => $set) {
    $term = sp_results_term_key($set['res']);
    $rank = [
        sp_results_kind((string)($set['res']['title'] ?? '')),
        (string)($set['res']['created_at'] ?? ''),
        (int)$set['res']['id'],
    ];
    foreach ($set['entries'] as $j => $e) {
        $k = $term . '|' . sp_results_course_key($e);
        if (!isset($best[$k])) {
            $best[$k] = ['set' => $i, 'entry' => $j, 'rank' => $rank];
            continue;
        }
        if (($rank <=> $best[$k]['rank']) > 0) {
            // This entry outranks the one kept so far – drop the earlier one.
            unset($sets[$best[$k]['set']]['entries'][$best[$k]['entry']]);
            $best[$k] = ['set' => $i, 'entry' => $j, 'rank' => $rank];
        } else {
            unset($sets[$i]['entries'][$j]);
        }
    }
}

// ── Output ────────────────────────────────────────────────────────────────────

$results = [];

foreach ($sets as $set) {
    $res     = $set['res'];
    $entries = array_values($set['entries']);

    // Every course of this set was superseded (e.g. a mid-term set whose
    // courses are all covered by the final set) – nothing left to show.
    if (empty($entries)) {
        continue;
    }
    if ($filter_result_id > 0 && (int)$res['id'] !== $filter_result_id) {
        continue;
    }

    $incomplete = sp_results_has_fail_or_incom($entries);
    $gpa        = $incomplete ? null : sp_results_gpa($entries);

    $out_entries = [];
    foreach ($entries as $e) {
        $out_entries[] = [
            'course_code'  => (string)($e['course_code'] ?? ''),
            'course_title' => (string)($e['course_title'] ?? ''),
            'credit'       => $e['credit'] !== null ? (float)$e['credit'] : null,
            'letter_grade' => (string)($e['letter_grade'] ?? ''),
            'grade_point'  => $e['grade_point'] !== null ? (float)$e['grade_point'] : null,
        ];
    }

    $results[] = [
        'id'             => (int)$res['id'],
        'title'          => (string)$res['title'],
        'semester'       => (string)($res['semester'] ?? ''),
        'published_at'   => (string)($res['created_at'] ?? ''),
        'course_count'   => count($out_entries),
        'gpa'            => $gpa,
        'gpa_incomplete' => $incomplete,
        'entries'        => $out_entries,
    ];
}

sp_api_ok([
    'student_id'   => $sid,
    'student_name' => $sname,
    'results'      => $results,
]);
