<?php
/**
 * JSON endpoint: unread notification count for the topbar bell badge polling.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$uid = (int)($_SESSION['user_id'] ?? 0);
echo json_encode(['unread' => $uid > 0 ? notif_unread_count($uid) : 0]);
