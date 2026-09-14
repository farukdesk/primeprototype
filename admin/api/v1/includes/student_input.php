<?php
/**
 * Third-party API v1 – student payload validation
 * ===============================================
 * Shared by students/create.php (full validation) and students/update.php
 * (partial: only the keys present in the request are validated and written).
 * Field names, aliases and rules are documented in ../API-GUIDE.md §6.1.
 *
 * capi_student_validate() returns the `students` columns to write plus the
 * academic-qualification rows, the prepared photo and (create only) the
 * validated inline result.  Nothing is written to the database here.
 */

require_once __DIR__ . '/result_helpers.php';   // also loads student_helpers.php

/**
 * Locate the student a request refers to, by `id` (students.id) or by
 * `student_id` (official ID, alias `sid`; leading-zero variants tolerated).
 * Terminates with 404 student_not_found, or 422 when neither was sent.
 */
function capi_student_locate(array $in): array
{
    if (isset($in['id']) && is_scalar($in['id']) && ctype_digit((string)$in['id']) && (int)$in['id'] > 0) {
        $st = db()->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
        $st->execute([(int)$in['id']]);
        $row = $st->fetch();
        if (!$row) {
            capi_error(404, 'student_not_found', 'No student with internal id ' . (int)$in['id'] . '.', ['id' => 'No student with this id.']);
        }
        return $row;
    }

    $sid = capi_result_scalar($in, ['student_id', 'sid']);
    if ($sid === null) {
        capi_error(422, 'validation_failed', 'Identify the student with "student_id" (official Student ID) or "id" (internal id).',
            ['student_id' => 'Student ID is required.']);
    }
    $sid = preg_replace('/\s+/u', '', $sid);
    if (!preg_match('/^[a-zA-Z0-9\-]{1,25}$/', $sid)) {
        capi_error(422, 'validation_failed', 'Invalid student_id.', ['student_id' => 'Must be 1-25 letters, digits or hyphens.']);
    }
    $found = capi_result_find_student($sid);
    if ($found === null) {
        capi_error(404, 'student_not_found', 'No student with ID "' . $sid . '".', ['student_id' => 'No student with ID "' . $sid . '".']);
    }
    $st = db()->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
    $st->execute([(int)$found['id']]);
    return $st->fetch();
}

/**
 * A partner may only modify / delete students it created itself, unless its
 * key carries the matching ":any" scope (students:update:any, students:delete:any).
 */
function capi_student_assert_owned(array $student, array $client, string $scope): void
{
    if ((int)($student['api_client_id'] ?? 0) === (int)$client['id'] || capi_has_scope($client, $scope . ':any')) {
        return;
    }
    capi_error(403, 'not_owned',
        'This student was not created by your API client. The "' . $scope . ':any" scope is required to change students created elsewhere.');
}

/** Public representation of a students row used in responses. */
function capi_student_public(array $s, ?array $dept, ?array $program): array
{
    return [
        'id'                => (int)$s['id'],
        'student_id'        => (string)$s['student_id'],
        'full_name'         => (string)$s['full_name'],
        'status'            => (string)$s['status'],
        'department'        => $dept
            ? ['id' => (int)$dept['id'], 'code' => $dept['code'], 'name' => $dept['name']]
            : ['id' => (int)($s['dept_id'] ?? 0), 'code' => null, 'name' => null],
        'program'           => $program ? ['id' => (int)$program['id'], 'name' => $program['program_name']] : null,
        'admitted_semester' => $s['admitted_semester'] ?? null,
        'year'              => $s['year'] ?? null,
        'batch'             => $s['batch'] ?? null,
        'email'             => $s['email'] ?? null,
        'contact_no'        => $s['phone'] ?? null,
        'photo_url'         => capi_photo_url($s['photo'] ?? null),
    ];
}

/**
 * Validate a create / update payload.
 *
 * @param array      $in        Decoded request body.
 * @param array      $client    api_clients row.
 * @param array|null $existing  Current students row when updating (partial mode); null when creating.
 *
 * @return array{
 *   errors: array<string,string>, warnings: string[], conflict: bool,
 *   row: array<string,mixed>,            students columns to write (create: all; update: only those sent)
 *   quals: ?array,                       qualification rows, or null when not sent (update keeps existing)
 *   photo: ?array, photo_remove: bool,
 *   student_id: string, auto_id: bool,
 *   dept: ?array, program: ?array,
 *   result_d: ?array                     validated inline result (create only)
 * }
 */
