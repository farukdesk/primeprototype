<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('spring-result', 'can_delete');
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    flash_set('error', 'Invalid result ID.');
    redirect(APP_URL . '/spring-result/index.php');
}

$stmt = db()->prepare('SELECT id, title FROM sr_results WHERE id = ?');
$stmt->execute([$id]);
$result = $stmt->fetch();

if (!$result) {
    flash_set('error', 'Result not found.');
    redirect(APP_URL . '/spring-result/index.php');
}

$del = db()->prepare('DELETE FROM sr_results WHERE id = ?');
$del->execute([$id]);

flash_set('success', 'Result <strong>' . h($result['title']) . '</strong> and all its entries have been deleted.');
redirect(APP_URL . '/spring-result/index.php');
