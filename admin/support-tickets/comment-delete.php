<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
csrf_check();

$comment_id = (int)($_POST['comment_id'] ?? 0);
$ticket_id  = (int)($_POST['ticket_id']  ?? 0);
$user       = auth_user();

if (!$comment_id || !$ticket_id) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/support-tickets/index.php');
}

$stmt = db()->prepare('SELECT * FROM support_ticket_comments WHERE id = ?');
$stmt->execute([$comment_id]);
$comment = $stmt->fetch();

if (!$comment) {
    flash_set('error', 'Comment not found.');
    redirect(APP_URL . '/support-tickets/view.php?id=' . $ticket_id . '#comments');
}

// Only the comment author or super admin can delete
if (!is_super_admin() && (int)$comment['created_by'] !== (int)$user['id']) {
    flash_set('error', 'You cannot delete this comment.');
    redirect(APP_URL . '/support-tickets/view.php?id=' . $ticket_id . '#comments');
}

// ── Delete physical comment attachment files ──────────────────────────────────
$ca_stmt = db()->prepare(
    'SELECT stored_name FROM support_ticket_comment_attachments WHERE comment_id = ?'
);
$ca_stmt->execute([$comment_id]);
foreach ($ca_stmt->fetchAll() as $ca) {
    $path = UPLOAD_DIR . '/support-tickets/' . $ca['stored_name'];
    if (is_file($path)) unlink($path);
}

db()->prepare('DELETE FROM support_ticket_comments WHERE id = ?')->execute([$comment_id]);

flash_set('success', 'Comment deleted.');
redirect(APP_URL . '/support-tickets/view.php?id=' . $ticket_id . '#comments');
