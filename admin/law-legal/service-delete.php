<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('law-legal', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/law-legal/service-index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

try {
    db()->prepare('DELETE FROM ll_services WHERE id = ?')->execute([$id]);
    flash_set('success', 'Service deleted.');
} catch (Throwable $e) {
    flash_set('error', 'Could not delete service.');
}

redirect(APP_URL . '/law-legal/service-index.php');
