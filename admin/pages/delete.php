<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/pages/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if (!$id) { flash_set('error', 'Invalid page ID.'); redirect(APP_URL . '/pages/index.php'); }

$stmt = db()->prepare('SELECT * FROM pages WHERE id = ?');
$stmt->execute([$id]);
$page = $stmt->fetch();
if (!$page) { flash_set('error', 'Page not found.'); redirect(APP_URL . '/pages/index.php'); }

// Delete featured image
if ($page['featured_image']) {
    $img_path = UPLOAD_DIR . '/pages/' . $page['featured_image'];
    if (is_file($img_path)) @unlink($img_path);
}

// Delete profile photos
$profiles = db()->prepare('SELECT photo FROM page_profiles WHERE page_id = ?');
$profiles->execute([$id]);
foreach ($profiles->fetchAll() as $pr) {
    if ($pr['photo']) {
        $ph = UPLOAD_DIR . '/pages/profiles/' . $pr['photo'];
        if (is_file($ph)) @unlink($ph);
    }
}

// Delete page (cascades to page_profiles)
db()->prepare('DELETE FROM pages WHERE id = ?')->execute([$id]);

flash_set('success', 'Page <strong>' . h($page['title']) . '</strong> deleted.');
redirect(APP_URL . '/pages/index.php');
