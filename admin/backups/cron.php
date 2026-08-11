<?php
/**
 * Daily auto backup runner – schedule ONCE PER DAY via cron:
 *   php /path/to/admin/backups/cron.php
 * or, if CLI cron is not available, via URL (key required):
 *   wget -qO- "https://your-site/admin/backups/cron.php?key=CRON_KEY"
 *
 * Sunday's backup is retained 30 days ('weekly'); other days 7 days
 * ('daily'). Expired auto backups are pruned from Google Drive after
 * each run. Retention windows are configurable in Backup Settings.
 */
require_once __DIR__ . '/helpers.php';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    header('Content-Type: text/plain');
    $key      = (string)($_GET['key'] ?? '');
    $expected = (string)bk_setting_get('cron_key', '');
    if ($expected === '' || $key === '' || !hash_equals($expected, $key)) {
        http_response_code(403);
        exit("Forbidden\n");
    }
}

if (bk_setting_get('auto_enabled', '0') !== '1') {
    exit("Auto backup is disabled in Backup Settings.\n");
}

// Do not run twice on the same day (unless forced).
$force = $is_cli ? in_array('--force', $argv ?? [], true) : (($_GET['force'] ?? '') === '1');
try {
    // First, fail anything stuck in "running" so a dead run from a previous
    // day/hour does not block today's backup.
    $stale = bk_mark_stale();
    if ($stale > 0) {
        echo 'Marked ' . $stale . " stale running backup(s) as failed.\n";
    }
    $done = db()->query(
        "SELECT COUNT(*) FROM sys_backups
         WHERE backup_type = 'auto' AND status IN ('running','completed')
           AND DATE(created_at) = CURDATE()"
    )->fetchColumn();
    if ((int)$done > 0 && !$force) {
        exit("Auto backup already ran today.\n");
    }
} catch (Throwable $e) {
    exit("Backup tables missing – run admin/backups.sql first.\n");
}

$scope     = (string)bk_setting_get('auto_scope', 'full');
$retention = (date('w') === '0') ? 'weekly' : 'daily'; // Sunday backup kept longer

[$ok, $msg] = bk_run_backup($scope, 'auto', $retention, null);
echo $msg . "\n";

$plog   = [];
$pruned = bk_prune($plog);
echo 'Pruned ' . $pruned . " expired backup(s) from Google Drive.\n";
if ($plog) echo implode("\n", $plog) . "\n";
