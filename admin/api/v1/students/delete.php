<?php
/**
 * Third-party API – POST /admin/api/v1/students/delete.php
 * =========================================================
 * PERMANENTLY deletes a student and everything attached to them, exactly like
 * the "Delete" action in admin/students/delete.php:
 *
 *   * students row (cascades to academic qualifications, files, comments)
 *   * student_results (final results / CGPA published for the student)
 *   * photo and uploaded files on disk
 *
 * Safeguards (same as the admin panel, plus API-specific ones):
 *   * the caller must send `"confirm": true`
 *   * a student with recorded payments / vouchers cannot be deleted (409 has_payments)
 *   * a partner may only delete students its own key created, unless the key
 *     has the `students:delete:any` scope (403 not_owned)
 *
 * Identify the student with `student_id` (official ID) or `id` (internal id).
 * Optional `reason` (≤ 500 chars) is written to the Change Log.
 *
 * Auth : X-API-Key with scope `students:delete`
 * Docs : ../API-GUIDE.md §6.10
 *
 * 200  { ok:true, message, data:{ id, student_id, full_name, deleted:true, … } }
 * 4xx  { ok:false, code, message }
 */

require_once dirname(__DIR__, 2) . '/includes/auth_client_api.php';
require_once dirname(__DIR__) . '/includes/student_input.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) {
    header('Allow: POST, DELETE, OPTIONS');
    capi_error(405, 'method_not_allowed', 'Use POST (DELETE is accepted as an alias).');
}

$client = capi_auth('students:delete');
capi_begin_request($client, 'v1/students/delete');

$in = capi_json_input();
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && !$in) {
    $in = $_GET;   // DELETE /students/delete.php?student_id=…&confirm=true
}

$s  = capi_student_locate($in);
capi_student_assert_owned($s, $client, 'students:delete');

$pk = (int)$s['id'];
$GLOBALS['CAPI']['student_db_id'] = $pk;

if (capi_result_bool($in['confirm'] ?? null) !== true) {
    capi_error(422, 'confirmation_required',
        'Send "confirm": true to permanently delete this student and all of their data at the university.',
        ['confirm' => 'Must be true.']);
}

$reason = capi_result_scalar($in, ['reason', 'note']) ?? '';
if (mb_strlen($reason) > 500) {
    capi_error(422, 'validation_failed', 'Reason is too long.', ['reason' => 'Must be 500 characters or fewer.']);
}

$db = db();

// ── Guard: recorded payments must be preserved (mirrors admin/students/delete.php) ──

$payments = 0;
try {
    $cnt = $db->prepare(
        'SELECT COUNT(*)
           FROM sfp_payments sp
           JOIN acc_vouchers v ON v.id = sp.voucher_id
          WHERE sp.student_id = ? AND v.is_deleted = 0'
    );
    $cnt->execute([$pk]);
    $payments = (int)$cnt->fetchColumn();
} catch (Throwable $e) {
    error_log('api/v1/students/delete payment guard: ' . $e->getMessage());
}
if ($payments > 0) {
    capi_error(409, 'has_payments',
        'This student has ' . $payments . ' recorded payment(s) / voucher(s) and cannot be deleted. Ask the university accounts office to reverse them first.');
}

// Files to clean up after the delete (student_files cascades with the student row).
$stored_names = [];
try {
    $fs = $db->prepare('SELECT DISTINCT stored_name FROM student_files WHERE student_id = ?');
    $fs->execute([$pk]);
    $stored_names = array_map(static fn($r) => (string)$r['stored_name'], $fs->fetchAll());
} catch (Throwable $e) {
    error_log('api/v1/students/delete files: ' . $e->getMessage());
}

$label            = $s['full_name'] . ' (' . $s['student_id'] . ')';
$results_deleted  = 0;
$quals_deleted    = 0;

// ── Delete ────────────────────────────────────────────────────────────────────────────

try {
    $db->beginTransaction();

    $st = $db->prepare('DELETE FROM student_results WHERE student_id = ?');
    $st->execute([$pk]);
    $results_deleted = $st->rowCount();

    $st = $db->prepare('DELETE FROM student_academic_qualifications WHERE student_id = ?');
    $st->execute([$pk]);
    $quals_deleted = $st->rowCount();

    $db->prepare('DELETE FROM students WHERE id = ?')->execute([$pk]);

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('api/v1/students/delete: ' . $e->getMessage());
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        capi_error(409, 'has_dependencies',
            'Other university records (fees, accounts, registrations…) still reference this student, so it cannot be deleted through the API. Contact the university IT office.');
    }
    capi_error(500, 'server_error', 'The student could not be deleted. Please retry; if the problem persists contact the university IT office.');
}

// ── Disk clean-up (best effort, after commit) ──────────────────────────────────────────────

capi_photo_delete($s['photo'] ?? null);

$files_deleted = 0;
if ($stored_names) {
    try {
        $ref = $db->prepare('SELECT COUNT(*) FROM student_files WHERE stored_name = ?');
        foreach ($stored_names as $name) {
            $ref->execute([$name]);
            if ((int)$ref->fetchColumn() === 0) {
                $fp = UPLOAD_DIR . '/students/files/' . $name;
                if (is_file($fp) && @unlink($fp)) {
                    $files_deleted++;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('api/v1/students/delete file cleanup: ' . $e->getMessage());
    }
}

// ── Audit trail ────────────────────────────────────────────────────────────────────

capi_log_change(
    $client,
    'DELETE',
    $pk,
    $label,
    null,
    null,
    null,
    'Student deleted via API client "' . $client['name'] . '": ' . $label
        . ' (' . $results_deleted . ' result(s), ' . $quals_deleted . ' qualification(s), ' . $files_deleted . ' file(s) removed)'
        . ($reason !== '' ? ' – reason: ' . $reason : '')
);

// ── Response ────────────────────────────────────────────────────────────────────────

capi_ok([
    'message' => 'Student and all related data deleted.',
    'data'    => [
        'id'                     => $pk,
        'student_id'             => (string)$s['student_id'],
        'full_name'              => (string)$s['full_name'],
        'deleted'                => true,
        'results_deleted'        => $results_deleted,
        'qualifications_deleted' => $quals_deleted,
        'files_deleted'          => $files_deleted,
        'deleted_at'             => date('c'),
    ],
]);
