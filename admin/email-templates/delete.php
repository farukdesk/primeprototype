<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('email-templates', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/email-templates/index.php');
}

csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid template.');
    redirect(APP_URL . '/email-templates/index.php');
}

$stmt = db()->prepare('SELECT name FROM email_templates WHERE id = ?');
$stmt->execute([$id]);
$tpl = $stmt->fetch();

if (!$tpl) {
    flash_set('error', 'Email template not found.');
    redirect(APP_URL . '/email-templates/index.php');
}

db()->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$id]);

flash_set('success', "Email template <strong>" . h($tpl['name']) . "</strong> deleted.");
redirect(APP_URL . '/email-templates/index.php');
