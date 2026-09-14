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
require_once dirname(__DIR__) . '/includes/result_helpers.php';   // also loads student_helpers.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    capi_error(405, 'method_not_allowed', 'Use POST.');
}

$client = capi_auth('students:create');
capi_begin_request($client, 'v1/students/create');

$in       = capi_json_input();
$errors   = [];   // field => message
$warnings = [];
$conflict = false;

// ── Input accessors ───────────────────────────────────────────────────────────

/**
 * First non-empty scalar among the alias keys, trimmed.  Records a length
 * error under $err_key when the value exceeds $max characters.
 */
$pick = static function (array $src, array $keys, int $max, string $err_key) use (&$errors): string {
    foreach ($keys as $k) {
        if (!isset($src[$k]) || !is_scalar($src[$k])) {
            continue;
        }
        $v = trim((string)$src[$k]);
        if ($v === '') {
            continue;
        }
        if (mb_strlen($v) > $max) {
            $errors[$err_key] = 'Must be ' . $max . ' characters or fewer.';
        }
        return $v;
    }
    return '';
};

/** Enumerated value: case-insensitive match against $allowed, '' when absent. */
$enum = static function (array $src, array $keys, array $allowed, string $err_key) use ($pick, &$errors): string {
    $v = $pick($src, $keys, 40, $err_key);
    if ($v === '') {
        return '';
    }
    $norm = capi_normalize_enum($v, $allowed);
    if ($norm === null) {
        $errors[$err_key] = 'Allowed values: ' . implode(', ', $allowed) . '.';
        return '';
    }
    return $norm;
};

// ── 1. Enrollment ─────────────────────────────────────────────────────────────

$dept_in = $in['department'] ?? $in['dept_id'] ?? $in['department_code'] ?? null;
$dept    = capi_resolve_department($dept_in);
if ($dept === null) {
    $errors['department'] = ($dept_in === null || $dept_in === '')
        ? 'Department is required (id, code or exact name).'
        : 'Unknown department. Call GET /v1/reference-data.php for valid values.';
}
$dept_id = $dept ? (int)$dept['id'] : 0;

$prog_in = $in['program'] ?? $in['program_id'] ?? null;
$program = null;
if ($prog_in !== null && $prog_in !== '') {
    $program = capi_resolve_program($prog_in, $dept_id);
    if ($program === null) {
        $errors['program'] = 'Unknown program. Call GET /v1/reference-data.php for valid values.';
    } elseif ($dept_id > 0 && (int)$program['dept_id'] !== $dept_id) {
        $errors['program'] = 'Program does not belong to the selected department.';
        $program = null;
    }
}
$program_id = $program ? (int)$program['id'] : 0;

$sem_raw      = $pick($in, ['semester', 'admitted_semester'], 30, 'semester');
$admitted_sem = $sem_raw !== '' ? capi_normalize_semester($sem_raw) : null;
if ($admitted_sem === null) {
    $errors['semester'] = $sem_raw === ''
        ? 'Semester is required, e.g. "Spring 2026".'
        : 'Semester must be "<Spring|Summer|Fall> <YYYY>", e.g. "Fall 2026".';
}

$year          = $pick($in, ['year'], 20, 'year');
$batch         = $pick($in, ['batch'], 50, 'batch');
$semester_type = $enum($in, ['semester_type'], CAPI_SEMESTER_TYPES, 'semester_type');
$shift         = $enum($in, ['shift'], CAPI_SHIFTS, 'shift');
$section       = $enum($in, ['section'], CAPI_SECTIONS, 'section');

$status = $enum($in, ['status'], CAPI_STATUSES, 'status');
if ($status === '') {
    $status = capi_normalize_enum((string)$client['default_status'], CAPI_STATUSES) ?? 'Not Admitted Yet';
}

// Student ID: optional. When omitted it is generated the same way as the admin form.
$student_id = $pick($in, ['student_id'], 20, 'student_id');
$auto_id    = $student_id === '';
if (!$auto_id) {
    if (!preg_match('/^[a-zA-Z0-9\-]{1,20}$/', $student_id)) {
        $errors['student_id'] = 'Must be 1-20 letters, digits or hyphens.';
    } elseif (capi_student_id_exists($student_id)) {
        $errors['student_id'] = 'Student ID "' . $student_id . '" is already in use.';
        $conflict = true;
    }
}

// ── 2. Personal information ───────────────────────────────────────────────────

$full_name = $pick($in, ['name', 'full_name', 'student_name'], 255, 'name');
if ($full_name === '') {
    $errors['name'] = 'Student name is required.';
} elseif (mb_strlen($full_name) < 2) {
    $errors['name'] = 'Student name is too short.';
}

