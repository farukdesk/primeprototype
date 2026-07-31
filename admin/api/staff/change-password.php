<?php
/**
 * Staff API – POST /api/staff/change-password.php
 * ================================================
 * Lets a signed-in employee change their account password from the mobile app
 * (Settings → Password Change).
 *
 * Request (form-encoded or JSON):
 *   current_password, new_password
 *
 * Success response:
 *   { "ok": true, "message": "Password changed successfully." }
 */

require_once __DIR__ . '/includes/auth_staff_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method Not Allowed. Use POST.');
}

$ctx = staff_api_auth();
$uid = (int)$ctx['user']['user_id'];

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$current = (string)($input['current_password'] ?? '');
$new     = (string)($input['new_password'] ?? '');

if ($current === '' || $new === '') {
    api_error(400, 'current_password and new_password are required.');
}
if (strlen($new) < 8) {
    api_error(400, 'The new password must be at least 8 characters long.');
}
if ($new === $current) {
    api_error(400, 'The new password must be different from the current password.');
}

$stmt = db()->prepare('SELECT password FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$uid]);
$stored = $stmt->fetchColumn();
if ($stored === false) {
    api_error(404, 'Account not found.');
}

// Mirror the verification used by auth/login.php (password_hash, legacy MD5/SHA1, plain).
$stored = (string)$stored;
$is_current_correct = password_verify($current, $stored)
    || md5($current) === $stored
    || sha1($current) === $stored
    || $current === $stored;

if (!$is_current_correct) {
    api_error(403, 'The current password is incorrect.');
}

try {
    db()->prepare('UPDATE users SET password = ? WHERE id = ?')
       ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
} catch (Throwable $e) {
    api_error(500, 'Could not update the password. Please try again.');
}

api_ok(['message' => 'Password changed successfully.']);
