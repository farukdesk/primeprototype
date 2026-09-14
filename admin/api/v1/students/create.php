<?php
/**
 * Third-party API – POST /admin/api/v1/students/create.php
 * =========================================================
 * Creates a student record directly in the `students` table (plus rows in
 * `student_academic_qualifications`) on behalf of an authorised external
 * application.  Field names, validation and the auto-generated student ID
 * follow admin/students/create.php so records are indistinguishable from
 * ones entered through the admin panel.
 *
 * Optionally the payload may carry a `result` object (alias `final_result`,
 * fields as in results/create.php).  The final result is then written to
 * `student_results` in the SAME transaction as the student, so a partner
 * portal can push "new student + their result" in one call.  Requires the
 * `results:create` scope in addition to `students:create`.
 *
 * Validation lives in ../includes/student_input.php and is shared with
 * students/update.php.
 *
 * Auth : X-API-Key with scope `students:create`  (includes/auth_client_api.php)
 * Body : application/json, or multipart/form-data when uploading the photo as
 *        a file (put the JSON document in the `payload` field, or send every
 *        field as a plain form field).
 * Docs : ../API-GUIDE.md
 *
 * 201  { ok:true, message, data:{…, result:{…}|null}, warnings:[…] }
 * 4xx  { ok:false, code, message, errors?:{field:message} }
 */

require_once dirname(__DIR__, 2) . '/includes/auth_client_api.php';
require_once dirname(__DIR__) . '/includes/student_input.php';   // loads result_helpers.php + student_helpers.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    capi_error(405, 'method_not_allowed', 'Use POST.');
}

$client = capi_auth('students:create');
capi_begin_request($client, 'v1/students/create');

$in = capi_json_input();
$v  = capi_student_validate($in, $client, null);

$errors   = $v['errors'];
$warnings = $v['warnings'];

// ── Validation result ─────────────────────────────────────────────────────────────

if ($errors) {
    if ($v['conflict'] && count($errors) === 1) {
        capi_error(409, 'duplicate_student_id', 'Student ID "' . $v['student_id'] . '" already exists.', $errors);
    }
    capi_error(422, 'validation_failed', 'One or more fields are invalid.', $errors);
}

$row          = $v['row'];
$qual_rows    = $v['quals'] ?? [];
$photo        = $v['photo'];
$student_id   = $v['student_id'];
$auto_id      = $v['auto_id'];
$dept         = $v['dept'];
$program      = $v['program'];
$result_d     = $v['result_d'];
$dept_id      = (int)$row['dept_id'];
$program_id   = (int)($row['program_id'] ?? 0);
$admitted_sem = (string)$row['admitted_semester'];
$full_name    = (string)$row['full_name'];
$status       = (string)$row['status'];
$batch        = $row['batch'] ?? null;
$email        = (string)($row['email'] ?? '');
$phone        = (string)($row['phone'] ?? '');

// Auto ID: continue the numbering already used by this admission cohort
// (same semester + department + program).  When the cohort has no numbering
// yet NOTHING is created – the Student ID must be issued by the university
// admin office and sent in `student_id`.
if ($auto_id) {
    $student_id = capi_generate_student_id($admitted_sem, $dept_id, $program_id);
    if ($student_id === null) {
        $cohort_label = $admitted_sem . ' / ' . $dept['code'] . ($program ? ' / ' . $program['program_name'] : '');
        capi_error(422, 'student_id_pattern_not_found',
            'No Student ID numbering exists yet for ' . $cohort_label
            . '. The student was not created. Please contact the Prime University admin office for a Student ID and send it in "student_id".',
            ['student_id' => 'Please contact the university admin for a Student ID (' . $cohort_label . ') and send it in "student_id".']);
    }
}

// Soft duplicate detection – never blocks, mirrors admin behaviour, but tells
// the caller so they can reconcile on their side.
try {
    foreach (['email' => $email, 'phone' => $phone] as $col => $val) {
        if ($val === '') {
            continue;
        }
        $dup = db()->prepare("SELECT student_id, full_name FROM students WHERE {$col} = ? LIMIT 1");
        $dup->execute([$val]);
        if ($d = $dup->fetch()) {
            $warnings[] = 'A student with the same ' . $col . ' already exists: ' . $d['full_name'] . ' (' . $d['student_id'] . ').';
        }
    }
} catch (Throwable $e) {
    error_log('students/create duplicate check: ' . $e->getMessage());
}

// ── Persist ───────────────────────────────────────────────────────────────────────────

$db         = db();
$photo_name = null;
$new_id     = 0;
$result_out = null;

