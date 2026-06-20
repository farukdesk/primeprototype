<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs', 'can_delete');

$db      = db();
$id      = (int)($_GET['id']      ?? 0);
$club_id = (int)($_GET['club_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM club_photos WHERE id = ? AND club_id = ?');
$stmt->execute([$id, $club_id]);
$photo = $stmt->fetch();

if (!$photo) {
    flash_set('error', 'Photo not found.');
    redirect(APP_URL . '/clubs/view.php?id=' . $club_id . '#gallery');
}

clubs_delete_image(CLUB_UPLOAD_GALLERY, $photo['stored_name']);
$db->prepare('DELETE FROM club_photos WHERE id = ?')->execute([$id]);

log_change('clubs', 'DELETE', $id, $photo['caption'] ?? $photo['stored_name'], 'gallery_photo', null, null, "Gallery photo deleted from club ID $club_id.");

flash_set('success', 'Photo deleted.');
redirect(APP_URL . '/clubs/view.php?id=' . $club_id . '#gallery');
