<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/medical-center/prescriptions.php');
}

csrf_check();

if (!mc_can_delete()) {
    flash_set('error', 'Permission denied.');
    redirect(APP_URL . '/medical-center/prescriptions.php');
}

$id = (int)($_POST['id'] ?? 0);
$db = db();

$rx = $db->prepare('SELECT * FROM mc_prescriptions WHERE id = ?');
$rx->execute([$id]);
$rx = $rx->fetch();

if (!$rx) {
    flash_set('error', 'Prescription not found.');
    redirect(APP_URL . '/medical-center/prescriptions.php');
}

$db->prepare('DELETE FROM mc_prescriptions WHERE id = ?')->execute([$id]);
log_change('medical-center', 'DELETE', $id, $rx['patient_name'], null, null, null, 'Prescription deleted');

flash_set('success', 'Prescription deleted successfully.');
redirect(APP_URL . '/medical-center/prescriptions.php');
