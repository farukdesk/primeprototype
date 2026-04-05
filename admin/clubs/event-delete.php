<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs', 'can_delete');

$db      = db();
$id      = (int)($_GET['id']      ?? 0);
$club_id = (int)($_GET['club_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM club_events WHERE id = ?');
$stmt->execute([$id]);
$event = $stmt->fetch();

if (!$event) {
    flash_set('error', 'Event not found.');
    redirect(APP_URL . '/clubs/view.php?id=' . $club_id . '#events-tab');
}

clubs_delete_image(CLUB_UPLOAD_EVENTS, $event['cover_photo']);
$db->prepare('DELETE FROM club_events WHERE id = ?')->execute([$id]);

log_change('clubs', 'DELETE', $id, $event['title'], 'event', null, null, "Event '{$event['title']}' deleted from club ID {$event['club_id']}.");

flash_set('success', 'Event deleted.');
redirect(APP_URL . '/clubs/view.php?id=' . $event['club_id'] . '#events-tab');
