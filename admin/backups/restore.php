<?php
/** Restore a backup from Google Drive – SUPER ADMIN ONLY. */
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/backups/index.php');
csrf_check();

$id   = (int)($_POST['id'] ?? 0);
$what = (string)($_POST['what'] ?? '');
$user = auth_user();

$stmt = db()->prepare('SELECT * FROM sys_backups WHERE id = ?');
$stmt->execute([$id]);
$backup = $stmt->fetch();

if (!$backup || $backup['status'] !== 'completed') {
    flash_set('error', 'Backup not found or not completed.');
    redirect(APP_URL . '/backups/index.php');
}

if ($what === 'db') {
    [$ok, $msg] = bk_restore_db($backup, (int)$user['id']);
} elseif ($what === 'files') {
    [$ok, $msg] = bk_restore_files($backup, (int)$user['id']);
} else {
    $ok = false;
    $msg = 'Invalid restore target.';
}

if ($ok) {
    log_change('backups', 'UPDATE', $id, 'Backup #' . $id, null, null, null, 'RESTORE: ' . $msg);
    flash_set('success', $msg);
} else {
    flash_set('error', $msg);
}
redirect(APP_URL . '/backups/index.php');
