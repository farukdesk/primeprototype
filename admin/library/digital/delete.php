<?php
require_once __DIR__ . '/../../../includes/auth.php';
auth_check();
require_access('library-digital', 'can_delete');
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/library/digital/index.php');
}

csrf_check();

if (!lib_can_delete()) {
    flash_set('error', 'You do not have permission to delete digital resources.');
    redirect(APP_URL . '/library/digital/index.php');
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Invalid resource ID.');
    redirect(APP_URL . '/library/digital/index.php');
}

$stmt = db()->prepare('SELECT * FROM library_digital_resources WHERE id = ?');
$stmt->execute([$id]);
$resource = $stmt->fetch();

if (!$resource) {
    flash_set('error', 'Resource not found.');
    redirect(APP_URL . '/library/digital/index.php');
}

// Delete files
if ($resource['file_name']) {
    lib_delete_file('digital', $resource['file_name']);
}
if ($resource['cover_image']) {
    lib_delete_file('covers', $resource['cover_image']);
}

// Delete record
db()->prepare('DELETE FROM library_digital_resources WHERE id = ?')->execute([$id]);

lib_audit('DIGITAL_DELETED', 'library-digital', $id, $resource['title'], 'Digital resource deleted: ' . $resource['title']);
log_change('library-digital', 'DELETE', $id, $resource['title'], null, null, null, 'Digital resource deleted: ' . $resource['title']);

flash_set('success', 'Digital resource <strong>' . h($resource['title']) . '</strong> has been deleted.');
redirect(APP_URL . '/library/digital/index.php');
