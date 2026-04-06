<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_access('cms-glance', 'can_delete');
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$row = db()->prepare('SELECT name FROM glance_leaders WHERE id = ?');
$row->execute([$id]);
$row = $row->fetch();

if ($row) {
    db()->prepare('DELETE FROM glance_leaders WHERE id = ?')->execute([$id]);
    log_change('cms-glance', 'DELETE', $id, $row['name'], null, null, null, 'Leader deleted.');
    flash_set('success', 'Leader deleted.');
} else {
    flash_set('error', 'Leader not found.');
}

redirect(APP_URL . '/cms/glance/index.php');
