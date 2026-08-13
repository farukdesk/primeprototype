<?php
/** Delete a backup (record + Google Drive files) – SUPER ADMIN ONLY. */
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/backups/index.php');
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM sys_backups WHERE id = ?');
$stmt->execute([$id]);
$backup = $stmt->fetch();

if ($backup) {
    bk_drive_delete($backup['db_drive_id'] ?? null);
    foreach (bk_drive_ids($backup['files_drive_id'] ?? null) as $fid) {
        bk_drive_delete($fid);
    }
    db()->prepare('DELETE FROM sys_backups WHERE id = ?')->execute([$id]);
    log_change('backups', 'DELETE', $id, 'Backup #' . $id, null, null, null, 'Backup deleted from Google Drive.');
    flash_set('success', 'Backup #' . $id . ' deleted from Google Drive.');
} else {
    flash_set('error', 'Backup not found.');
}
redirect(APP_URL . '/backups/index.php');
