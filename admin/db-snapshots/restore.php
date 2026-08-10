<?php
/**
 * Restore a database snapshot – SUPER ADMIN ONLY.
 * POST { id } with CSRF token.
 */
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/db-snapshots/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
[$ok, $msg] = snap_restore($id);

if ($ok) {
    $snap = snap_get($id);
    log_change(
        'db-snapshots',
        'UPDATE',
        $id,
        'Snapshot #' . $id . ' (' . ($snap['table_name'] ?? '?') . ')',
        null,
        null,
        null,
        'Restored database snapshot: ' . $msg
    );
    flash_set('success', $msg);
} else {
    flash_set('error', $msg);
}

redirect(APP_URL . '/db-snapshots/view.php?id=' . $id);
