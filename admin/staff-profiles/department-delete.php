<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/sp-helpers.php';

if (!sp_can_manage_depts()) {
    require_access('staff-departments', 'can_delete');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    redirect(APP_URL . '/staff-profiles/departments.php');
}

$stmt = db()->prepare('SELECT * FROM staff_departments WHERE id = ?');
$stmt->execute([$id]);
$dept = $stmt->fetch();

if (!$dept) {
    redirect(APP_URL . '/staff-profiles/departments.php');
}

db()->prepare('DELETE FROM staff_departments WHERE id = ?')->execute([$id]);
log_change('staff_departments', $id, 'delete', 'Staff department deleted: ' . $dept['name']);

redirect(APP_URL . '/staff-profiles/departments.php');
