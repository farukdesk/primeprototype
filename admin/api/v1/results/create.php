<?php
/**
 * Third-party API – POST /admin/api/v1/results/create.php
 * ========================================================
 * Publishes a student's final result (CGPA) into the `student_results` table,
 * the table the public certificate-verification page reads, in exactly the
 * way the admin "Final Result Publish" import does:
 *
 *   * same columns  (student_id → students.id, semester, semester_year, batch,
 *                    subject, cgpa, recorded_date)
 *   * same upsert key (student, subject, semester, semester_year): an existing
 *                    row is updated instead of duplicated
 *   * same status rule: a student whose status is Active or Dropped (or when
 *                    mark_graduated=true) becomes "Graduated"
 *
 * Differences from the admin import, on purpose:
 *   * the student MUST already exist (404 student_not_found) – partners create
 *     students through /v1/students/create.php first
 *   * "incom." / "withheld" CGPAs are rejected (422) instead of silently skipped
 *
 * Auth : X-API-Key with scope `results:create`
 * Body : application/json
 *   single : { "student_id": "...", "semester": "Fall 2024", "cgpa": 3.42, ... }
 *   bulk   : { "results": [ {...}, {...} ], "recorded_date": "2025-01-10" }   (max 200)
 *            top-level subject / semester / batch / recorded_date / mark_graduated
 *            act as defaults for every item.
 * Docs : ../API-GUIDE.md §7
 *
 * Single : 201 created | 200 updated | 404 student_not_found | 422 validation_failed
 * Bulk   : 200 with per-item outcome (422 only when every item failed)
 */

require_once dirname(__DIR__, 2) . '/includes/auth_client_api.php';
require_once dirname(__DIR__) . '/includes/student_helpers.php';

const CAPI_RESULT_SUBJECT_DEFAULT = 'Final Result';   // = FRP_SUBJECT_LABEL in admin/final-result-publish
const CAPI_RESULT_BULK_MAX        = 200;
const CAPI_RESULT_IGNORED_CGPA    = ['incom', 'incomp', 'incomplete', 'inc', 'withheld', 'withhold', 'wh'];
const CAPI_RESULT_GRADUATE_FROM   = ['Active', 'Dropped'];  // auto-corrected to Graduated, as in the admin import

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    capi_error(405, 'method_not_allowed', 'Use POST.');
}

$client = capi_auth('results:create');
capi_begin_request($client, 'v1/results/create');

$in = capi_json_input();

// ── Helpers ───────────────────────────────────────────────────────────────────

/** First non-empty scalar among the alias keys, trimmed; null when absent. */
function capi_result_scalar(array $src, array $keys): ?string
{
    foreach ($keys as $k) {
        if (isset($src[$k]) && is_scalar($src[$k])) {
            $v = trim((string)$src[$k]);
            if ($v !== '') {
                return $v;
            }
        }
    }
    return null;
}

/** Lenient boolean parser; null when the value is not recognisable. */
function capi_result_bool(mixed $v): ?bool
{
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) || is_float($v)) {
        return $v != 0;
    }
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if (in_array($s, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'n', 'off', ''], true)) {
            return false;
        }
    }
    return null;
}

/**
 * Same lookup rule as admin/final-result-publish: an exact Student ID wins,
 * otherwise the leading-zero variant ("0123" ↔ "123") is accepted.
 */
function capi_result_find_student(string $sid): ?array
{
    $trimmed = ltrim($sid, '0');
    if ($trimmed === '') {
        $trimmed = $sid;
    }
    $stmt = db()->prepare(
        "SELECT id, student_id, full_name, status, batch
           FROM students
          WHERE student_id = ? OR student_id = ? OR TRIM(LEADING '0' FROM student_id) = ?
          ORDER BY (student_id = ?) DESC
          LIMIT 1"
    );
    $stmt->execute([$sid, $trimmed, $trimmed, $sid]);
    return $stmt->fetch() ?: null;
}

/**
 * Validate one result item against the admin import rules.
 *
 * @return array{errors:array<string,string>,warnings:string[],data:array<string,mixed>}
 */