function capi_student_validate(array $in, array $client, ?array $existing = null): array
{
    $partial  = $existing !== null;
    $errors   = [];
    $warnings = [];
    $conflict = false;
    $row      = [];

    /** True when any alias key is present in the payload (even if empty / null). */
    $has = static function (array $src, array $keys): bool {
        foreach ($keys as $k) {
            if (array_key_exists($k, $src)) {
                return true;
            }
        }
        return false;
    };

    /** First non-empty scalar among the alias keys, trimmed; records a length error. */
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

    // ── 1. Enrollment ─────────────────────────────────────────────────────────────────

    $dept_id      = $partial ? (int)$existing['dept_id'] : 0;
    $dept         = null;
    $dept_changed = false;
    if (!$partial || $has($in, ['department', 'dept_id', 'department_code'])) {
        $dept_in = $in['department'] ?? $in['dept_id'] ?? $in['department_code'] ?? null;
        $dept    = capi_resolve_department($dept_in);
        if ($dept === null) {
            $errors['department'] = ($dept_in === null || $dept_in === '')
                ? 'Department is required (id, code or exact name).'
                : 'Unknown department. Call GET /v1/reference-data.php for valid values.';
        } else {
            $dept_changed         = (int)$dept['id'] !== $dept_id;
            $dept_id              = (int)$dept['id'];
            $row['dept_id']       = $dept_id;
            $row['faculty_label'] = ($dept['faculty_label'] ?? '') !== '' ? $dept['faculty_label'] : null;
        }
    } else {
        $dept = capi_resolve_department((string)$dept_id);
    }

    $program_id = $partial ? (int)($existing['program_id'] ?? 0) : 0;
    $program    = null;
    if (!$partial || $has($in, ['program', 'program_id'])) {
        $prog_in = $in['program'] ?? $in['program_id'] ?? null;
        if ($prog_in === null || $prog_in === '') {
            $program_id        = 0;
            $row['program_id'] = null;
        } else {
            $program = capi_resolve_program($prog_in, $dept_id);
            if ($program === null) {
                $errors['program'] = 'Unknown program. Call GET /v1/reference-data.php for valid values.';
            } elseif ($dept_id > 0 && (int)$program['dept_id'] !== $dept_id) {
                $errors['program'] = 'Program does not belong to the selected department.';
                $program           = null;
            } else {
                $program_id        = (int)$program['id'];
                $row['program_id'] = $program_id;
            }
        }
    } elseif ($program_id > 0) {
        $program = capi_resolve_program((string)$program_id, $dept_id);
        if ($dept_changed && ($program === null || (int)$program['dept_id'] !== $dept_id)) {
            $errors['program'] = 'The current program does not belong to the new department. Send "program" as well (or null to clear it).';
        }
    }

    if (!$partial || $has($in, ['semester', 'admitted_semester'])) {
        $sem_raw = $pick($in, ['semester', 'admitted_semester'], 30, 'semester');
        $norm    = $sem_raw !== '' ? capi_normalize_semester($sem_raw) : null;
        if ($norm === null) {
            $errors['semester'] = $sem_raw === ''
                ? 'Semester is required, e.g. "Spring 2026".'
                : 'Semester must be "<Spring|Summer|Fall> <YYYY>", e.g. "Fall 2026".';
        } else {
            $row['admitted_semester'] = $norm;
        }
    }

    if (!$partial) {
        $status = $enum($in, ['status'], CAPI_STATUSES, 'status');
        if ($status === '') {
            $status = capi_normalize_enum((string)$client['default_status'], CAPI_STATUSES) ?? 'Not Admitted Yet';
        }
        $row['status'] = $status;
    } elseif ($has($in, ['status'])) {
        $status = $enum($in, ['status'], CAPI_STATUSES, 'status');
        if ($status === '') {
            if (!isset($errors['status'])) {
                $errors['status'] = 'Status cannot be empty. Allowed values: ' . implode(', ', CAPI_STATUSES) . '.';
            }
        } else {
            $row['status'] = $status;
        }
    }

    // Student ID: create → optional `student_id` (generated when omitted);
    // update → `student_id` identifies the record, `new_student_id` changes it.
    $student_id = $partial ? (string)$existing['student_id'] : '';
    $auto_id    = false;
    $id_keys    = $partial ? ['new_student_id'] : ['student_id'];
    if (!$partial || $has($in, $id_keys)) {
        $sid = $pick($in, $id_keys, 20, $id_keys[0]);
        if ($sid === '') {
            if ($partial) {
                $errors['new_student_id'] = 'new_student_id cannot be empty.';
            } else {
                $auto_id = true;
            }
        } elseif (!preg_match('/^[a-zA-Z0-9\-]{1,20}$/', $sid)) {
            $errors[$id_keys[0]] = 'Must be 1-20 letters, digits or hyphens.';
        } elseif ($sid !== $student_id && capi_student_id_exists($sid)) {
            $errors[$id_keys[0]] = 'Student ID "' . $sid . '" is already in use.';
            $conflict            = true;
        } else {
            $student_id = $sid;
            if ($partial) {
                $row['student_id'] = $sid;
            }
        }
    }

    // ── 2. Personal information ───────────────────────────────────────────────────────────────

    if (!$partial || $has($in, ['name', 'full_name', 'student_name'])) {
        $full_name = $pick($in, ['name', 'full_name', 'student_name'], 255, 'name');
        if ($full_name === '') {
            $errors['name'] = 'Student name is required.';
        } elseif (mb_strlen($full_name) < 2) {
            $errors['name'] = 'Student name is too short.';
        }
        $row['full_name'] = $full_name;
    }

    // column => [aliases, max length, kind, allowed values]; the first alias is the error key.
    $simple = [
        'year'              => [['year'], 20],
        'batch'             => [['batch'], 50],
        'semester_type'     => [['semester_type'], 40, 'enum', CAPI_SEMESTER_TYPES],
        'shift'             => [['shift'], 40, 'enum', CAPI_SHIFTS],
        'section'           => [['section'], 40, 'enum', CAPI_SECTIONS],
        'father_name'       => [['father_name'], 255],
        'father_phone'      => [['father_phone', 'father_contact_no'], 30, 'phone'],
        'father_occupation' => [['father_occupation', 'father_profession'], 150],
        'mother_name'       => [['mother_name'], 255],
        'mother_phone'      => [['mother_phone', 'mother_contact_no'], 30, 'phone'],
        'mother_occupation' => [['mother_occupation', 'mother_profession'], 150],
        'present_address'   => [['present_address'], 1000],
        'phone'             => [['contact_no', 'phone', 'mobile'], 30, 'phone'],
        'email'             => [['email'], 255, 'email'],
        'permanent_address' => [['permanent_address'], 1000],
        'permanent_phone'   => [['permanent_contact_no', 'permanent_phone'], 30, 'phone'],
        'permanent_email'   => [['permanent_email'], 255, 'email'],
        'nationality'       => [['nationality'], 100],
        'place_of_birth'    => [['place_of_birth'], 150],
        'religion'          => [['religion'], 50],
        'blood_group'       => [['blood_group'], 40, 'enum', CAPI_BLOOD_GROUPS],
        'nid'               => [['nid', 'national_id'], 50],
    ];
    foreach ($simple as $col => $def) {
        $aliases = $def[0];
        $max     = $def[1];
        $kind    = $def[2] ?? 'text';
        $key     = $aliases[0];
        if ($partial && !$has($in, $aliases)) {
            continue;
        }
        $v = $kind === 'enum' ? $enum($in, $aliases, $def[3], $key) : $pick($in, $aliases, $max, $key);
        if ($v !== '' && $kind === 'phone' && !capi_valid_phone($v)) {
            $errors[$key] = 'Must be a valid phone number (digits, optional leading +).';
        }
        if ($v !== '' && $kind === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $errors[$key] = 'Must be a valid email address.';
        }
        $row[$col] = $v !== '' ? $v : null;
    }

    // ── Batch: link to student_batches like the admin form; auto-assign on create ──
    // A batch sent by the caller is matched against the batches defined at the
    // university so students.batch_id is set (free text is kept as text only).
    // When nothing is sent, a NEW student inherits the batch of the students
    // already admitted with them – see capi_infer_batch().
    if (!$partial || $has($in, ['batch'])) {
        $batch_row = capi_resolve_batch($row['batch'] ?? null);
        if ($batch_row !== null) {
            $row['batch_id'] = (int)$batch_row['id'];
            $row['batch']    = (string)$batch_row['name'];
        } else {
            $row['batch_id'] = null;
            if (($row['batch'] ?? null) !== null) {
                $warnings[] = 'batch "' . $row['batch'] . '" does not match any batch defined at the university; it was stored as text only.';
            }
        }
    }
    if (!$partial && ($row['batch_id'] ?? null) === null && ($row['batch'] ?? null) === null
        && $dept_id > 0 && isset($row['admitted_semester'])) {
        $inferred = capi_infer_batch($row['admitted_semester'], $dept_id, $program_id);
        if ($inferred !== null) {
            $row['batch_id'] = $inferred['batch_id'];
            $row['batch']    = $inferred['batch'];
            $warnings[]      = 'Batch "' . $inferred['batch'] . '" was assigned automatically (' . $inferred['scope'] . ').';
        } else {
            $warnings[]      = 'No batch could be assigned automatically: no student admitted in ' . $row['admitted_semester']
                . ' has a batch yet. The university admin can set it in the admin panel.';
        }
    }

    if (!$partial || $has($in, ['country'])) {
        $row['country'] = $pick($in, ['country'], 100, 'country') ?: 'Bangladesh';
    }

    if (!$partial || $has($in, ['date_of_birth', 'dob'])) {
        $dob = $pick($in, ['date_of_birth', 'dob'], 10, 'date_of_birth');
        if ($dob !== '') {
            if (!capi_valid_date($dob)) {
                $errors['date_of_birth'] = 'Must be YYYY-MM-DD.';
            } elseif ($dob > date('Y-m-d') || $dob < '1900-01-01') {
                $errors['date_of_birth'] = 'Date of birth is out of range.';
            }
        }
        $row['dob'] = $dob ?: null;
    }

    if (!$partial || $has($in, ['sex', 'gender'])) {
        $sex_raw = $pick($in, ['sex', 'gender'], 10, 'sex');
        $sex     = '';
        if ($sex_raw !== '') {
            $sex = match (strtolower($sex_raw)) {
                'm'     => 'Male',
                'f'     => 'Female',
                default => capi_normalize_enum($sex_raw, CAPI_SEXES) ?? '',
            };
            if ($sex === '') {
                $errors['sex'] = 'Allowed values: Male, Female, Other.';
            }
        }
        $row['sex'] = $sex ?: null;
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
    $gdefs = [
        'guardian_name'         => [['name'], ['guardian_name'], 255],
        'guardian_profession'   => [['profession', 'occupation'], ['guardian_profession'], 150],
        'guardian_address'      => [['address'], ['guardian_address'], 1000],
        'guardian_phone'        => [['phone', 'contact_no'], ['guardian_phone'], 30, 'phone'],
        'guardian_relationship' => [['relationship', 'relation'], ['guardian_relationship'], 100],
        'guardian_email'        => [['email'], ['guardian_email'], 255, 'email'],
    ];
    foreach ($gdefs as $col => $def) {
        [$gkeys, $flat, $max] = $def;
        $kind = $def[3] ?? 'text';
        $ekey = 'guardian.' . $gkeys[0];
        if ($partial && !$has($g, $gkeys) && !$has($in, $flat)) {
            continue;
        }
        $v = $pick($g, $gkeys, $max, $ekey) ?: $pick($in, $flat, $max, $flat[0]);
        if ($v !== '' && $kind === 'phone' && !capi_valid_phone($v)) {
            $errors[$ekey] = 'Must be a valid phone number.';
        }
        if ($v !== '' && $kind === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $errors[$ekey] = 'Must be a valid email address.';
        }
        $row[$col] = $v !== '' ? $v : null;
    }
    if (!$partial || $has($g, ['yearly_income', 'annual_income']) || $has($in, ['guardian_yearly_income'])) {
        $raw = $pick($g, ['yearly_income', 'annual_income'], 30, 'guardian.yearly_income')
            ?: $pick($in, ['guardian_yearly_income'], 30, 'guardian_yearly_income');
        $income = null;
        if ($raw !== '') {
            $clean = str_replace([',', ' '], '', $raw);
            if (!is_numeric($clean) || (float)$clean < 0 || (float)$clean > 9999999999) {
                $errors['guardian.yearly_income'] = 'Must be a non-negative number.';
            } else {
                $income = round((float)$clean, 2);
            }
        }
        $row['guardian_yearly_income'] = $income;
    }

    // ── 4. Academic qualifications (update: replaced only when the key is sent) ──────

    $quals = null;
    if (!$partial || $has($in, ['academic_qualifications', 'qualifications'])) {
        $quals_in = $in['academic_qualifications'] ?? $in['qualifications'] ?? [];
        if ($quals_in === null) {
            $quals_in = [];
        }
        if (!is_array($quals_in)) {
            $errors['academic_qualifications'] = 'Must be an array of qualification objects.';
            $quals_in = [];
        }
        if (count($quals_in) > CAPI_MAX_QUALIFICATIONS) {
            $errors['academic_qualifications'] = 'At most ' . CAPI_MAX_QUALIFICATIONS . ' qualifications are accepted.';
            $quals_in = array_slice(array_values($quals_in), 0, CAPI_MAX_QUALIFICATIONS);
        }

        $quals = [];
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

            $qrow = [
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
            if (!array_filter($qrow, static fn($v) => $v !== null)) {
                continue; // completely empty row – ignore, same as the admin form
            }
            if ($exam['id'] === null && $exam['name'] === null) {
                $errors[$p . 'name_of_examination'] = 'Name of examination is required.';
            }
            $quals[] = $qrow;
        }
    }

    // ── 5. Photo (multipart file `photo`, JSON `photo_base64`; update: `remove_photo`) ──

    $photo        = null;
    $photo_remove = false;
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
    if ($partial && $photo === null) {
        if (capi_result_bool($in['remove_photo'] ?? null) === true) {
            $photo_remove = true;
        } elseif (array_key_exists('photo_base64', $in) && ($in['photo_base64'] === null || (is_scalar($in['photo_base64']) && trim((string)$in['photo_base64']) === ''))) {
            $photo_remove = true;   // explicit empty photo_base64 clears the photo
        }
    }

    // ── 6. Final result (create only – updates go through results/create.php) ──────

    $result_d  = null;
    $result_in = $in['result'] ?? $in['final_result'] ?? null;
    if ($result_in !== null) {
        if ($partial) {
            $errors['result'] = 'Results cannot be changed through students/update.php. Use POST /v1/results/create.php (it is an upsert).';
        } elseif (!is_array($result_in)) {
            $errors['result'] = 'Must be an object: { semester, cgpa, batch?, recorded_date?, mark_graduated?, subject? } (see API-GUIDE §7.1).';
        } elseif (!capi_has_scope($client, 'results:create')) {
            capi_error(403, 'insufficient_scope', 'Including a "result" requires the "results:create" scope on your API key.');
        } else {
            // The student does not exist yet – validate against the values being created.
            $stub = [
                'id'         => 0,
                'student_id' => $auto_id ? '(generated)' : $student_id,
                'full_name'  => $row['full_name'] ?? '',
                'status'     => $row['status'] ?? '',
                'batch'      => $row['batch'] ?? null,
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

    return [
        'errors'       => $errors,
        'warnings'     => $warnings,
        'conflict'     => $conflict,
        'row'          => $row,
        'quals'        => $quals,
        'photo'        => $photo,
        'photo_remove' => $photo_remove,
        'student_id'   => $student_id,
        'auto_id'      => $auto_id,
        'dept'         => $dept,
        'program'      => $program,
        'result_d'     => $result_d,
    ];
}
