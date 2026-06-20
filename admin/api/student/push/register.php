<?php
/**
 * Student Portal API – POST /api/student/push/register.php
 * ===========================================================
 * Registers or updates the FCM device token for the authenticated student.
 *
 * Request (JSON or form-encoded):
 *   { "fcm_token": "...", "device_id": "...", "platform": "android" }
 *
 * Success response:
 *   { "ok": true, "message": "Push token registered." }
 */

require_once __DIR__ . '/../../includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sp_api_error(405, 'Method Not Allowed. Use POST.');
}

$ctx     = sp_api_auth();
$user    = $ctx['user'];

$input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$fcm_token = trim($input['fcm_token'] ?? '');
$device_id = trim($input['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
$platform  = in_array($input['platform'] ?? '', ['android', 'ios'], true) ? $input['platform'] : 'android';

if ($fcm_token === '') {
    sp_api_error(400, 'fcm_token is required.');
}

$user_id = (int)$user['user_id'];

if ($device_id !== '') {
    db()->prepare(
        'INSERT INTO student_push_tokens (user_id, fcm_token, device_id, platform)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token), updated_at = NOW()'
    )->execute([$user_id, $fcm_token, $device_id, $platform]);
} else {
    db()->prepare('DELETE FROM student_push_tokens WHERE user_id = ? AND device_id IS NULL')
       ->execute([$user_id]);
    db()->prepare(
        'INSERT INTO student_push_tokens (user_id, fcm_token, device_id, platform)
         VALUES (?, ?, NULL, ?)'
    )->execute([$user_id, $fcm_token, $platform]);
}

sp_api_ok(['message' => 'Push token registered.']);
