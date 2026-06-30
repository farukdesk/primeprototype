<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('semester-drop', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/semester-drop/index.php');
}
csrf_check();

$id     = (int)($_POST['id'] ?? 0);
$reason = trim($_POST['cancel_reason'] ?? '');
$me     = auth_user();

$drop = sd_get_drop($id);
if (!$drop) {
    flash_set('error', 'Semester drop record not found.');
    redirect(APP_URL . '/semester-drop/index.php');
}

if (($drop['kind'] ?? 'drop') === 'dropout') {
    flash_set('error', 'Dropouts are re-instated, not cancelled. Use the re-instate option.');
    redirect(APP_URL . '/semester-drop/view.php?id=' . $id);
}

if ($drop['status'] !== 'active') {
    flash_set('warning', 'This semester drop is already cancelled.');
    redirect(APP_URL . '/semester-drop/view.php?id=' . $id);
}

sd_cancel_drop($id, (int)$me['id'], $reason !== '' ? $reason : null);
flash_set('success', 'Semester drop cancelled. Normal dues restored.');
redirect(APP_URL . '/semester-drop/view.php?id=' . $id);
