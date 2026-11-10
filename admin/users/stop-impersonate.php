<?php
/**
 * Shadow login (impersonation) – STOP / "Return to my account"
 * ============================================================
 * Restores the real super-admin session that was parked by impersonate.php.
 * Callable by the impersonated session itself (it is not a super admin), so it
 * intentionally does NOT require super-admin — it only requires that an
 * impersonation is actually in progress.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';
auth_check();

if (!is_impersonating()) {
    redirect(APP_URL . '/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/index.php');
}

csrf_check();

$impersonator = $_SESSION['impersonator'];

// Capture the impersonated user's details for the audit trail before switching.
$target_id   = (int)($_SESSION['user_id'] ?? 0);
$target_name = '';
$target_user = '';
try {
    $stmt = db()->prepare('SELECT full_name, username FROM users WHERE id = ?');
    $stmt->execute([$target_id]);
    if ($row = $stmt->fetch()) {
        $target_name = $row['full_name'];
        $target_user = $row['username'];
    }
} catch (Throwable $e) {}

// Restore the real admin identity.
unset($_SESSION['impersonator']);
session_regenerate_id(true);
$_SESSION['user_id']       = (int)$impersonator['user_id'];
$_SESSION['group_id']      = $impersonator['group_id'] ?? null;
$_SESSION['is_super']      = (int)($impersonator['is_super'] ?? 0);
$_SESSION['last_activity'] = time();

// Audit log AFTER restore, so auth_user() resolves to the real admin again.
log_change(
    'users',
    'UPDATE',
    $target_id,
    $target_user !== '' ? ($target_user . ($target_name !== '' ? ' (' . $target_name . ')' : '')) : ('#' . $target_id),
    'impersonation',
    'impersonating: ' . ($target_user !== '' ? $target_user : ('#' . $target_id)),
    'admin: ' . ($impersonator['name'] ?? ''),
    'Super admin ' . ($impersonator['name'] ?? '') . ' ended the shadow login'
        . ($target_name !== '' ? ' of ' . $target_name : '') . '.'
);

flash_set('success', 'You are back in your own account.');
redirect(APP_URL . '/users/index.php');
