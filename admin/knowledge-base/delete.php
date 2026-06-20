<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_super_admin();
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/knowledge-base/index.php');
}

csrf_check();

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/knowledge-base/index.php');
}

$stmt = db()->prepare('SELECT * FROM kb_articles WHERE id = ?');
$stmt->execute([$id]);
$article = $stmt->fetch();

if (!$article) {
    flash_set('error', 'Article not found.');
    redirect(APP_URL . '/knowledge-base/index.php');
}

// Delete uploaded files
kb_delete_file($article['thumbnail']);
kb_delete_file($article['file_name']);

db()->prepare('DELETE FROM kb_articles WHERE id = ?')->execute([$id]);

flash_set('success', 'Article <strong>' . h($article['title']) . '</strong> deleted.');
redirect(APP_URL . '/knowledge-base/index.php');
