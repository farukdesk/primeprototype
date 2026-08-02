<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('user-groups', 'can_delete');
require_once __DIR__ . '/usage.php';

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

// Block deletion when the group is referenced anywhere — even for admins and
// super admins. Covers primary users, member assignments, leave approval
// flows (as requester or approver) and historical approval records.
$usage = ug_group_usage($id);
if (!empty($usage)) {
    flash_set('error', 'Cannot delete “' . $group['name'] . '” — it is still in use: ' . implode('; ', $usage) . '.');
    redirect(APP_URL . '/user-groups/index.php');
}

// Safe to delete: also clean up config rows that would otherwise be orphaned.
foreach ([
    'DELETE FROM group_module_access WHERE group_id = ?',
    'DELETE FROM group_dept_scope WHERE group_id = ?',
    'DELETE FROM leave_flow_priorities WHERE requester_group_id = ?',
] as $cleanup) {
    try {
        db()->prepare($cleanup)->execute([$id]);
    } catch (Throwable $e) {
        // Optional table not installed – ignore.
    }
}

db()->prepare('DELETE FROM user_groups WHERE id = ?')->execute([$id]);
flash_set('success', 'User group deleted.');
redirect(APP_URL . '/user-groups/index.php');
