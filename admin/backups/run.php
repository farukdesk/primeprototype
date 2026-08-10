<?php
/** Run a manual backup – SUPER ADMIN ONLY. */
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(APP_URL . '/backups/index.php');
csrf_check();

$scope = (string)($_POST['scope'] ?? 'full');
$user  = auth_user();

[$ok, $msg] = bk_run_backup($scope, 'manual', 'manual', (int)$user['id']);

if ($ok) {
    log_change('backups', 'CREATE', null, 'Manual ' . $scope . ' backup', null, null, null, $msg);
    flash_set('success', $msg);
} else {
    flash_set('error', $msg);
}
redirect(APP_URL . '/backups/index.php');
