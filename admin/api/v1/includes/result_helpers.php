<?php
/**
 * Third-party API v1 – final result (student_results) helpers
 * =============================================================
 * Validation and upsert logic for publishing a final result (CGPA) into the
 * `student_results` table, mirroring admin/final-result-publish/index.php:
 *
 *   * same columns  (student_id → students.id, semester, semester_year, batch,
 *                    subject, cgpa, recorded_date)
 *   * same upsert key (student, subject, semester, semester_year)
 *   * same status rule: Active / Dropped (or mark_graduated) → Graduated
 *
 * Shared by:
 *   - results/create.php   (single / bulk publish for existing students)
 *   - students/create.php  (optional inline `result` saved with a new student)
 */

require_once __DIR__ . '/student_helpers.php';

const CAPI_RESULT_SUBJECT_DEFAULT = 'Final Result';   // = FRP_SUBJECT_LABEL in admin/final-result-publish
const CAPI_RESULT_BULK_MAX        = 200;
const CAPI_RESULT_IGNORED_CGPA    = ['incom', 'incomp', 'incomplete', 'inc', 'withheld', 'withhold', 'wh'];
const CAPI_RESULT_GRADUATE_FROM   = ['Active', 'Dropped'];  // auto-corrected to Graduated, as in the admin import

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
 * @param array      $item     Raw result object from the request.
 * @param array      $defaults Bulk-level defaults (subject, semester, batch, recorded_date, mark_graduated).
 * @param array|null $student  Pre-resolved student row (id, student_id, full_name, status, batch).
 *                             When given, the item's own student_id is ignored and no lookup
 *                             is performed – used when the student is being created in the
 *                             same request.
 *
 * @return array{errors:array<string,string>,warnings:string[],data:array<string,mixed>}
 */
function capi_result_validate(array $item, array $defaults = [], ?array $student = null): array
{
    $errors   = [];
    $warnings = [];

    // ── Student ────────────────────────────────────────────────────────────────
    if ($student !== null) {
        $sid = (string)$student['student_id'];
    } else {
        $sid_raw = capi_result_scalar($item, ['student_id', 'sid']);
        $sid     = $sid_raw !== null ? preg_replace('/\s+/u', '', $sid_raw) : '';

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
 * @param array $d               Validated data from capi_result_validate() (with 'student' set).
 * @param array $client          api_clients row.
 * @param bool  $own_transaction false when the caller already holds a transaction
 *                               (the caller is then responsible for commit / rollback).
 *
 * @return array{action:string,result_id:int,student_status:string}
 */
function capi_result_persist(array $d, array $client, bool $own_transaction = true): array
{
    $db = db();
    $s  = $d['student'];
    $pk = (int)$s['id'];

    if ($own_transaction) {
        $db->beginTransaction();
    }
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
                error_log('result_helpers change_log: ' . $e->getMessage());
            }
        }

        if ($own_transaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own_transaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['action' => $action, 'result_id' => $rid, 'student_status' => $status];
}