$father_name       = $pick($in, ['father_name'], 255, 'father_name');
$father_phone      = $pick($in, ['father_phone', 'father_contact_no'], 30, 'father_phone');
$father_occupation = $pick($in, ['father_occupation', 'father_profession'], 150, 'father_occupation');
$mother_name       = $pick($in, ['mother_name'], 255, 'mother_name');
$mother_phone      = $pick($in, ['mother_phone', 'mother_contact_no'], 30, 'mother_phone');
$mother_occupation = $pick($in, ['mother_occupation', 'mother_profession'], 150, 'mother_occupation');

$present_address   = $pick($in, ['present_address'], 1000, 'present_address');
$phone             = $pick($in, ['contact_no', 'phone', 'mobile'], 30, 'contact_no');
$email             = $pick($in, ['email'], 255, 'email');
$permanent_address = $pick($in, ['permanent_address'], 1000, 'permanent_address');
$permanent_phone   = $pick($in, ['permanent_contact_no', 'permanent_phone'], 30, 'permanent_contact_no');
$permanent_email   = $pick($in, ['permanent_email'], 255, 'permanent_email');

foreach (['contact_no' => $phone, 'permanent_contact_no' => $permanent_phone,
          'father_phone' => $father_phone, 'mother_phone' => $mother_phone] as $k => $v) {
    if ($v !== '' && !capi_valid_phone($v)) {
        $errors[$k] = 'Must be a valid phone number (digits, optional leading +).';
    }
}
foreach (['email' => $email, 'permanent_email' => $permanent_email] as $k => $v) {
    if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
        $errors[$k] = 'Must be a valid email address.';
    }
}

$nationality    = $pick($in, ['nationality'], 100, 'nationality');
$country        = $pick($in, ['country'], 100, 'country') ?: 'Bangladesh';
$place_of_birth = $pick($in, ['place_of_birth'], 150, 'place_of_birth');
$religion       = $pick($in, ['religion'], 50, 'religion');
$blood_group    = $enum($in, ['blood_group'], CAPI_BLOOD_GROUPS, 'blood_group');
$nid            = $pick($in, ['nid', 'national_id'], 50, 'nid');

$dob = $pick($in, ['date_of_birth', 'dob'], 10, 'date_of_birth');
if ($dob !== '') {
    if (!capi_valid_date($dob)) {
        $errors['date_of_birth'] = 'Must be YYYY-MM-DD.';
    } elseif ($dob > date('Y-m-d') || $dob < '1900-01-01') {
        $errors['date_of_birth'] = 'Date of birth is out of range.';
    }
}

$sex_raw = $pick($in, ['sex', 'gender'], 10, 'sex');
$sex     = '';
if ($sex_raw !== '') {
    $sex = match (strtolower($sex_raw)) {
        'm' => 'Male',
        'f' => 'Female',
        default => capi_normalize_enum($sex_raw, CAPI_SEXES) ?? '',
    };
    if ($sex === '') {
        $errors['sex'] = 'Allowed values: Male, Female, Other.';
    }
}

// ── 3. Guardian (nested `guardian` object or flat guardian_* keys) ─────────────

$g = [];
if (isset($in['guardian'])) {
    if (is_array($in['guardian'])) {
        $g = $in['guardian'];
    } else {
        $errors['guardian'] = 'Must be an object with name, profession, address, phone, relationship, email, yearly_income.';
    }
}
$guardian_name         = $pick($g, ['name'], 255, 'guardian.name')                 ?: $pick($in, ['guardian_name'], 255, 'guardian_name');
$guardian_profession   = $pick($g, ['profession', 'occupation'], 150, 'guardian.profession') ?: $pick($in, ['guardian_profession'], 150, 'guardian_profession');
$guardian_address      = $pick($g, ['address'], 1000, 'guardian.address')          ?: $pick($in, ['guardian_address'], 1000, 'guardian_address');
$guardian_phone        = $pick($g, ['phone', 'contact_no'], 30, 'guardian.phone')  ?: $pick($in, ['guardian_phone'], 30, 'guardian_phone');
$guardian_relationship = $pick($g, ['relationship', 'relation'], 100, 'guardian.relationship') ?: $pick($in, ['guardian_relationship'], 100, 'guardian_relationship');
$guardian_email        = $pick($g, ['email'], 255, 'guardian.email')               ?: $pick($in, ['guardian_email'], 255, 'guardian_email');
$guardian_income_raw   = $pick($g, ['yearly_income', 'annual_income'], 30, 'guardian.yearly_income') ?: $pick($in, ['guardian_yearly_income'], 30, 'guardian_yearly_income');

