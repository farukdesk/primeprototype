<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('users', 'can_delete');

// Bring the admin back to the same filtered / paginated Users view.
$return_qs = isset($_POST['return']) ? (string)$_POST['return'] : '';
$return_qs = str_replace(["\r", "\n"], '', $return_qs);
$users_index_url = APP_URL . '/users/index.php' . ($return_qs !== '' ? '?' . $return_qs : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($users_index_url);
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$me = auth_user();

if ($id === (int)$me['id']) {
    flash_set('error', 'You cannot delete your own account.');
    redirect($users_index_url);
}

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'User not found.');
    redirect($users_index_url);
}

db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
flash_set('success', 'User deleted.');
redirect($users_index_url);
