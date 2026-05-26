<?php
/**
 * API – POST /api/push/register.php
 * ====================================
 * Registers or updates the FCM device token for the authenticated user.
 *
 * Request (JSON or form-encoded):
 *   { "fcm_token": "...", "device_id": "...", "platform": "android" }
 *
 * Success response:
 *   { "ok": true, "message": "Push token registered." }
 */

require_once __DIR__ . '/../includes/auth_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method Not Allowed. Use POST.');
}

$API_USER = api_auth();

$input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$fcm_token = trim($input['fcm_token'] ?? '');
$device_id = trim($input['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
$platform  = trim($input['platform']  ?? 'android');

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
    db()->prepare(
        'INSERT INTO api_push_tokens (user_id, fcm_token, device_id, platform)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token), updated_at = NOW()'
    )->execute([$user_id, $fcm_token, $device_id, $platform]);
} else {
    // No device_id – delete old token(s) for this user and insert fresh
    db()->prepare('DELETE FROM api_push_tokens WHERE user_id = ? AND device_id IS NULL')
       ->execute([$user_id]);
    db()->prepare(
        'INSERT INTO api_push_tokens (user_id, fcm_token, device_id, platform)
         VALUES (?, ?, NULL, ?)'
    )->execute([$user_id, $fcm_token, $platform]);
}

api_ok(['message' => 'Push token registered.']);
