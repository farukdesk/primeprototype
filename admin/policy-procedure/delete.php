<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('policy-procedure');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/policy-procedure/index.php');
    exit;
}

csrf_check();

if (!pp_can_delete()) {
    flash('error', 'You do not have permission to delete sections.');
    header('Location: ' . APP_URL . '/policy-procedure/index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    flash('error', 'Invalid section.');
    header('Location: ' . APP_URL . '/policy-procedure/index.php');
    exit;
}

$stmt = db()->prepare('SELECT title FROM policy_procedure_sections WHERE id = ?');
$stmt->execute([$id]);
$section = $stmt->fetch();

if (!$section) {
    flash('error', 'Section not found.');
    header('Location: ' . APP_URL . '/policy-procedure/index.php');
    exit;
}

$stmt = db()->prepare('DELETE FROM policy_procedure_sections WHERE id = ?');
$stmt->execute([$id]);

log_change($_SESSION['user_id'], 'delete', 'Deleted policy section: ' . $section['title']);
flash('success', 'Section "' . $section['title'] . '" deleted successfully.');
header('Location: ' . APP_URL . '/policy-procedure/index.php');
exit;
