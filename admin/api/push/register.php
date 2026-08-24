<?php
/**
 * API – POST /api/push/register.php
 * ====================================
 * Registers or updates the FCM device token for the authenticated user.
 *
 * Request (JSON or form-encoded):
 *   { "fcm_token": "...", "device_id": "...", "platform": "android", "app_version": "1.0.1" }
 *
 * Success response:
 *   { "ok": true, "message": "Push token registered." }
 */

require_once __DIR__ . '/../includes/auth_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method Not Allowed. Use POST.');
}

$API_USER = api_auth();

$input       = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$fcm_token   = trim($input['fcm_token'] ?? '');
$device_id   = trim($input['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
$platform    = trim($input['platform']  ?? 'android');
$app_version = substr(trim((string)($input['app_version'] ?? '')), 0, 30);

if ($fcm_token === '') {
    api_error(400, 'fcm_token is required.');
}

$valid_platforms = ['android', 'ios'];
if (!in_array($platform, $valid_platforms, true)) {
    $platform = 'android';
}

$user_id = (int)$API_USER['user_id'];

if ($device_id !== '') {
    // Upsert by (user_id, device_id)
    try {
        db()->prepare(
            'INSERT INTO api_push_tokens (user_id, fcm_token, device_id, platform, app_version)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token),
                                     app_version = VALUES(app_version), updated_at = NOW()'
        )->execute([$user_id, $fcm_token, $device_id, $platform, $app_version !== '' ? $app_version : null]);
    } catch (Throwable $e) {
        // app_version column not installed yet (run admin/app-push-app-version.sql).
        db()->prepare(
            'INSERT INTO api_push_tokens (user_id, fcm_token, device_id, platform)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token), updated_at = NOW()'
        )->execute([$user_id, $fcm_token, $device_id, $platform]);
    }
} else {
    // No device_id – delete old token(s) for this user and insert fresh
    db()->prepare('DELETE FROM api_push_tokens WHERE user_id = ? AND device_id IS NULL')
       ->execute([$user_id]);
    try {
        db()->prepare(
            'INSERT INTO api_push_tokens (user_id, fcm_token, device_id, platform, app_version)
             VALUES (?, ?, NULL, ?, ?)'
        )->execute([$user_id, $fcm_token, $platform, $app_version !== '' ? $app_version : null]);
    } catch (Throwable $e) {
        db()->prepare(
            'INSERT INTO api_push_tokens (user_id, fcm_token, device_id, platform)
             VALUES (?, ?, NULL, ?)'
        )->execute([$user_id, $fcm_token, $platform]);
    }
}

// A device belongs to exactly one signed-in account: drop rows that map this
// token (or device) to another user, plus any student registration left over
// from a previous student session on the same phone (prevents duplicate
// notifications on shared devices).
try {
    db()->prepare('DELETE FROM api_push_tokens WHERE fcm_token = ? AND user_id != ?')
       ->execute([$fcm_token, $user_id]);
    if ($device_id !== '') {
        db()->prepare('DELETE FROM api_push_tokens WHERE device_id = ? AND user_id != ?')
           ->execute([$device_id, $user_id]);
    }
    db()->prepare('DELETE FROM student_push_tokens WHERE fcm_token = ?')->execute([$fcm_token]);
} catch (Throwable $e) {
    error_log('Push register: stale token cleanup failed - ' . $e->getMessage());
}

api_ok(['message' => 'Push token registered.']);
