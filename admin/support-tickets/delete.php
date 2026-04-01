<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
csrf_check();

$id   = (int)($_POST['id'] ?? 0);
$user = auth_user();

if (!$id) {
    flash_set('error', 'Invalid ticket.');
    redirect(APP_URL . '/support-tickets/index.php');
}

$stmt = db()->prepare('SELECT * FROM support_tickets WHERE id = ?');
$stmt->execute([$id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    flash_set('error', 'Ticket not found.');
    redirect(APP_URL . '/support-tickets/index.php');
}

// Only ticket creator or super admin can delete
if (!is_super_admin() && (int)$ticket['created_by'] !== (int)$user['id']) {
    flash_set('error', 'You do not have permission to delete this ticket.');
    redirect(APP_URL . '/support-tickets/view.php?id=' . $id);
}

// ── Delete physical attachment files ──────────────────────────────────────────
$att_stmt = db()->prepare(
    'SELECT stored_name FROM support_ticket_attachments WHERE ticket_id = ?'
);
$att_stmt->execute([$id]);
foreach ($att_stmt->fetchAll() as $att) {
    $path = UPLOAD_DIR . '/support-tickets/' . $att['stored_name'];
    if (is_file($path)) unlink($path);
}

// ── Delete physical comment attachment files ──────────────────────────────────
$ca_stmt = db()->prepare(
    'SELECT ca.stored_name
     FROM support_ticket_comment_attachments ca
     JOIN support_ticket_comments c ON c.id = ca.comment_id
     WHERE c.ticket_id = ?'
);
$ca_stmt->execute([$id]);
foreach ($ca_stmt->fetchAll() as $ca) {
    $path = UPLOAD_DIR . '/support-tickets/' . $ca['stored_name'];
    if (is_file($path)) unlink($path);
}

// ── Delete ticket (FK cascades handle DB cleanup) ─────────────────────────────
db()->prepare('DELETE FROM support_tickets WHERE id = ?')->execute([$id]);

flash_set('success', 'Ticket <strong>#' . h($ticket['ticket_number']) . '</strong> deleted.');
redirect(APP_URL . '/support-tickets/index.php');
