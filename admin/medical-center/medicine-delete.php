<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/medical-center/medicines.php');
}

csrf_check();

if (!mc_can_delete()) {
    flash_set('error', 'Permission denied.');
    redirect(APP_URL . '/medical-center/medicines.php');
}

$id = (int)($_POST['id'] ?? 0);
$db = db();

$med = $db->prepare('SELECT * FROM mc_medicines WHERE id = ?');
$med->execute([$id]);
$med = $med->fetch();

if (!$med) {
    flash_set('error', 'Medicine not found.');
    redirect(APP_URL . '/medical-center/medicines.php');
}

// Soft delete
$db->prepare('UPDATE mc_medicines SET is_active = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
log_change('medical-center', 'DELETE', $id, $med['name'], null, null, null, 'Medicine removed from stock');

flash_set('success', 'Medicine removed successfully.');
redirect(APP_URL . '/medical-center/medicines.php');
