<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$user    = auth_user();
$note_id = (int)($_GET['id']      ?? 0);
$lead_id = (int)($_GET['lead_id'] ?? 0);

$lead = leads_get($lead_id);

// Only the author or someone with delete permission can remove a note
$stmt = db()->prepare('SELECT * FROM lead_notes WHERE id = ? AND lead_id = ?');
$stmt->execute([$note_id, $lead_id]);
$note = $stmt->fetch();

if (!$note) {
    flash_set('error', 'Note not found.');
    redirect(APP_URL . '/leads/view.php?id=' . $lead_id . '#notes');
}

$can = leads_can_delete() || (int)($note['user_id'] ?? -1) === $user['id'];
if (!$can) {
    flash_set('error', 'You cannot delete this note.');
    redirect(APP_URL . '/leads/view.php?id=' . $lead_id . '#notes');
}

db()->prepare('DELETE FROM lead_notes WHERE id = ?')->execute([$note_id]);
leads_log($lead_id, 'note_deleted', null, null, null, 'Note deleted by ' . $user['full_name']);

flash_set('success', 'Note deleted.');
redirect(APP_URL . '/leads/view.php?id=' . $lead_id . '#notes');
