<?php
/**
 * ID Card – Delete (POST only)
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('id-card', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/id-card/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    $st = db()->prepare('DELETE FROM idc_cards WHERE id = ?');
    $st->execute([$id]);
    flash_set($st->rowCount() ? 'success' : 'warning',
              $st->rowCount() ? 'ID card deleted.' : 'ID card not found.');
}
redirect(APP_URL . '/id-card/index.php');
