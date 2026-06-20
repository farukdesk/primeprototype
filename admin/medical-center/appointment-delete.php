<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/medical-center/appointments.php');
}

csrf_check();

if (!mc_can_delete()) {
    flash_set('error', 'Permission denied.');
    redirect(APP_URL . '/medical-center/appointments.php');
}

$id = (int)($_POST['id'] ?? 0);
$db = db();

$apt = $db->prepare('SELECT * FROM mc_appointments WHERE id = ?');
$apt->execute([$id]);
$apt = $apt->fetch();

if (!$apt) {
    flash_set('error', 'Appointment not found.');
    redirect(APP_URL . '/medical-center/appointments.php');
}

$db->prepare('DELETE FROM mc_appointments WHERE id = ?')->execute([$id]);
log_change('medical-center', 'DELETE', $id, $apt['patient_name'], null, null, null, 'Appointment deleted');

flash_set('success', 'Appointment deleted successfully.');
redirect(APP_URL . '/medical-center/appointments.php');
