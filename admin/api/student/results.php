<?php
/**
 * Student Portal API – GET /api/student/results.php
 * ==================================================
 * Published semester results of the signed-in student. Mirrors the public
 * web result page (spring-result.php): same sr_results / sr_result_entries
 * tables, same ordering and the same GPA rule (credit-weighted average;
 * reported as incomplete when any course is graded F or INCOM).
 *
 * Optional query: ?result_id=<id> to restrict to one result set.
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

// ── Query ─────────────────────────────────────────────────────────────────────

try {
    $extra_where = $filter_result_id > 0 ? 'AND r.id = ?' : '';
    $params      = $filter_result_id > 0 ? [$sid, $filter_result_id] : [$sid];

    $stmt = db()->prepare(
        "SELECT r.id, r.title, r.semester, r.created_at
         FROM sr_results r
         WHERE r.is_published = 1
           AND EXISTS (
               SELECT 1 FROM sr_result_entries e
               WHERE e.result_id = r.id AND e.student_id = ?
           )
           $extra_where
         ORDER BY r.created_at DESC"
    );
    $stmt->execute($params);
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

$results = [];

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
