<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('notice-signing', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/notice-signing/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id < 1) { flash_set('error', 'Invalid notice.'); redirect(APP_URL . '/notice-signing/index.php'); }

$stmt = db()->prepare('SELECT * FROM notice_documents WHERE id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) { flash_set('error', 'Notice not found.'); redirect(APP_URL . '/notice-signing/index.php'); }

// Delete document file
ns_delete_document($doc['document_file']);

// Cascade delete handled by FK, but delete the row
db()->prepare('DELETE FROM notice_documents WHERE id = ?')->execute([$id]);

log_change('notice-signing', 'DELETE', $id, $doc['title']);
flash_set('success', 'Notice <strong>' . h($doc['title']) . '</strong> deleted.');
redirect(APP_URL . '/notice-signing/index.php');
