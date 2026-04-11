<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gallery', 'can_delete');

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$st  = $db->prepare('SELECT * FROM gallery_albums WHERE id = ?');
$st->execute([$id]);
$album = $st->fetch();

if (!$album) {
    flash_set('error', 'Album not found.');
    redirect(APP_URL . '/gallery/index.php');
}

// Fetch all photo filenames so we can delete files
$photos = $db->prepare('SELECT stored_name FROM gallery_photos WHERE album_id = ?');
$photos->execute([$id]);
$photos = $photos->fetchAll();

// Delete files
gallery_delete_image(GAL_UPLOAD_COVERS, $album['cover_photo']);
foreach ($photos as $p) {
    gallery_delete_image(GAL_UPLOAD_PHOTOS, $p['stored_name']);
}

$db->prepare('DELETE FROM gallery_albums WHERE id = ?')->execute([$id]);

log_change('gallery', 'DELETE', $id, $album['title'], null, null, null, "Album '{$album['title']}' deleted.");

flash_set('success', 'Album <strong>' . h($album['title']) . '</strong> and all its photos were deleted.');
redirect(APP_URL . '/gallery/index.php');
