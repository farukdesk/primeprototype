<?php
/**
 * Student Portal API – POST /api/student/push/unregister.php
 * ============================================================
 * Removes this device's FCM registration for the signed-in student. Called by
 * the app on logout so the next account that signs in on the phone does not
 * inherit this user's push notifications (which caused the same notification
 * to be delivered once per previously signed-in account).
 *
 * Request (JSON or form-encoded):
 *   { "fcm_token": "...", "device_id": "..." }
 */

require_once __DIR__ . '/../includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sp_api_error(405, 'Method Not Allowed. Use POST.');
}

$ctx = sp_api_auth();
$uid = (int)$ctx['user']['user_id'];

$input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$fcm_token = trim($input['fcm_token'] ?? '');
$device_id = trim($input['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));

try {
    if ($fcm_token !== '') {
        db()->prepare('DELETE FROM student_push_tokens WHERE user_id = ? AND fcm_token = ?')
           ->execute([$uid, $fcm_token]);
    }
    if ($device_id !== '') {
        db()->prepare('DELETE FROM student_push_tokens WHERE user_id = ? AND device_id = ?')
           ->execute([$uid, $device_id]);
    }
    if ($fcm_token === '' && $device_id === '') {
        // No identifiers supplied: forget every device of this user.
        db()->prepare('DELETE FROM student_push_tokens WHERE user_id = ?')->execute([$uid]);
    }
} catch (Throwable $e) {
    error_log('Student push unregister: ' . $e->getMessage());
}

sp_api_ok(['message' => 'Push token unregistered.']);
