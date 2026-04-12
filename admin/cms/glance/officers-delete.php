<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_access('cms-glance', 'can_delete');
csrf_check();

$id  = (int)($_POST['id'] ?? 0);
$row = db()->prepare('SELECT full_name FROM glance_officers WHERE id = ?');
$row->execute([$id]);
$row = $row->fetch();

if ($row) {
    db()->prepare('DELETE FROM glance_officers WHERE id = ?')->execute([$id]);
    log_change('cms-glance', 'DELETE', $id, $row['full_name'], null, null, null, 'Officer deleted.');
    flash_set('success', 'Officer deleted.');
} else {
    flash_set('error', 'Officer not found.');
}

redirect(APP_URL . '/cms/glance/index.php');
