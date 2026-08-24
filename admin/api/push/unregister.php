<?php
/**
 * API – POST /api/push/unregister.php
 * ======================================
 * Removes this device's FCM registration for the authenticated user. Called by
 * the app on logout so the next account that signs in on the phone does not
 * inherit this user's push notifications.
 *
 * Request (JSON or form-encoded):
 *   { "fcm_token": "...", "device_id": "..." }
 */

require_once __DIR__ . '/../includes/auth_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method Not Allowed. Use POST.');
}

$API_USER = api_auth();
$user_id  = (int)$API_USER['user_id'];

$input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$fcm_token = trim($input['fcm_token'] ?? '');
$device_id = trim($input['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));

try {
    if ($fcm_token !== '') {
        db()->prepare('DELETE FROM api_push_tokens WHERE user_id = ? AND fcm_token = ?')
           ->execute([$user_id, $fcm_token]);
    }
    if ($device_id !== '') {
        db()->prepare('DELETE FROM api_push_tokens WHERE user_id = ? AND device_id = ?')
           ->execute([$user_id, $device_id]);
    }
    if ($fcm_token === '' && $device_id === '') {
        db()->prepare('DELETE FROM api_push_tokens WHERE user_id = ?')->execute([$user_id]);
    }
} catch (Throwable $e) {
    error_log('Push unregister: ' . $e->getMessage());
}

api_ok(['message' => 'Push token unregistered.']);
