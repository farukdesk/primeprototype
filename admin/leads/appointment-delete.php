<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

if (!leads_is_staff()) {
    flash_set('error', 'Permission denied.');
    redirect(APP_URL . '/leads/index.php');
}

$user    = auth_user();
$appt_id = (int)($_GET['id']      ?? 0);
$lead_id = (int)($_GET['lead_id'] ?? 0);

leads_get($lead_id); // validates lead exists

$stmt = db()->prepare('SELECT * FROM lead_appointments WHERE id = ? AND lead_id = ?');
$stmt->execute([$appt_id, $lead_id]);
$appt = $stmt->fetch();

if (!$appt) {
    flash_set('error', 'Appointment not found.');
    redirect(APP_URL . '/leads/view.php?id=' . $lead_id . '#appointments');
}

db()->prepare('DELETE FROM lead_appointments WHERE id = ?')->execute([$appt_id]);
leads_log($lead_id, 'appointment_deleted', null, $appt['appointment_date'], null,
    'Appointment on ' . $appt['appointment_date'] . ' deleted by ' . $user['full_name']);

flash_set('success', 'Appointment deleted.');
redirect(APP_URL . '/leads/view.php?id=' . $lead_id . '#appointments');
