<?php
/**
 * Student Portal API – POST /api/student/change-password.php
 * ===========================================================
 * Lets a signed-in student change their account password from the mobile app
 * (Settings → Password Change).
 *
 * Request (form-encoded or JSON):
 *   current_password, new_password
 *
 * Success response:
 *   { "ok": true, "message": "Password changed successfully." }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sp_api_error(405, 'Method Not Allowed. Use POST.');
}

$ctx = sp_api_auth();
$uid = (int)$ctx['user']['user_id'];

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$current = (string)($input['current_password'] ?? '');
$new     = (string)($input['new_password'] ?? '');

if ($current === '' || $new === '') {
    sp_api_error(400, 'current_password and new_password are required.');
}
if (strlen($new) < 8) {
    sp_api_error(400, 'The new password must be at least 8 characters long.');
}
if ($new === $current) {
    sp_api_error(400, 'The new password must be different from the current password.');
}

$stmt = db()->prepare('SELECT password FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$uid]);
$stored = $stmt->fetchColumn();
if ($stored === false) {
    sp_api_error(404, 'Account not found.');
}

// Mirror the verification used by auth/login.php (password_hash, legacy MD5/SHA1, plain).
$stored = (string)$stored;
$is_current_correct = password_verify($current, $stored)
    || md5($current) === $stored
    || sha1($current) === $stored
    || $current === $stored;

if (!$is_current_correct) {
    sp_api_error(403, 'The current password is incorrect.');
}

try {
    db()->prepare('UPDATE users SET password = ? WHERE id = ?')
       ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
} catch (Throwable $e) {
    sp_api_error(500, 'Could not update the password. Please try again.');
}

// Changing the password signs the account out everywhere else: revoke every
// other API session and drop the push registrations of all other devices so
// they stop receiving this account's notifications.
try {
    db()->prepare('DELETE FROM api_tokens WHERE user_id = ? AND id != ?')
       ->execute([$uid, (int)$ctx['user']['token_id']]);

    $device_id = trim((string)($ctx['user']['device_id'] ?? ''));
    if ($device_id !== '') {
        db()->prepare(
            'DELETE FROM student_push_tokens
             WHERE user_id = ? AND (device_id IS NULL OR device_id != ?)'
        )->execute([$uid, $device_id]);
    } else {
        db()->prepare('DELETE FROM student_push_tokens WHERE user_id = ?')->execute([$uid]);
    }
} catch (Throwable $e) {
    // Best-effort: the password change itself already succeeded.
    error_log('Student change-password: session revocation failed – ' . $e->getMessage());
}

sp_api_ok(['message' => 'Password changed successfully.']);
