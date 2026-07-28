<?php
/**
 * POST handler: mark one (id) or all (all=1) notifications as read for the
 * logged-in user, then redirect back to a same-site page.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../includes/notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/notifications/index.php');
}
csrf_check();

$user = auth_user();

if (!empty($_POST['all'])) {
    notif_mark_all_read((int)$user['id']);
} elseif (($nid = (int)($_POST['id'] ?? 0)) > 0) {
    notif_mark_read((int)$user['id'], $nid);
}

$back = (string)($_POST['back'] ?? '');
if ($back === '' || (!str_starts_with($back, '/') && !str_starts_with($back, APP_URL))) {
    $back = APP_URL . '/notifications/index.php';
}
redirect($back);
