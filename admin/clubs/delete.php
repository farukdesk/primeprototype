<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs', 'can_delete');

$db   = db();
$id   = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM clubs WHERE id = ?');
$stmt->execute([$id]);
$club = $stmt->fetch();

if (!$club) {
    flash_set('error', 'Club not found.');
    redirect(APP_URL . '/clubs/index.php');
}

// Delete gallery images
$photos = $db->prepare('SELECT stored_name FROM club_photos WHERE club_id = ?');
$photos->execute([$id]);
foreach ($photos->fetchAll() as $p) {
    clubs_delete_image(CLUB_UPLOAD_GALLERY, $p['stored_name']);
}

// Delete activity photos
$acts = $db->prepare('SELECT photo FROM club_activities WHERE club_id = ? AND photo IS NOT NULL');
$acts->execute([$id]);
foreach ($acts->fetchAll() as $a) {
    clubs_delete_image(CLUB_UPLOAD_ACTIVITIES, $a['photo']);
}

// Delete event cover photos
$evts = $db->prepare('SELECT cover_photo FROM club_events WHERE club_id = ? AND cover_photo IS NOT NULL');
$evts->execute([$id]);
foreach ($evts->fetchAll() as $e) {
    clubs_delete_image(CLUB_UPLOAD_EVENTS, $e['cover_photo']);
}

// Delete club cover & logo
clubs_delete_image(CLUB_UPLOAD_COVERS, $club['cover_photo']);
clubs_delete_image(CLUB_UPLOAD_LOGOS,  $club['logo']);

// Cascade deletes handled by FK ON DELETE CASCADE
$db->prepare('DELETE FROM clubs WHERE id = ?')->execute([$id]);

log_change('clubs', 'DELETE', $id, $club['name'], null, null, null, "Club '{$club['name']}' and all associated data deleted.");

flash_set('success', 'Club <strong>' . h($club['name']) . '</strong> deleted.');
redirect(APP_URL . '/clubs/index.php');
