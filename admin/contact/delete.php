<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('contact', 'can_delete');
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/contact/index.php');
}

$stmt = db()->prepare('SELECT id FROM contact_messages WHERE id = ?');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    flash_set('error', 'Message not found.');
    redirect(APP_URL . '/contact/index.php');
}

db()->prepare('DELETE FROM contact_messages WHERE id = ?')->execute([$id]);

flash_set('success', 'Message deleted successfully.');
redirect(APP_URL . '/contact/index.php');
