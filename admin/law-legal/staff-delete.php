<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('law-legal', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/law-legal/staff-index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

try {
    $row = db()->prepare('SELECT name, photo FROM ll_staff WHERE id = ?');
    $row->execute([$id]);
    $row = $row->fetch();

    if ($row) {
        ll_delete_photo($row['photo'] ?? '');
        db()->prepare('DELETE FROM ll_staff WHERE id = ?')->execute([$id]);
        flash_set('success', 'Staff member <strong>' . h($row['name']) . '</strong> removed.');
    }
} catch (Throwable $e) {
    flash_set('error', 'Could not delete staff member.');
}

redirect(APP_URL . '/law-legal/staff-index.php');
