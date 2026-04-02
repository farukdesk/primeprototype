<?php
require_once __DIR__ . '/helpers.php';
auth_check();
require_access('homepage', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/homepage/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    db()->prepare('DELETE FROM homepage_stats WHERE id = ?')->execute([$id]);
    flash_set('success', 'Stat deleted.');
} else {
    flash_set('error', 'Invalid stat ID.');
}

redirect(APP_URL . '/homepage/index.php');
