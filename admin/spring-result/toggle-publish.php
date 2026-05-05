<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('spring-result', 'can_edit');
require_once __DIR__ . '/helpers.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'Invalid result ID.');
    redirect(APP_URL . '/spring-result/index.php');
}

csrf_check();

$stmt = db()->prepare('SELECT id, is_published FROM sr_results WHERE id = ?');
$stmt->execute([$id]);
$result = $stmt->fetch();

if (!$result) {
    flash_set('error', 'Result not found.');
    redirect(APP_URL . '/spring-result/index.php');
}

$new_status = $result['is_published'] ? 0 : 1;
db()->prepare('UPDATE sr_results SET is_published = ? WHERE id = ?')->execute([$new_status, $id]);

flash_set('success', $new_status ? 'Result published successfully.' : 'Result unpublished.');
redirect(APP_URL . '/spring-result/view.php?id=' . $id);
