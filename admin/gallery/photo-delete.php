<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gallery', 'can_delete');

$db       = db();
$id       = (int)($_GET['id']       ?? 0);
$album_id = (int)($_GET['album_id'] ?? 0);

$st = $db->prepare('SELECT * FROM gallery_photos WHERE id = ?');
$st->execute([$id]);
$photo = $st->fetch();

if (!$photo) {
    flash_set('error', 'Photo not found.');
    redirect(APP_URL . '/gallery/index.php');
}

$back_album = $photo['album_id'];
gallery_delete_image(GAL_UPLOAD_PHOTOS, $photo['stored_name']);
$db->prepare('DELETE FROM gallery_photos WHERE id = ?')->execute([$id]);

log_change('gallery', 'DELETE', $id, 'Photo #' . $id, null, null, null, "Photo deleted from album #$back_album.");

flash_set('success', 'Photo deleted.');
redirect(APP_URL . '/gallery/view.php?id=' . $back_album);
