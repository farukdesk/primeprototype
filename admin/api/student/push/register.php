<?php
/**
 * Student Portal API – POST /api/student/push/register.php
 * ===========================================================
 * Registers or updates the FCM device token for the authenticated student.
 *
 * Request (JSON or form-encoded):
 *   { "fcm_token": "...", "device_id": "...", "platform": "android", "app_version": "1.0.1" }
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

$input       = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$fcm_token   = trim($input['fcm_token'] ?? '');
$device_id   = trim($input['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
$platform    = in_array($input['platform'] ?? '', ['android', 'ios'], true) ? $input['platform'] : 'android';
$app_version = substr(trim((string)($input['app_version'] ?? '')), 0, 30);

if ($fcm_token === '') {
    sp_api_error(400, 'fcm_token is required.');
}

$user_id = (int)$user['user_id'];

// Ensure the token table exists. The App Notification migration assumed it was
// "already created", so fresh installs never had it — which silently broke
// device registration and left the admin "Student Devices" list empty.
try {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS `student_push_tokens` (
            `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`     int(10) UNSIGNED NOT NULL COMMENT 'FK users.id (student portal account)',
            `fcm_token`   varchar(512)     NOT NULL,
            `device_id`   varchar(64)      DEFAULT NULL COMMENT 'Stable per-install id sent by the app',
            `platform`    enum('android','ios') NOT NULL DEFAULT 'android',
            `app_version` varchar(30)      DEFAULT NULL,
            `created_at`  datetime         NOT NULL DEFAULT current_timestamp(),
            `updated_at`  datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_student_push_user_device` (`user_id`, `device_id`),
            KEY `idx_student_push_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $e) {
    error_log('Student push register: could not ensure student_push_tokens table – ' . $e->getMessage());
}

try {
    if ($device_id !== '') {
        try {
            db()->prepare(
                'INSERT INTO student_push_tokens (user_id, fcm_token, device_id, platform, app_version)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token),
                                         app_version = VALUES(app_version), updated_at = NOW()'
            )->execute([$user_id, $fcm_token, $device_id, $platform, $app_version !== '' ? $app_version : null]);
        } catch (Throwable $e) {
            // app_version column not installed yet (run admin/app-push-app-version.sql).
            db()->prepare(
                'INSERT INTO student_push_tokens (user_id, fcm_token, device_id, platform)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE fcm_token = VALUES(fcm_token), updated_at = NOW()'
            )->execute([$user_id, $fcm_token, $device_id, $platform]);
        }
    } else {
        db()->prepare('DELETE FROM student_push_tokens WHERE user_id = ? AND device_id IS NULL')
           ->execute([$user_id]);
        try {
            db()->prepare(
                'INSERT INTO student_push_tokens (user_id, fcm_token, device_id, platform, app_version)
                 VALUES (?, ?, NULL, ?, ?)'
            )->execute([$user_id, $fcm_token, $platform, $app_version !== '' ? $app_version : null]);
        } catch (Throwable $e) {
            db()->prepare(
                'INSERT INTO student_push_tokens (user_id, fcm_token, device_id, platform)
                 VALUES (?, ?, NULL, ?)'
            )->execute([$user_id, $fcm_token, $platform]);
        }
    }
} catch (Throwable $e) {
    // Surface a clean JSON error (the app retries on the next launch) and log
    // the root cause instead of dying with a blank 500.
    error_log('Student push register: token insert failed – ' . $e->getMessage());
    sp_api_error(500, 'Could not register the device. Please try again later.');
}

sp_api_ok(['message' => 'Push token registered.']);