if ($guardian_phone !== '' && !capi_valid_phone($guardian_phone)) {
    $errors['guardian.phone'] = 'Must be a valid phone number.';
}
if ($guardian_email !== '' && !filter_var($guardian_email, FILTER_VALIDATE_EMAIL)) {
    $errors['guardian.email'] = 'Must be a valid email address.';
}
$guardian_income = null;
if ($guardian_income_raw !== '') {
    $clean = str_replace([',', ' '], '', $guardian_income_raw);
    if (!is_numeric($clean) || (float)$clean < 0 || (float)$clean > 9999999999) {
        $errors['guardian.yearly_income'] = 'Must be a non-negative number.';
    } else {
        $guardian_income = round((float)$clean, 2);
    }
}

// ── 4. Academic qualifications ───────────────────────────────────────────────

$quals_in = $in['academic_qualifications'] ?? $in['qualifications'] ?? [];
if (!is_array($quals_in)) {
    $errors['academic_qualifications'] = 'Must be an array of qualification objects.';
    $quals_in = [];
}
if (count($quals_in) > CAPI_MAX_QUALIFICATIONS) {
    $errors['academic_qualifications'] = 'At most ' . CAPI_MAX_QUALIFICATIONS . ' qualifications are accepted.';
    $quals_in = array_slice(array_values($quals_in), 0, CAPI_MAX_QUALIFICATIONS);
}

$qual_rows = [];
foreach (array_values($quals_in) as $i => $q) {
    $p = 'academic_qualifications.' . $i . '.';
    if (!is_array($q)) {
        $errors[$p . '_'] = 'Each qualification must be an object.';
        continue;
    }

    $exam  = capi_resolve_lookup('exam',  $q['exam_title_id'] ?? null, $pick($q, ['name_of_examination', 'exam_name', 'examination', 'exam'], 100, $p . 'name_of_examination'));
    $group = capi_resolve_lookup('group', $q['group_id'] ?? null,      $pick($q, ['group', 'group_name'], 100, $p . 'group'));
    $board = capi_resolve_lookup('board', $q['board_id'] ?? null,      $pick($q, ['board_university', 'board', 'university'], 150, $p . 'board_university'));
    foreach (['exam_title_id' => $exam, 'group_id' => $group, 'board_id' => $board] as $k => $res) {
        if ($res['error'] !== null) {
            $errors[$p . $k] = $res['error'];
        }
    }

    $session      = $pick($q, ['session'], 30, $p . 'session');
    $passing_year = $pick($q, ['year_of_passing', 'passing_year'], 10, $p . 'year_of_passing');
    $division     = $pick($q, ['division_grade', 'division', 'grade', 'division_class_grade'], 50, $p . 'division_grade');
    $marks        = $pick($q, ['obtained_marks_cgpa', 'total_marks', 'cgpa', 'gpa', 'obtained_marks_gpa'], 50, $p . 'obtained_marks_cgpa');

    if ($passing_year !== '' && (!preg_match('/^\d{4}$/', $passing_year) || (int)$passing_year < 1950 || (int)$passing_year > (int)date('Y') + 1)) {
        $errors[$p . 'year_of_passing'] = 'Must be a 4-digit year.';
    }

    $row = [
        'exam_title_id'        => $exam['id'],
        'exam_name'            => $exam['name'],
        'session'              => $session ?: null,
        'group_id'             => $group['id'],
        'group_name'           => $group['name'],
        'board_id'             => $board['id'],
        'board_university'     => $board['name'],
        'passing_year'         => $passing_year ?: null,
        'division_class_grade' => $division ?: null,
        'obtained_marks_gpa'   => $marks ?: null,
    ];
    if (!array_filter($row, static fn($v) => $v !== null)) {
        continue; // completely empty row – ignore, same as the admin form
    }
    if ($exam['id'] === null && $exam['name'] === null) {
        $errors[$p . 'name_of_examination'] = 'Name of examination is required.';
    }
    $qual_rows[] = $row;
}

// ── 5. Photo (multipart file `photo` or JSON `photo_base64`) ────────────────────