function capi_result_validate(array $item, array $defaults): array
{
    $errors   = [];
    $warnings = [];

    // ── Student ────────────────────────────────────────────────────────────────
    $sid_raw = capi_result_scalar($item, ['student_id', 'sid']);
    $sid     = $sid_raw !== null ? preg_replace('/\s+/u', '', $sid_raw) : '';
    $student = null;

    if ($sid === '') {
        $errors['student_id'] = 'Student ID is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9\-]{1,25}$/', $sid)) {
        $errors['student_id'] = 'Must be 1-25 letters, digits or hyphens.';
    } else {
        $student = capi_result_find_student($sid);
        if ($student === null) {
            $errors['student_id'] = 'No student with ID "' . $sid . '". Create the student first via POST /v1/students/create.php.';
        } elseif ($student['student_id'] !== $sid) {
            $warnings[] = 'Matched by leading-zero variant: "' . $sid . '" resolved to "' . $student['student_id'] . '".';
        }
    }

    $name = capi_result_scalar($item, ['student_name', 'name']);
    if ($student !== null && $name !== null) {
        $norm = static fn(string $s): string => strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
        if ($norm($name) !== $norm((string)$student['full_name'])) {
            $warnings[] = 'student_name "' . $name . '" differs from the record "' . $student['full_name'] . '"; the record is kept.';
        }
    }

    // ── Subject ────────────────────────────────────────────────────────────────
    $subject = capi_result_scalar($item, ['subject']) ?? $defaults['subject'] ?? CAPI_RESULT_SUBJECT_DEFAULT;
    if (mb_strlen($subject) > 100) {
        $errors['subject'] = 'Must be 100 characters or fewer.';
    }

    // ── Completion semester ──────────────────────────────────────────────────────
    $sem_raw = capi_result_scalar($item, ['semester', 'completion_semester', 'ending_semester'])
        ?? $defaults['semester'] ?? null;
    $season = null;
    $year   = null;
    if ($sem_raw === null) {
        $errors['semester'] = 'Completion semester is required, e.g. "Fall 2024".';
    } else {
        $norm = capi_normalize_semester($sem_raw);
        if ($norm === null) {
            $errors['semester'] = 'Must be "<Spring|Summer|Fall> <YYYY>", e.g. "Fall 2024".';
        } else {
            [$season, $year] = explode(' ', $norm, 2);
        }
    }

    // ── CGPA ───────────────────────────────────────────────────────────────────
    $cgpa_raw = capi_result_scalar($item, ['cgpa', 'final_cgpa', 'gpa']);
    $cgpa     = null;
    if ($cgpa_raw === null) {
        $errors['cgpa'] = 'CGPA is required.';
    } else {
        $key = preg_replace('/[^a-z]/', '', strtolower($cgpa_raw));
        if ($key !== '' && in_array($key, CAPI_RESULT_IGNORED_CGPA, true)) {
            $errors['cgpa'] = 'Incomplete / withheld results cannot be published. Send the result once a final CGPA exists.';
        } else {
            $val = filter_var($cgpa_raw, FILTER_VALIDATE_FLOAT);
            if ($val === false) {
                $errors['cgpa'] = 'Must be a number.';
            } elseif ($val <= 0 || $val > 4.0) {
                $errors['cgpa'] = 'Must be between 0.01 and 4.00.';
            } else {
                $cgpa = number_format($val, 2, '.', '');
            }
        }
    }

    // ── Batch (falls back to the student's own batch) ─────────────────────────────
    $batch = capi_result_scalar($item, ['batch']) ?? $defaults['batch'] ?? null;
    if ($batch !== null && mb_strlen($batch) > 50) {
        $errors['batch'] = 'Must be 50 characters or fewer.';
    }
    if ($batch === null && $student !== null && !empty($student['batch'])) {
        $batch = (string)$student['batch'];
    }

    // ── Result publish date (defaults to today) ───────────────────────────────────
    $date = capi_result_scalar($item, ['recorded_date', 'publish_date', 'result_publish_date'])
        ?? $defaults['recorded_date'] ?? date('Y-m-d');
    if (!capi_valid_date($date)) {
        $errors['recorded_date'] = 'Must be YYYY-MM-DD.';
    } elseif ($date > date('Y-m-d')) {
        $errors['recorded_date'] = 'Cannot be in the future.';
    }

    // ── mark_graduated ───────────────────────────────────────────────────────────
    $mg_in = array_key_exists('mark_graduated', $item) ? $item['mark_graduated'] : ($defaults['mark_graduated'] ?? false);
    $mg    = capi_result_bool($mg_in);
    if ($mg === null) {
        $errors['mark_graduated'] = 'Must be true or false.';
    }

    return [
        'errors'   => $errors,
        'warnings' => $warnings,
        'data'     => [
            'student'        => $student,
            'sid_input'      => $sid,
            'subject'        => $subject,
            'season'         => $season,
            'year'           => $year,
            'cgpa'           => $cgpa,
            'batch'          => $batch,
            'recorded_date'  => $date,
            'mark_graduated' => (bool)$mg,
        ],
    ];
}

