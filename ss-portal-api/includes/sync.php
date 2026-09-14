<?php
/**
 * SS Portal – push local student records to Prime University.
 *
 * ssp_sync_student()    POST /students/create.php with an X-Idempotency-Key
 *                       derived from the local reference number, so retrying
 *                       after a network failure never creates a second student.
 *                       Registered students with pending edits are routed to
 *                       ssp_update_student().
 * ssp_update_student()  POST /students/update.php – pushes the local record.
 * ssp_delete_student()  POST /students/delete.php – permanently deletes the
 *                       student at the university; the local record is KEPT
 *                       and marked "deleted".
 * ssp_publish_result()  POST /results/create.php for an already registered student.
 *
 * Every call is written to ssp_api_log for auditing.
 */

require_once __DIR__ . '/pu_api_client.php';
require_once __DIR__ . '/student_payload.php';

function ssp_student_find(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $st = ssp_db()->prepare('SELECT * FROM ssp_students WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Local reference, e.g. SSP-20260913-3F9A1C (unique). */
function ssp_generate_reference_no(PDO $db): string
{
    for ($i = 0; $i < 10; $i++) {
        $ref = 'SSP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $st  = $db->prepare('SELECT 1 FROM ssp_students WHERE reference_no = ? LIMIT 1');
        $st->execute([$ref]);
        if (!$st->fetchColumn()) {
            return $ref;
        }
    }
    throw new RuntimeException('Could not generate a unique reference number.');
}

function ssp_student_idempotency_key(array $student): string
{
    return (string)ssp_config('idempotency_prefix', 'ssp') . '-student-' . $student['reference_no'];
}

function ssp_api_log(?int $studentId, ?int $userId, string $endpoint, ?string $idem, array $request, array $resp): void
{
    try {
        ssp_db()->prepare('INSERT INTO ssp_api_log
                (student_id, user_id, endpoint, idempotency_key, http_status, response_code, ok, duration_ms, request_json, response_json)
             VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $studentId, $userId, $endpoint, $idem,
                (int)$resp['status'] ?: null, mb_substr((string)$resp['code'], 0, 60), $resp['ok'] ? 1 : 0, (int)$resp['duration_ms'],
                json_encode($request, JSON_UNESCAPED_UNICODE),
                json_encode(['status' => $resp['status'], 'headers' => $resp['headers'], 'body' => $resp['body']], JSON_UNESCAPED_UNICODE),
            ]);
    } catch (Throwable $e) {
        error_log('ss-portal api log: ' . $e->getMessage());
    }
}

/** One-line summary of a failed API response for ssp_students.last_error. */
function ssp_error_summary(array $resp): string
{
    $summary = '[' . ($resp['status'] ?: 'network') . ' ' . $resp['code'] . '] ' . ($resp['message'] !== '' ? $resp['message'] : 'Request failed.');
    $errors  = $resp['body']['errors'] ?? null;
    if (is_array($errors) && $errors) {
        $summary .= ' ' . json_encode($errors, JSON_UNESCAPED_UNICODE);
    }
    return mb_substr($summary, 0, 4000);
}

/**
 * Send (or re-send) a local student to the university.
 *
 * @return array{ok: bool, message: string, student_id?: ?string, warnings?: array, errors?: array, retryable?: bool, response?: array, already?: bool}
 */
function ssp_sync_student(int $id, int $userId): array
{
    $db = ssp_db();
    $s  = ssp_student_find($id);
    if ($s === null) {
        return ['ok' => false, 'message' => 'Student not found.'];
    }
    if ($s['sync_status'] === 'deleted') {
        return ['ok' => false, 'message' => 'This student has been deleted and cannot be sent.'];
    }
    if ($s['sync_status'] === 'synced') {
        if ((int)$s['pending_update'] === 1) {
            return ssp_update_student($id, $userId);
        }
        return ['ok' => true, 'already' => true, 'student_id' => $s['pu_student_id'],
            'message' => 'Already registered at the university as ' . $s['pu_student_id'] . '.'];
    }
    if (!ssp_api()->isConfigured()) {
        return ['ok' => false, 'retryable' => true, 'message' => 'The Prime University API key is not configured (config.php → pu_api.api_key).'];
    }

    $payload = json_decode((string)$s['payload_json'], true);
    if (!is_array($payload)) {
        return ['ok' => false, 'message' => 'Stored payload is corrupt; edit and save the student again.'];
    }

    $photoBytes = 0;
    if (!empty($s['photo_path'])) {
        $file = ssp_photo_file((string)$s['photo_path']);
        $mime = ssp_photo_mime($file);
        if ($mime !== null) {
            $bin        = (string)file_get_contents($file);
            $photoBytes = strlen($bin);
            $payload['photo_base64'] = 'data:' . $mime . ';base64,' . base64_encode($bin);
            unset($bin);
        }
    }

    $idem = ssp_student_idempotency_key($s);
    $db->prepare('UPDATE ssp_students SET sync_status = "pending" WHERE id = ?')->execute([$id]);

    $resp = ssp_api()->createStudent($payload, $idem);

    $logged = $payload;
    if (isset($logged['photo_base64'])) {
        $logged['photo_base64'] = '[photo, ' . $photoBytes . ' bytes]';
    }
    ssp_api_log($id, $userId, 'v1/students/create', $idem, $logged, $resp);

    if ($resp['ok']) {
        $d = is_array($resp['body']['data'] ?? null) ? $resp['body']['data'] : [];
        $db->prepare('UPDATE ssp_students
                SET sync_status = "synced", sync_attempts = sync_attempts + 1, pu_id = ?, pu_student_id = ?, pu_status = ?,
                    pu_photo_url = ?, pu_result_id = ?, result_json = ?, last_error = NULL, last_response_json = ?, synced_at = NOW()
              WHERE id = ?')
           ->execute([
               isset($d['id']) ? (int)$d['id'] : null,
               isset($d['student_id']) ? (string)$d['student_id'] : null,
               isset($d['status']) ? (string)$d['status'] : null,
               isset($d['photo_url']) ? (string)$d['photo_url'] : null,
               isset($d['result']['result_id']) ? (int)$d['result']['result_id'] : null,
               isset($d['result']) && is_array($d['result']) ? json_encode($d['result'], JSON_UNESCAPED_UNICODE) : null,
               json_encode($resp['body'], JSON_UNESCAPED_UNICODE),
               $id,
           ]);
        $message = $resp['message'] !== '' ? $resp['message'] : 'Student created.';
        if (!empty($resp['headers']['x-idempotent-replayed'])) {
            $message .= ' (The university replayed an earlier identical request; no duplicate was created.)';
        }
        return ['ok' => true, 'message' => $message, 'student_id' => $d['student_id'] ?? null,
            'warnings' => is_array($resp['body']['warnings'] ?? null) ? $resp['body']['warnings'] : [], 'response' => $resp];
    }

    $errors = is_array($resp['body']['errors'] ?? null) ? $resp['body']['errors'] : [];

    if ($resp['code'] === 'student_id_pattern_not_found') {
        // The university has no Student ID numbering yet for this semester / department /
        // program and never invents one. Nothing was created there: keep the record as a
        // DRAFT until the university admin supplies the Student ID.
        $db->prepare('UPDATE ssp_students SET sync_status = "draft", sync_attempts = sync_attempts + 1, last_error = ?, last_response_json = ? WHERE id = ?')
           ->execute([ssp_error_summary($resp), json_encode($resp['body'], JSON_UNESCAPED_UNICODE), $id]);
        return ['ok' => false, 'needs_student_id' => true, 'errors' => $errors, 'retryable' => false, 'response' => $resp,
            'message' => 'Saved as draft – the student was NOT created at Prime University. No Student ID numbering exists yet for this semester / department / program. '
                . 'Please contact the university admin for the Student ID, then edit the student, enter it in "University Student ID" and send again.'];
    }

    $db->prepare('UPDATE ssp_students SET sync_status = "failed", sync_attempts = sync_attempts + 1, last_error = ?, last_response_json = ? WHERE id = ?')
       ->execute([ssp_error_summary($resp), json_encode($resp['body'], JSON_UNESCAPED_UNICODE), $id]);

    return ['ok' => false, 'message' => ssp_error_summary($resp), 'errors' => $errors,
        'retryable' => PuApiClient::isRetryable($resp), 'response' => $resp];
}

/**
 * Push the local record of a registered student to the university
 * (POST /students/update.php).  The whole local payload is sent, so the
 * university copy always mirrors the portal; the result block is never sent
 * (results have their own endpoint).  The local photo is re-sent when present,
 * otherwise the university photo is removed.
 *
 * @return array{ok: bool, message: string, student_id?: ?string, warnings?: array, errors?: array, retryable?: bool, response?: array, changed_fields?: array}
 */
function ssp_update_student(int $id, int $userId): array
{
    $db = ssp_db();
    $s  = ssp_student_find($id);
    if ($s === null) {
        return ['ok' => false, 'message' => 'Student not found.'];
    }
    if ($s['sync_status'] !== 'synced' || empty($s['pu_student_id'])) {
        return ['ok' => false, 'message' => 'The student is not registered at the university yet; use "Send to university" instead.'];
    }
    if (!ssp_api()->isConfigured()) {
        return ['ok' => false, 'retryable' => true, 'message' => 'The Prime University API key is not configured (config.php → pu_api.api_key).'];
    }

    $payload = json_decode((string)$s['payload_json'], true);
    if (!is_array($payload)) {
        return ['ok' => false, 'message' => 'Stored payload is corrupt; edit and save the student again.'];
    }
    unset($payload['result'], $payload['final_result'], $payload['student_id'], $payload['new_student_id'], $payload['id']);

    $photoBytes = 0;
    $hasPhoto   = false;
    if (!empty($s['photo_path'])) {
        $file = ssp_photo_file((string)$s['photo_path']);
        $mime = ssp_photo_mime($file);
        if ($mime !== null) {
            $bin        = (string)file_get_contents($file);
            $photoBytes = strlen($bin);
            $hasPhoto   = true;
            $payload['photo_base64'] = 'data:' . $mime . ';base64,' . base64_encode($bin);
            unset($bin);
        }
    }
    if (!$hasPhoto) {
        $payload['remove_photo'] = true;
    }

    $ident = ['student_id' => (string)$s['pu_student_id']];
    if (!empty($s['pu_id'])) {
        $ident = ['id' => (int)$s['pu_id']] + $ident;
    }
    $payload = $ident + $payload;

    $resp = ssp_api()->updateStudent($payload);

    $logged = $payload;
    if (isset($logged['photo_base64'])) {
        $logged['photo_base64'] = '[photo, ' . $photoBytes . ' bytes]';
    }
    ssp_api_log($id, $userId, 'v1/students/update', null, $logged, $resp);

    if ($resp['ok']) {
        $d = is_array($resp['body']['data'] ?? null) ? $resp['body']['data'] : [];
        $db->prepare('UPDATE ssp_students
                SET pending_update = 0, sync_attempts = sync_attempts + 1,
                    pu_student_id = COALESCE(?, pu_student_id), pu_status = COALESCE(?, pu_status),
                    pu_photo_url = ?, last_error = NULL, last_response_json = ?
              WHERE id = ?')
           ->execute([
               isset($d['student_id']) ? (string)$d['student_id'] : null,
               isset($d['status']) ? (string)$d['status'] : null,
               isset($d['photo_url']) ? (string)$d['photo_url'] : null,
               json_encode($resp['body'], JSON_UNESCAPED_UNICODE),
               $id,
           ]);
        $changed = is_array($d['changed_fields'] ?? null) ? $d['changed_fields'] : [];
        $message = $resp['message'] !== '' ? $resp['message'] : 'Student updated.';
        if ($changed) {
            $message .= ' Changed at the university: ' . implode(', ', $changed) . '.';
        }
        return ['ok' => true, 'message' => $message, 'student_id' => $d['student_id'] ?? $s['pu_student_id'],
            'changed_fields' => $changed,
            'warnings' => is_array($resp['body']['warnings'] ?? null) ? $resp['body']['warnings'] : [], 'response' => $resp];
    }

    $errors = is_array($resp['body']['errors'] ?? null) ? $resp['body']['errors'] : [];
    $db->prepare('UPDATE ssp_students SET pending_update = 1, sync_attempts = sync_attempts + 1, last_error = ?, last_response_json = ? WHERE id = ?')
       ->execute([ssp_error_summary($resp), json_encode($resp['body'], JSON_UNESCAPED_UNICODE), $id]);

    return ['ok' => false, 'message' => ssp_error_summary($resp), 'errors' => $errors,
        'retryable' => PuApiClient::isRetryable($resp), 'response' => $resp];
}

/**
 * Delete a student.  If the student is registered at the university the record
 * (and all its data there) is permanently removed through the API first; the
 * local row is then KEPT and marked sync_status = 'deleted'.
 *
 * @return array{ok: bool, message: string, at_university?: bool, response?: array}
 */
function ssp_delete_student(int $id, int $userId, string $reason = ''): array
{
    $db = ssp_db();
    $s  = ssp_student_find($id);
    if ($s === null) {
        return ['ok' => false, 'message' => 'Student not found.'];
    }
    if ($s['sync_status'] === 'deleted') {
        return ['ok' => true, 'message' => 'This student was already deleted.'];
    }

    $atUniversity = $s['sync_status'] === 'synced' && !empty($s['pu_student_id']);
    $resp         = null;
    $puData       = null;

    if ($atUniversity) {
        if (!ssp_api()->isConfigured()) {
            return ['ok' => false, 'message' => 'The Prime University API key is not configured (config.php → pu_api.api_key).'];
        }
        $payload = [
            'student_id' => (string)$s['pu_student_id'],
            'confirm'    => true,
            'reason'     => mb_substr($reason !== '' ? $reason : 'Deleted from partner portal (' . $s['reference_no'] . ')', 0, 500),
        ];
        if (!empty($s['pu_id'])) {
            $payload = ['id' => (int)$s['pu_id']] + $payload;
        }
        $resp = ssp_api()->deleteStudent($payload);
        ssp_api_log($id, $userId, 'v1/students/delete', null, $payload, $resp);

        if (!$resp['ok'] && $resp['code'] !== 'student_not_found') {
            $db->prepare('UPDATE ssp_students SET last_error = ?, last_response_json = ? WHERE id = ?')
               ->execute([ssp_error_summary($resp), json_encode($resp['body'], JSON_UNESCAPED_UNICODE), $id]);
            return ['ok' => false, 'message' => 'The university refused the deletion: ' . ssp_error_summary($resp), 'response' => $resp];
        }
        $puData = is_array($resp['body']['data'] ?? null) ? $resp['body']['data'] : null;
    }

    $db->prepare('UPDATE ssp_students
            SET sync_status = "deleted", pending_update = 0, deleted_at = NOW(), deleted_by = ?, delete_reason = ?,
                last_error = NULL, last_response_json = COALESCE(?, last_response_json)
          WHERE id = ?')
       ->execute([$userId, $reason !== '' ? mb_substr($reason, 0, 500) : null,
                  $resp ? json_encode($resp['body'], JSON_UNESCAPED_UNICODE) : null, $id]);

    if ($atUniversity) {
        $message = $resp['code'] === 'student_not_found'
            ? 'The student no longer existed at the university; the local record is now marked as deleted.'
            : 'Student permanently deleted at Prime University'
              . ($puData ? ' (' . (int)($puData['results_deleted'] ?? 0) . ' result(s), ' . (int)($puData['qualifications_deleted'] ?? 0) . ' qualification(s) removed)' : '')
              . '. The local record is kept and marked as deleted.';
    } else {
        $message = 'Student was never registered at the university; the local record is marked as deleted.';
    }
    return ['ok' => true, 'message' => $message, 'at_university' => $atUniversity, 'response' => $resp];
}

/**
 * Publish / correct the final result of a student that is already registered.
 *
 * @return array{ok: bool, message: string, errors?: array, response?: array}
 */
function ssp_publish_result(int $id, array $result, int $userId): array
{
    $db = ssp_db();
    $s  = ssp_student_find($id);
    if ($s === null) {
        return ['ok' => false, 'message' => 'Student not found.'];
    }
    if ($s['sync_status'] !== 'synced' || empty($s['pu_student_id'])) {
        return ['ok' => false, 'message' => 'The student must be registered at the university before a result can be published.'];
    }
    if (!ssp_api()->isConfigured()) {
        return ['ok' => false, 'message' => 'The Prime University API key is not configured (config.php → pu_api.api_key).'];
    }

    $payload = ['student_id' => (string)$s['pu_student_id'], 'student_name' => (string)$s['full_name']] + $result;
    $resp    = ssp_api()->publishResult($payload);
    ssp_api_log($id, $userId, 'v1/results/create', null, $payload, $resp);

    if ($resp['ok']) {
        $d = is_array($resp['body']['data'] ?? null) ? $resp['body']['data'] : [];
        $db->prepare('UPDATE ssp_students SET pu_result_id = ?, pu_status = COALESCE(?, pu_status), result_json = ?, last_error = NULL WHERE id = ?')
           ->execute([
               isset($d['result_id']) ? (int)$d['result_id'] : null,
               isset($d['student']['status']) ? (string)$d['student']['status'] : null,
               json_encode($d, JSON_UNESCAPED_UNICODE),
               $id,
           ]);
        $message = $resp['message'] !== '' ? $resp['message'] : 'Result published.';
        if (($d['action'] ?? '') === 'updated') {
            $message .= ' (An existing result for this semester was updated.)';
        }
        return ['ok' => true, 'message' => $message,
            'warnings' => is_array($resp['body']['warnings'] ?? null) ? $resp['body']['warnings'] : [], 'response' => $resp];
    }

    return ['ok' => false, 'message' => ssp_error_summary($resp),
        'errors' => is_array($resp['body']['errors'] ?? null) ? $resp['body']['errors'] : [], 'response' => $resp];
}
