<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('users', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/users/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$me = auth_user();

if ($id === (int)$me['id']) {
    flash_set('error', 'You cannot delete your own account.');
    redirect(APP_URL . '/users/index.php');
}

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'User not found.');
    redirect(APP_URL . '/users/index.php');
}

db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
flash_set('success', 'User deleted.');
redirect(APP_URL . '/users/index.php');
