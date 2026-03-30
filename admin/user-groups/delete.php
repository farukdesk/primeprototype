<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('user-groups', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/user-groups/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM user_groups WHERE id = ?');
$stmt->execute([$id]);
$group = $stmt->fetch();

if (!$group) {
    flash_set('error', 'Group not found.');
    redirect(APP_URL . '/user-groups/index.php');
}

if ($group['is_super']) {
    flash_set('error', 'The Super Admin group cannot be deleted.');
    redirect(APP_URL . '/user-groups/index.php');
}

// Check if group has users
$count = db()->prepare('SELECT COUNT(*) FROM users WHERE group_id = ?');
$count->execute([$id]);
if ((int)$count->fetchColumn() > 0) {
    flash_set('error', 'Cannot delete group: reassign or remove its users first.');
    redirect(APP_URL . '/user-groups/index.php');
}

db()->prepare('DELETE FROM user_groups WHERE id = ?')->execute([$id]);
flash_set('success', 'User group deleted.');
redirect(APP_URL . '/user-groups/index.php');
