<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/hall-management/index.php');
}
csrf_check();

$id   = (int)($_POST['id'] ?? 0);
$hall = $id > 0 ? hm_get_hall($id) : null;

if ($hall) {
    // Columns cascade-delete with the hall (fk_hmc_hall).
    db()->prepare('DELETE FROM hm_halls WHERE id = ?')->execute([$id]);
    flash_set('success', 'Hall "' . $hall['room_number'] . '" deleted.');
} else {
    flash_set('error', 'Hall not found or you do not have permission to delete it.');
}
redirect(APP_URL . '/hall-management/index.php');
