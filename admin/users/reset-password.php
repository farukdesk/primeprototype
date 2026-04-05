<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/users/index.php');
}

csrf_check();

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Invalid user.');
    redirect(APP_URL . '/users/index.php');
}

$me = auth_user();
if ($id === (int)$me['id']) {
    flash_set('error', 'You cannot reset your own password from this action.');
    redirect(APP_URL . '/users/index.php');
}

$stmt = db()->prepare('SELECT id, full_name, email, username FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'User not found.');
    redirect(APP_URL . '/users/index.php');
}

// Generate a secure random 8-character password
$chars    = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
$password = '';
for ($i = 0; $i < 8; $i++) {
    $password .= $chars[random_int(0, strlen($chars) - 1)];
}

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $id]);

// Send email with new credentials
$sent = send_template_email('admin_password_reset', $user['email'], $user['full_name'], [
    'full_name'    => $user['full_name'],
    'username'     => $user['username'],
    'new_password' => $password,
    'login_url'    => APP_URL . '/login.php',
]);

// Log the action
log_change(
    'users',
    'UPDATE',
    $id,
    $user['username'] . ' (' . $user['email'] . ')',
    'password',
    '(hashed)',
    '(reset by admin)',
    'Password reset by super admin. New credentials sent to ' . $user['email'] . '.'
);

if ($sent) {
    flash_set('success', 'Password reset for <strong>' . h($user['full_name']) . '</strong>. New credentials sent to <strong>' . h($user['email']) . '</strong>.');
} else {
    flash_set('warning', 'Password reset for <strong>' . h($user['full_name']) . '</strong>, but the email could not be sent. Please inform the user manually.');
}

redirect(APP_URL . '/users/index.php');
