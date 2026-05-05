<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('spring-result', 'can_delete');
require_once __DIR__ . '/helpers.php';

$id        = (int)($_GET['id']        ?? 0);
$result_id = (int)($_GET['result_id'] ?? 0);

if ($id <= 0 || $result_id <= 0) {
    flash_set('error', 'Invalid parameters.');
    redirect(APP_URL . '/spring-result/index.php');
}

$stmt = db()->prepare('DELETE FROM sr_result_entries WHERE id = ? AND result_id = ?');
$stmt->execute([$id, $result_id]);

flash_set('success', 'Entry deleted.');
redirect(APP_URL . '/spring-result/view.php?id=' . $result_id);
