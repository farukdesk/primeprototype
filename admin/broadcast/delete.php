<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('broadcast', 'can_delete');
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash_set('error', 'Invalid broadcast.'); redirect(APP_URL . '/broadcast/index.php'); }

$pdo = db();
$bc  = $pdo->prepare('SELECT id FROM broadcasts WHERE id = ?');
$bc->execute([$id]);
$bc = $bc->fetch();
if (!$bc) { flash_set('error', 'Broadcast not found.'); redirect(APP_URL . '/broadcast/index.php'); }

// Delete stored files
$attachments = $pdo->prepare('SELECT stored_name FROM broadcast_attachments WHERE broadcast_id = ?');
$attachments->execute([$id]);
foreach ($attachments->fetchAll() as $att) {
    $path = UPLOAD_DIR . '/' . BC_UPLOAD_SUBDIR . '/' . $att['stored_name'];
    if (is_file($path)) @unlink($path);
}

// Cascade deletes broadcast_attachments and broadcast_recipients via FK ON DELETE CASCADE
$pdo->prepare('DELETE FROM broadcasts WHERE id = ?')->execute([$id]);

flash_set('success', 'Broadcast deleted.');
redirect(APP_URL . '/broadcast/index.php');
