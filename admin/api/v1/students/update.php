<?php
/**
 * Third-party API – POST /admin/api/v1/students/update.php
 * =========================================================
 * Partial update of an existing student created through the API.
 *
 *   * Identify the student with `student_id` (official ID) or `id` (internal id).
 *   * Send only the fields to change; every field of students/create.php is
 *     accepted (same names / aliases).  An explicit empty string or null
 *     clears an optional field.
 *   * `academic_qualifications` REPLACES the whole list when present.
 *   * Photo: `photo_base64` / multipart `photo` replaces it; `remove_photo: true`
 *     (or an empty `photo_base64`) removes it.
 *   * `new_student_id` changes the official Student ID (must be unique).
 *   * Results are NOT changed here – use results/create.php (upsert).
 *
 * A partner may only update students its own key created, unless the key has
 * the `students:update:any` scope.
 *
 * Auth : X-API-Key with scope `students:update`
 * Docs : ../API-GUIDE.md §6.9
 *
 * 200  { ok:true, message, data:{…, changed_fields:[…]}, warnings:[…] }
 * 4xx  { ok:false, code, message, errors?:{field:message} }
 */

require_once dirname(__DIR__, 2) . '/includes/auth_client_api.php';
require_once dirname(__DIR__) . '/includes/student_input.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'], true)) {
    header('Allow: POST, PUT, PATCH, OPTIONS');
    capi_error(405, 'method_not_allowed', 'Use POST (PUT and PATCH are accepted as aliases).');
}

$client = capi_auth('students:update');
capi_begin_request($client, 'v1/students/update');

$in       = capi_json_input();
$existing = capi_student_locate($in);
capi_student_assert_owned($existing, $client, 'students:update');

$pk = (int)$existing['id'];
$GLOBALS['CAPI']['student_db_id'] = $pk;

$v = capi_student_validate($in, $client, $existing);
if ($v['errors']) {
    if ($v['conflict'] && count($v['errors']) === 1) {
        capi_error(409, 'duplicate_student_id', 'Student ID "' . ($in['new_student_id'] ?? '') . '" already exists.', $v['errors']);
    }
    capi_error(422, 'validation_failed', 'One or more fields are invalid.', $v['errors']);
}

$warnings     = $v['warnings'];
$quals        = $v['quals'];
$photo        = $v['photo'];
$photo_remove = $v['photo_remove'];

// Keep only columns whose value actually changes.
$changes = [];
$norm    = static fn($x) => $x === null ? null : (string)$x;
foreach ($v['row'] as $col => $new) {
    $old = $existing[$col] ?? null;
    if ($norm($old) !== $norm($new)) {
        $changes[$col] = ['old' => $old, 'new' => $new];
    }
}

if (!$changes && $quals === null && $photo === null && !($photo_remove && !empty($existing['photo']))) {
    capi_ok([
        'message'  => 'Nothing to update.',
        'data'     => capi_student_public($existing, $v['dept'], $v['program']) + ['changed_fields' => [], 'academic_qualifications_saved' => null],
        'warnings' => $warnings,
    ]);
}

// ── Persist ───────────────────────────────────────────────────────────────────────────

$db          = db();
$old_photo   = $existing['photo'] ?? null;
$photo_name  = null;
$quals_saved = null;

try {
    if ($photo !== null) {
        $photo_name        = capi_photo_commit($photo);
        $changes['photo']  = ['old' => $old_photo, 'new' => $photo_name];
    } elseif ($photo_remove && $old_photo) {
        $changes['photo']  = ['old' => $old_photo, 'new' => null];
    }

    $db->beginTransaction();

    if ($changes) {
        $sets = [];
        $vals = [];
        foreach ($changes as $col => $c) {
            $sets[] = '`' . $col . '` = ?';
            $vals[] = $c['new'];
        }
        $vals[] = $pk;
        $db->prepare('UPDATE students SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    if ($quals !== null) {
        $db->prepare('DELETE FROM student_academic_qualifications WHERE student_id = ?')->execute([$pk]);
        $qins = $db->prepare(
            'INSERT INTO student_academic_qualifications
               (student_id, exam_title_id, exam_name, session, group_id, group_name,
                board_id, board_university, passing_year, division_class_grade, obtained_marks_gpa, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($quals as $qi => $q) {
            $qins->execute([
                $pk, $q['exam_title_id'], $q['exam_name'], $q['session'], $q['group_id'], $q['group_name'],
                $q['board_id'], $q['board_university'], $q['passing_year'], $q['division_class_grade'],
                $q['obtained_marks_gpa'], $qi,
            ]);
        }
        $quals_saved = count($quals);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    capi_photo_delete($photo_name);
    error_log('api/v1/students/update: ' . $e->getMessage());
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        capi_error(409, 'duplicate_student_id', 'Student ID "' . ($changes['student_id']['new'] ?? '') . '" already exists.');
    }
    capi_error(500, 'server_error', 'The student could not be updated. Please retry; if the problem persists contact the university IT office.');
}

// Old photo file is removed only after the database change is committed.
if (isset($changes['photo']) && $old_photo && $old_photo !== $photo_name) {
    capi_photo_delete($old_photo);
}

// ── Audit trail – one entry per changed field, like the admin edit form ─────────────

$fresh = $db->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
$fresh->execute([$pk]);
$s     = $fresh->fetch() ?: $existing;
$label = $s['full_name'] . ' (' . $s['student_id'] . ')';
$via   = 'Updated via API client "' . $client['name'] . '"';

foreach ($changes as $col => $c) {
    $old = $c['old'] === null ? null : (string)$c['old'];
    $new = $c['new'] === null ? null : (string)$c['new'];
    if ($col === 'photo') {
        $old = $old !== null ? '[photo]' : null;
        $new = $new !== null ? '[photo]' : null;
    }
    capi_log_change($client, 'UPDATE', $pk, $label, $col, $old, $new, $via);
}
if ($quals !== null) {
    capi_log_change($client, 'UPDATE', $pk, $label, 'academic_qualifications', null, (string)$quals_saved . ' row(s)',
        $via . ' – academic qualifications replaced');
}

// ── Response ────────────────────────────────────────────────────────────────────────

$dept    = capi_resolve_department((string)$s['dept_id']);
$program = !empty($s['program_id']) ? capi_resolve_program((string)$s['program_id'], (int)$s['dept_id']) : null;

$changed_fields = array_keys($changes);
if ($quals !== null) {
    $changed_fields[] = 'academic_qualifications';
}

capi_ok([
    'message'  => 'Student updated.',
    'data'     => capi_student_public($s, $dept, $program) + [
        'changed_fields'                => $changed_fields,
        'academic_qualifications_saved' => $quals_saved,
        'updated_at'                    => date('c'),
    ],
    'warnings' => $warnings,
]);
