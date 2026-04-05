<?php
/**
 * Broadcast Approval Handler
 * Called via POST from admin/cms/pending-changes/index.php
 * Requires super-admin.
 */
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/broadcast/index.php');
}

csrf_check();

$reviewer   = auth_user();
$id         = (int)($_POST['id'] ?? 0);
$action     = $_POST['action'] ?? '';
$review_note = trim($_POST['review_note'] ?? '');

if (!$id || !in_array($action, ['approve', 'reject'], true)) {
    flash_set('danger', 'Invalid request.');
    redirect(APP_URL . '/cms/pending-changes/index.php?module=broadcast');
}

$pdo = db();

// Load the broadcast
$bc = $pdo->prepare(
    'SELECT b.*, ug.name AS group_name, ru.full_name AS user_name
     FROM broadcasts b
     LEFT JOIN user_groups ug ON ug.id = b.recipient_group_id
     LEFT JOIN users      ru ON ru.id = b.recipient_user_id
     WHERE b.id = ?'
);
$bc->execute([$id]);
$broadcast = $bc->fetch();

if (!$broadcast) {
    flash_set('danger', 'Broadcast not found.');
    redirect(APP_URL . '/cms/pending-changes/index.php?module=broadcast');
}

if ($broadcast['status'] !== 'pending_approval') {
    flash_set('warning', 'This broadcast is no longer pending approval.');
    redirect(APP_URL . '/cms/pending-changes/index.php?module=broadcast');
}

// ── Reject ────────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    $pdo->prepare(
        'UPDATE broadcasts
         SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
         WHERE id = ?'
    )->execute(['rejected', $review_note ?: null, $reviewer['id'], $id]);

    flash_set('success', 'Broadcast rejected' . ($review_note ? ' with note.' : '.'));
    redirect(APP_URL . '/cms/pending-changes/index.php?module=broadcast');
}

// ── Approve → send ────────────────────────────────────────────────────────────
$recipients = bc_resolve_recipients(
    $broadcast['recipient_type'],
    $broadcast['recipient_user_id'] ? (int)$broadcast['recipient_user_id'] : null,
    $broadcast['recipient_group_id'] ? (int)$broadcast['recipient_group_id'] : null
);

if (empty($recipients)) {
    flash_set('danger', 'No active recipients found for this broadcast. Cannot approve.');
    redirect(APP_URL . '/cms/pending-changes/index.php?module=broadcast');
}

// Load attachments
$attach_rows = $pdo->prepare('SELECT * FROM broadcast_attachments WHERE broadcast_id = ?');
$attach_rows->execute([$id]);
$attach_rows = $attach_rows->fetchAll();

// Send emails
$result = bc_send_broadcast(
    $id,
    $recipients,
    $broadcast['subject'],
    $broadcast['body_html'],
    $attach_rows
);

$new_status = 'sent';
if ($result['failed'] > 0 && $result['sent'] === 0) $new_status = 'draft';
elseif ($result['failed'] > 0)                       $new_status = 'partial';

$pdo->prepare(
    'UPDATE broadcasts
     SET status = ?, sent_count = ?, failed_count = ?, sent_at = NOW(),
         reviewed_by = ?, reviewed_at = NOW()
     WHERE id = ?'
)->execute([$new_status, $result['sent'], $result['failed'], $reviewer['id'], $id]);

$msg = 'Broadcast approved and sent to ' . $result['sent'] . ' recipient(s).';
if ($result['failed'] > 0) {
    $msg .= ' ' . $result['failed'] . ' delivery failure(s) – check the broadcast log.';
}
flash_set('success', $msg);
redirect(APP_URL . '/cms/pending-changes/index.php?module=broadcast');