$photo = null;
if (!empty($_FILES['photo']) && (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    [$photo, $perr] = capi_photo_from_upload($_FILES['photo']);
    if ($perr !== null) {
        $errors['photo'] = $perr;
    }
} else {
    $b64 = $in['photo_base64'] ?? (is_string($in['photo'] ?? null) ? $in['photo'] : null);
    if (is_string($b64) && trim($b64) !== '') {
        [$photo, $perr] = capi_photo_from_base64($b64);
        if ($perr !== null) {
            $errors['photo_base64'] = $perr;
        }
    }
}

// ── 6. Final result (optional, saved together with the student) ────────────────

$result_in = $in['result'] ?? $in['final_result'] ?? null;
$result_d  = null;
if ($result_in !== null) {
    if (!is_array($result_in)) {
        $errors['result'] = 'Must be an object: { semester, cgpa, batch?, recorded_date?, mark_graduated?, subject? } (see API-GUIDE §7.1).';
    } elseif (!capi_has_scope($client, 'results:create')) {
        capi_error(403, 'insufficient_scope', 'Including a "result" requires the "results:create" scope on your API key.');
    } else {
        // The student does not exist yet – validate against the values being created.
        $stub = [
            'id'         => 0,
            'student_id' => $auto_id ? '(generated)' : $student_id,
            'full_name'  => $full_name,
            'status'     => $status,
            'batch'      => $batch ?: null,
        ];
        $rv = capi_result_validate($result_in, [], $stub);
        foreach ($rv['errors'] as $k => $m) {
            $errors['result.' . $k] = $m;
        }
        foreach ($rv['warnings'] as $w) {
            $warnings[] = 'result: ' . $w;
        }
        $result_d = $rv['data'];
    }
}

// ── Validation result ─────────────────────────────────────────────────────────

if ($errors) {
    if ($conflict && count($errors) === 1) {
        capi_error(409, 'duplicate_student_id', 'Student ID "' . $student_id . '" already exists.', $errors);
    }
    capi_error(422, 'validation_failed', 'One or more fields are invalid.', $errors);
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
        if ($row = $dup->fetch()) {
            $warnings[] = 'A student with the same ' . $col . ' already exists: ' . $row['full_name'] . ' (' . $row['student_id'] . ').';
        }
    }
} catch (Throwable $e) {
    error_log('students/create duplicate check: ' . $e->getMessage());
}

// ── Persist ───────────────────────────────────────────────────────────────────

$db         = db();
$photo_name = null;
$new_id     = 0;
$result_out = null;

try {
    if ($photo !== null) {
        $photo_name = capi_photo_commit($photo);
    }

    $row = [
        'student_id'             => '',            // filled per attempt below
        'dept_id'                => $dept_id,
        'program_id'             => $program_id ?: null,
        'admitted_semester'      => $admitted_sem,
        'semester_type'          => $semester_type ?: null,
        'batch'                  => $batch ?: null,
        'year'                   => $year ?: null,
        'shift'                  => $shift ?: null,
        'section'                => $section ?: null,
        'full_name'              => $full_name,
        'father_name'            => $father_name ?: null,
        'father_phone'           => $father_phone ?: null,
        'father_occupation'      => $father_occupation ?: null,
        'mother_name'            => $mother_name ?: null,
        'mother_phone'           => $mother_phone ?: null,
        'mother_occupation'      => $mother_occupation ?: null,
        'present_address'        => $present_address ?: null,
        'permanent_address'      => $permanent_address ?: null,
        'permanent_phone'        => $permanent_phone ?: null,
        'permanent_email'        => $permanent_email ?: null,
        'nationality'            => $nationality ?: null,
        'country'                => $country,
        'faculty_label'          => ($dept['faculty_label'] ?? '') !== '' ? $dept['faculty_label'] : null,
        'email'                  => $email ?: null,
        'phone'                  => $phone ?: null,
        'dob'                    => $dob ?: null,
        'blood_group'            => $blood_group ?: null,
        'nid'                    => $nid ?: null,
        'place_of_birth'         => $place_of_birth ?: null,
        'sex'                    => $sex ?: null,
        'religion'               => $religion ?: null,
        'photo'                  => $photo_name,
        'guardian_name'          => $guardian_name ?: null,
        'guardian_profession'    => $guardian_profession ?: null,
        'guardian_address'       => $guardian_address ?: null,
        'guardian_phone'         => $guardian_phone ?: null,
        'guardian_relationship'  => $guardian_relationship ?: null,
        'guardian_email'         => $guardian_email ?: null,
        'guardian_yearly_income' => $guardian_income,
        'status'                 => $status,
        'created_by'             => $client['created_by'] !== null ? (int)$client['created_by'] : null,
        'api_client_id'          => (int)$client['id'],
    ];
    $cols = array_keys($row);
    $sql  = 'INSERT INTO students (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $ins  = $db->prepare($sql);

    // Auto-generated IDs can collide under concurrency; regenerate and retry.
    $attempts = $auto_id ? 3 : 1;
    for ($try = 1; $try <= $attempts; $try++) {
        if ($auto_id) {
            $student_id = capi_generate_student_id($admitted_sem, $dept_id, $program_id);
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
                    'batch'      => $batch ?: null,
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

// ── Audit trail (best effort, same table the admin panel uses) ─────────────────

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

// ── Response ──────────────────────────────────────────────────────────────────

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
        'year'              => $year ?: null,
        'email'             => $email ?: null,
        'contact_no'        => $phone ?: null,
        'photo_url'         => capi_photo_url($photo_name),
        'academic_qualifications_saved' => count($qual_rows),
        'result'            => $result_out,
        'created_at'        => date('c'),
    ],
    'warnings' => $warnings,
], 201);
