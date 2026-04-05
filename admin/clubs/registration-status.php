<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs', 'can_edit');

$db       = db();
$id       = (int)($_GET['id']       ?? 0);
$event_id = (int)($_GET['event_id'] ?? 0);
$status   = $_GET['status']         ?? '';

if (!in_array($status, ['approved','rejected'], true)) {
    flash_set('error', 'Invalid status.');
    redirect(APP_URL . '/clubs/event-view.php?id=' . $event_id);
}

$stmt = $db->prepare('SELECT * FROM club_event_registrations WHERE id = ? AND event_id = ?');
$stmt->execute([$id, $event_id]);
$reg = $stmt->fetch();

if (!$reg) {
    flash_set('error', 'Registration not found.');
    redirect(APP_URL . '/clubs/event-view.php?id=' . $event_id);
}

$user = auth_user();
$db->prepare(
    'UPDATE club_event_registrations SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?'
)->execute([$status, $user['id'], $id]);

log_change(
    'clubs',
    'UPDATE',
    $id,
    $reg['full_name'],
    'registration_status',
    $reg['status'],
    $status,
    "Event registration #{$id} for '{$reg['full_name']}' set to '$status'."
);

flash_set('success', 'Registration <strong>' . ucfirst($status) . '</strong>.');
redirect(APP_URL . '/clubs/event-view.php?id=' . $event_id);