/**
 * Upsert the student_results row and apply the graduation rule.
 *
 * @return array{action:string,result_id:int,student_status:string}
 */
function capi_result_persist(array $d, array $client): array
{
    $db = db();
    $s  = $d['student'];
    $pk = (int)$s['id'];

    $db->beginTransaction();
    try {
        $chk = $db->prepare(
            'SELECT id FROM student_results
              WHERE student_id = ? AND subject = ? AND semester = ? AND semester_year = ?
              LIMIT 1'
        );
        $chk->execute([$pk, $d['subject'], $d['season'], $d['year']]);
        $rid = (int)$chk->fetchColumn();

        if ($rid > 0) {
            $db->prepare(
                'UPDATE student_results
                    SET cgpa = ?, batch = ?, recorded_date = ?, api_client_id = ?
                  WHERE id = ?'
            )->execute([$d['cgpa'], $d['batch'], $d['recorded_date'], (int)$client['id'], $rid]);
            $action = 'updated';
        } else {
            $db->prepare(
                'INSERT INTO student_results
                    (student_id, semester, semester_year, batch, subject, cgpa, recorded_date, api_client_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$pk, $d['season'], $d['year'], $d['batch'], $d['subject'], $d['cgpa'], $d['recorded_date'], (int)$client['id']]);
            $rid    = (int)$db->lastInsertId();
            $action = 'created';
        }

        // A student with a valid final CGPA has graduated (same rule as the admin import).
        $status = (string)$s['status'];
        if ($status !== 'Graduated' && ($d['mark_graduated'] || in_array($status, CAPI_RESULT_GRADUATE_FROM, true))) {
            $db->prepare("UPDATE students SET status = 'Graduated' WHERE id = ?")->execute([$pk]);
            $status = 'Graduated';
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    // Best-effort audit trail, same shape the admin import writes.
    if ($client['created_by'] !== null) {
        try {
            $db->prepare(
                'INSERT INTO change_log
                    (user_id, module, record_id, record_label, action, field_name, old_value, new_value, description, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)'
            )->execute([
                (int)$client['created_by'], 'students', $pk, $s['full_name'] . ' (' . $s['student_id'] . ')',
                'UPDATE', 'final_result', $d['cgpa'],
                'Final result ' . $action . ' via API client "' . $client['name'] . '" ('
                    . $d['season'] . ' ' . $d['year'] . ', CGPA ' . $d['cgpa'] . ')',
                capi_client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('api/v1/results/create change_log: ' . $e->getMessage());
        }
    }

    return ['action' => $action, 'result_id' => $rid, 'student_status' => $status];
}

// ── Single vs bulk ────────────────────────────────────────────────────────────

$is_bulk  = array_key_exists('results', $in);
$defaults = [];

if ($is_bulk) {
    if (!is_array($in['results']) || $in['results'] === []) {
        capi_error(422, 'validation_failed', '"results" must be a non-empty array of result objects.', ['results' => 'Must be a non-empty array.']);
    }
    if (count($in['results']) > CAPI_RESULT_BULK_MAX) {
        capi_error(422, 'validation_failed', 'Too many items.', ['results' => 'At most ' . CAPI_RESULT_BULK_MAX . ' items per request.']);
    }
    $items = array_values($in['results']);
    foreach ([
        'subject'       => ['subject'],
        'semester'      => ['semester', 'completion_semester', 'ending_semester'],
        'batch'         => ['batch'],
        'recorded_date' => ['recorded_date', 'publish_date', 'result_publish_date'],
    ] as $key => $aliases) {
        $v = capi_result_scalar($in, $aliases);
        if ($v !== null) {
            $defaults[$key] = $v;
        }
    }
    if (array_key_exists('mark_graduated', $in)) {
        $defaults['mark_graduated'] = $in['mark_graduated'];
    }
} else {
    $items = [$in];
}

// ── Process items (each in its own transaction) ──────────────────────────────────

$out     = [];
$seen    = [];   // upsert key => item index, to catch duplicates inside one request
$created = 0;
$updated = 0;
$failed  = 0;

foreach ($items as $idx => $item) {
    if (!is_array($item)) {
        $out[] = ['index' => $idx, 'status' => 'failed', 'student_id' => null, 'errors' => ['_' => 'Each item must be an object.']];
        $failed++;
        continue;
    }

    $v = capi_result_validate($item, $defaults);
    $d = $v['data'];

    if (!$v['errors'] && $d['student'] !== null) {
        $key = $d['student']['id'] . '|' . $d['subject'] . '|' . $d['season'] . '|' . $d['year'];
        if (isset($seen[$key])) {
            $v['errors']['student_id'] = 'Duplicate of item #' . $seen[$key] . ' (same student, subject and semester) in this request.';
        } else {
            $seen[$key] = $idx;
        }
    }

    $entry = [
        'index'      => $idx,
        'student_id' => $d['student'] !== null ? $d['student']['student_id'] : ($d['sid_input'] !== '' ? $d['sid_input'] : null),
    ];

    if ($v['errors']) {
        $entry['status'] = 'failed';
        $entry['errors'] = $v['errors'];
        $failed++;
    } else {
        try {
            $r = capi_result_persist($d, $client);
            $entry += [
                'status'        => 'ok',
                'action'        => $r['action'],
                'result_id'     => $r['result_id'],
                'student'       => [
                    'id'         => (int)$d['student']['id'],
                    'student_id' => $d['student']['student_id'],
                    'full_name'  => $d['student']['full_name'],
                    'status'     => $r['student_status'],
                ],
                'subject'       => $d['subject'],
                'semester'      => $d['season'] . ' ' . $d['year'],
                'cgpa'          => $d['cgpa'],
                'batch'         => $d['batch'],
                'recorded_date' => $d['recorded_date'],
            ];
            $r['action'] === 'created' ? $created++ : $updated++;
        } catch (Throwable $e) {
            error_log('api/v1/results/create item ' . $idx . ': ' . $e->getMessage());
            $entry['status'] = 'failed';
            $entry['errors'] = ['_' => 'The result could not be saved. Retry; if it persists contact the university IT office.'];
            $failed++;
        }
    }

    if ($v['warnings']) {
        $entry['warnings'] = $v['warnings'];
    }
    $out[] = $entry;
}

// ── Response ──────────────────────────────────────────────────────────────────

if (!$is_bulk) {
    $e = $out[0];
    if ($e['status'] === 'failed') {
        $errs = $e['errors'];
        if (isset($errs['_'])) {
            capi_error(500, 'server_error', $errs['_']);
        }
        if (count($errs) === 1 && isset($errs['student_id']) && str_starts_with($errs['student_id'], 'No student')) {
            capi_error(404, 'student_not_found', $errs['student_id'], $errs);
        }
        capi_error(422, 'validation_failed', 'One or more fields are invalid.', $errs);
    }

    $GLOBALS['CAPI']['student_db_id'] = $e['student']['id'];
    $warnings = $e['warnings'] ?? [];
    unset($e['index'], $e['status'], $e['warnings']);

    capi_ok([
        'message'  => $e['action'] === 'created' ? 'Result published.' : 'Existing result updated.',
        'data'     => $e,
        'warnings' => $warnings,
    ], $e['action'] === 'created' ? 201 : 200);
}

$summary = ['total' => count($items), 'created' => $created, 'updated' => $updated, 'failed' => $failed];

if ($failed === count($items)) {
    capi_respond(422, [
        'ok'      => false,
        'code'    => 'validation_failed',
        'message' => 'Every item in the request failed. See results[].errors.',
        'summary' => $summary,
        'results' => $out,
    ]);
}

capi_ok([
    'message' => $failed === 0
        ? 'All results processed.'
        : $failed . ' of ' . count($items) . ' items failed. See results[].errors.',
    'summary' => $summary,
    'results' => $out,
]);
