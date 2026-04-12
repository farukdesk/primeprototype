<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('law-legal', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/law-legal/notice-index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

try {
    db()->prepare('DELETE FROM ll_notices WHERE id = ?')->execute([$id]);
    flash_set('success', 'Notice deleted.');
} catch (Throwable $e) {
    flash_set('error', 'Could not delete notice.');
}

redirect(APP_URL . '/law-legal/notice-index.php');
