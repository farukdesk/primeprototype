<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_access('cms-glance', 'can_delete');
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$row = db()->prepare('SELECT title FROM glance_milestones WHERE id = ?');
$row->execute([$id]);
$row = $row->fetch();

if ($row) {
    db()->prepare('DELETE FROM glance_milestones WHERE id = ?')->execute([$id]);
    log_change('cms-glance', 'DELETE', $id, $row['title'], null, null, null, 'Milestone deleted.');
    flash_set('success', 'Milestone deleted.');
} else {
    flash_set('error', 'Milestone not found.');
}

redirect(APP_URL . '/cms/glance/index.php');
