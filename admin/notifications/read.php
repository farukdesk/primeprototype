<?php
/**
 * Mark a notification as read, then jump to its target page (e.g. the leave
 * request approval page). Only same-site links are followed.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../includes/notifications.php';

$user   = auth_user();
$id     = (int)($_GET['id'] ?? 0);
$target = APP_URL . '/notifications/index.php';

if ($id > 0) {
    try {
        $stmt = db()->prepare('SELECT link FROM notifications WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, (int)$user['id']]);
        $row = $stmt->fetch();
        if ($row) {
            notif_mark_read((int)$user['id'], $id);
            $link = (string)($row['link'] ?? '');
            if ($link !== '' && (str_starts_with($link, APP_URL) || str_starts_with($link, '/'))) {
                $target = $link;
            }
        }
    } catch (Throwable $e) {
        // fall through to the notifications list
    }
}

redirect($target);
