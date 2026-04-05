<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs', 'can_delete');

$db      = db();
$id      = (int)($_GET['id']      ?? 0);
$club_id = (int)($_GET['club_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM club_activities WHERE id = ? AND club_id = ?');
$stmt->execute([$id, $club_id]);
$act = $stmt->fetch();

if (!$act) {
    flash_set('error', 'Activity not found.');
    redirect(APP_URL . '/clubs/view.php?id=' . $club_id . '#activities');
}

clubs_delete_image(CLUB_UPLOAD_ACTIVITIES, $act['photo']);
$db->prepare('DELETE FROM club_activities WHERE id = ?')->execute([$id]);

log_change('clubs', 'DELETE', $id, $act['title'], 'activity', null, null, "Activity '{$act['title']}' deleted from club ID $club_id.");

flash_set('success', 'Activity deleted.');
redirect(APP_URL . '/clubs/view.php?id=' . $club_id . '#activities');
