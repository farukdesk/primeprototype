<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs', 'can_delete');

$db      = db();
$id      = (int)($_GET['id']      ?? 0);
$club_id = (int)($_GET['club_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM club_members WHERE id = ? AND club_id = ?');
$stmt->execute([$id, $club_id]);
$member = $stmt->fetch();

if (!$member) {
    flash_set('error', 'Member not found.');
    redirect(APP_URL . '/clubs/view.php?id=' . $club_id . '#members');
}

$db->prepare('DELETE FROM club_members WHERE id = ?')->execute([$id]);

log_change('clubs', 'DELETE', $id, $member['full_name'], 'member', null, null, "Member '{$member['full_name']}' removed from club ID $club_id.");

flash_set('success', 'Member removed.');
redirect(APP_URL . '/clubs/view.php?id=' . $club_id . '#members');
