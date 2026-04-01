<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/pages/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$page_id = (int)($_POST['page_id'] ?? 0);
if (!$id || !$page_id) { flash_set('error', 'Invalid request.'); redirect(APP_URL . '/pages/index.php'); }

$stmt = db()->prepare('SELECT * FROM page_profiles WHERE id = ? AND page_id = ?');
$stmt->execute([$id, $page_id]);
$profile = $stmt->fetch();
if (!$profile) { flash_set('error', 'Member not found.'); redirect(APP_URL . '/pages/profiles/index.php?page_id=' . $page_id); }

// Delete photo file
if ($profile['photo']) {
    $ph = UPLOAD_DIR . '/pages/profiles/' . $profile['photo'];
    if (is_file($ph)) @unlink($ph);
}

db()->prepare('DELETE FROM page_profiles WHERE id = ?')->execute([$id]);

flash_set('success', 'Member <strong>' . h($profile['full_name']) . '</strong> removed.');
redirect(APP_URL . '/pages/profiles/index.php?page_id=' . $page_id);
