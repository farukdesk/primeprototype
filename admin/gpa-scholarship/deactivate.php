<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gpa-scholarship', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/gpa-scholarship/index.php');
}

csrf_check();
$id = (int)($_POST['id'] ?? 0);
$config = gs_get_config($id);
if (!$config) {
    flash_set('error', 'Setup not found.');
    redirect(APP_URL . '/gpa-scholarship/index.php');
}
if (!can_access_dept((int)$config['dept_id']) && !is_super_admin()) {
    flash_set('error', 'Access denied.');
    redirect(APP_URL . '/gpa-scholarship/index.php');
}

db()->prepare('UPDATE gpa_scholarship_configs SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?')
   ->execute([(int)auth_user()['id'], $id]);

flash_set('success', 'Setup deactivated. Existing audit history remains intact.');
redirect(APP_URL . '/gpa-scholarship/index.php');
