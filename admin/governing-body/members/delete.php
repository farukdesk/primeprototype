<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('governing-body', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/governing-body/index.php');
}

csrf_check();

$id        = (int)($_POST['id'] ?? 0);
$page_type = $_POST['page_type'] ?? '';

$valid_types = ['board-of-trustees', 'pu-syndicates', 'deans', 'head-of-departments'];
if (!$id || !in_array($page_type, $valid_types, true)) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/governing-body/index.php');
}

$stmt = db()->prepare('SELECT * FROM governing_body_members WHERE id = ? AND page_type = ? LIMIT 1');
$stmt->execute([$id, $page_type]);
$member = $stmt->fetch();

if (!$member) {
    flash_set('error', 'Member not found.');
    redirect(APP_URL . '/governing-body/members/index.php?page_type=' . urlencode($page_type));
}

// Remove photo file
if ($member['photo']) {
    $path = UPLOAD_DIR . '/governing-body/' . $member['photo'];
    if (is_file($path)) @unlink($path);
}

db()->prepare('DELETE FROM governing_body_members WHERE id = ?')->execute([$id]);

flash_set('success', 'Member <strong>' . h($member['full_name']) . '</strong> removed.');
redirect(APP_URL . '/governing-body/members/index.php?page_type=' . urlencode($page_type));
