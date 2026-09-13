<?php
/**
 * Third-party API – POST /admin/api/v1/results/create.php
 * ========================================================
 * Publishes a student's final result (CGPA) into the `student_results` table,
 * the table the public certificate-verification page reads, in exactly the
 * way the admin "Final Result Publish" import does (see includes/result_helpers.php).
 *
 * Differences from the admin import, on purpose:
 *   * the student MUST already exist (404 student_not_found) – partners create
 *     students through /v1/students/create.php first (optionally with an inline
 *     `result` object, which saves student + result in one call)
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
require_once dirname(__DIR__) . '/includes/result_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    capi_error(405, 'method_not_allowed', 'Use POST.');
}

$client = capi_auth('results:create');
capi_begin_request($client, 'v1/results/create');

$in = capi_json_input();

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
