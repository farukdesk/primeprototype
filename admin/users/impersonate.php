<?php
/**
 * Shadow login (impersonation) – START
 * =====================================
 * Lets a super admin sign in as another user WITHOUT their password, for
 * support / troubleshooting. The real admin identity is parked in the session
 * and restored via stop-impersonate.php. Every switch is audit-logged.
 *
 * No password is ever read, exposed or bypassed — this is a scoped session
 * switch that is always reversible and fully traceable in the Change Log.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_super_admin();

// Bring the admin back to the same filtered / paginated Users view on error.
$return_qs = isset($_POST['return']) ? (string)$_POST['return'] : '';
$return_qs = str_replace(["\r", "\n"], '', $return_qs);
$users_index_url = APP_URL . '/users/index.php' . ($return_qs !== '' ? '?' . $return_qs : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($users_index_url);
}

csrf_check();

// Never allow starting a new impersonation while already impersonating —
// the admin must return to their own account first.
if (is_impersonating()) {
    flash_set('error', 'You are already signed in as another user. Return to your account first.');
    redirect($users_index_url);
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Invalid user.');
    redirect($users_index_url);
}

$me = auth_user();
if ($id === (int)$me['id']) {
    flash_set('error', 'You cannot sign in as yourself.');
    redirect($users_index_url);
}

$stmt = db()->prepare(
    'SELECT u.id, u.full_name, u.username, u.email, u.group_id, u.is_active, g.is_super
     FROM users u
     LEFT JOIN user_groups g ON g.id = u.group_id
     WHERE u.id = ?'
);
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    flash_set('error', 'User not found.');
    redirect($users_index_url);
}
if (!$target['is_active']) {
    flash_set('error', 'That account is inactive and cannot be accessed.');
    redirect($users_index_url);
}
if ((int)$target['is_super'] === 1) {
    flash_set('error', 'You cannot sign in as another Super Administrator.');
    redirect($users_index_url);
}

// Audit log BEFORE the switch, while auth_user() still resolves to the admin.
log_change(
    'users',
    'UPDATE',
    (int)$target['id'],
    $target['username'] . ' (' . $target['email'] . ')',
    'impersonation',
    'admin: ' . $me['username'],
    'impersonating: ' . $target['username'],
    'Super admin ' . $me['full_name'] . ' started a shadow login as ' . $target['full_name'] . '.'
);

// Park the real admin identity so it can be restored on "Return to my account".
$_SESSION['impersonator'] = [
    'user_id'  => (int)$me['id'],
    'group_id' => $me['group_id'] ?? null,
    'is_super' => (int)($me['is_super'] ?? 0),
    'name'     => $me['full_name'],
    'since'    => time(),
];

// Switch the active session to the target user.
session_regenerate_id(true);
$_SESSION['user_id']       = (int)$target['id'];
$_SESSION['group_id']      = $target['group_id'];
$_SESSION['is_super']      = (int)$target['is_super'];
$_SESSION['last_activity'] = time();

flash_set('info', 'You are now signed in as <strong>' . h($target['full_name']) . '</strong>.');
redirect(APP_URL . '/index.php');