try {
    if ($photo !== null) {
        $photo_name = capi_photo_commit($photo);
    }

    $row['student_id']    = '';            // filled per attempt below
    $row['photo']         = $photo_name;
    $row['created_by']    = $client['created_by'] !== null ? (int)$client['created_by'] : null;
    $row['api_client_id'] = (int)$client['id'];

    $cols = array_keys($row);
    $sql  = 'INSERT INTO students (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $ins  = $db->prepare($sql);

    // Auto-generated IDs can collide under concurrency; regenerate and retry.
    $attempts = $auto_id ? 3 : 1;
    for ($try = 1; $try <= $attempts; $try++) {
        if ($auto_id && $try > 1) {
            // ID raced with another insert – read the cohort again and take the next free number.
            $student_id = capi_generate_student_id($admitted_sem, $dept_id, $program_id) ?? $student_id;
        }
        $row['student_id'] = $student_id;
        try {
            $db->beginTransaction();
            $ins->execute(array_values($row));
            $new_id = (int)$db->lastInsertId();

            $qins = $db->prepare(
                'INSERT INTO student_academic_qualifications
                   (student_id, exam_title_id, exam_name, session, group_id, group_name,
                    board_id, board_university, passing_year, division_class_grade, obtained_marks_gpa, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($qual_rows as $qi => $q) {
                $qins->execute([
                    $new_id, $q['exam_title_id'], $q['exam_name'], $q['session'], $q['group_id'], $q['group_name'],
                    $q['board_id'], $q['board_university'], $q['passing_year'], $q['division_class_grade'],
                    $q['obtained_marks_gpa'], $qi,
                ]);
            }

            // Inline final result: same transaction, so student + result are all-or-nothing.
            if ($result_d !== null) {
                $result_d['student'] = [
                    'id'         => $new_id,
                    'student_id' => $student_id,
                    'full_name'  => $full_name,
                    'status'     => $status,
                    'batch'      => $batch,
                ];
                $r          = capi_result_persist($result_d, $client, false);
                $status     = $r['student_status'];
                $result_out = [
                    'action'        => $r['action'],
                    'result_id'     => $r['result_id'],
                    'subject'       => $result_d['subject'],
                    'semester'      => $result_d['season'] . ' ' . $result_d['year'],
                    'cgpa'          => $result_d['cgpa'],
                    'batch'         => $result_d['batch'],
                    'recorded_date' => $result_d['recorded_date'],
                ];
            }

            $db->commit();
            break;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000' && $auto_id && $try < $attempts) {
                continue; // ID raced with another insert – regenerate
            }
            throw $e;
        }
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    capi_photo_delete($photo_name);
    error_log('api/v1/students/create: ' . $e->getMessage());
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        capi_error(409, 'duplicate_student_id', 'Student ID "' . $student_id . '" already exists.');
    }
    capi_error(500, 'server_error', 'The student could not be saved. Please retry; if the problem persists contact the university IT office.');
}

// ── Audit ──────────────────────────────────────────────────────────────────────────────────
// Partner activity is recorded in api_client_requests (student_db_id below).
// capi_log_change() is a no-op: nothing from the API reaches the admin Change Log.

$GLOBALS['CAPI']['student_db_id'] = $new_id;
capi_log_change(
    $client,
    'CREATE',
    $new_id,
    $full_name . ' (' . $student_id . ')',
    null,
    null,
    null,
    'New student added via API client "' . $client['name'] . '"'
        . ($result_out !== null ? ' together with final result (' . $result_out['semester'] . ', CGPA ' . $result_out['cgpa'] . ')' : '')
        . ($GLOBALS['CAPI']['idempotency_key'] !== null ? ' [idempotency key ' . $GLOBALS['CAPI']['idempotency_key'] . ']' : '')
);

// ── Response ────────────────────────────────────────────────────────────────────────

capi_ok([
    'message'  => $result_out !== null ? 'Student created and result published.' : 'Student created.',
    'data'     => [
        'id'                => $new_id,
        'student_id'        => $student_id,
        'student_id_source' => $auto_id ? 'generated' : 'provided',
        'full_name'         => $full_name,
        'status'            => $status,
        'department'        => ['id' => $dept_id, 'code' => $dept['code'], 'name' => $dept['name']],
        'program'           => $program ? ['id' => $program_id, 'name' => $program['program_name']] : null,
        'admitted_semester' => $admitted_sem,
        'year'              => $row['year'] ?? null,
        'batch'             => $batch,
        'batch_id'          => $row['batch_id'] ?? null,
        'email'             => $email !== '' ? $email : null,
        'contact_no'        => $phone !== '' ? $phone : null,
        'photo_url'         => capi_photo_url($photo_name),
        'academic_qualifications_saved' => count($qual_rows),
        'result'            => $result_out,
        'created_at'        => date('c'),
    ],
    'warnings' => $warnings,
], 201);
