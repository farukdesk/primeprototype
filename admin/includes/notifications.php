<?php
/**
 * In-app notification helpers — power the bell icon in the admin topbar.
 *
 * Table: notifications (see admin/notifications.sql). Write helpers swallow
 * database errors so a missing table never breaks a page; run the migration
 * to enable the feature.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/** Insert a notification for a single user. */
function notify_user(int $user_id, string $title, ?string $body = null, ?string $link = null, string $type = 'general'): void
{
    if ($user_id < 1 || $title === '') return;
    try {
        db()->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?,?,?,?,?)')
            ->execute([$user_id, $type, substr($title, 0, 190), $body, $link !== null ? substr($link, 0, 255) : null]);
    } catch (Throwable $e) {
        error_log('notify_user: ' . $e->getMessage());
    }
}

/** Insert the same notification for several users. */
function notify_users(array $user_ids, string $title, ?string $body = null, ?string $link = null, string $type = 'general'): void
{
    foreach (array_unique(array_map('intval', $user_ids)) as $uid) {
        notify_user($uid, $title, $body, $link, $type);
    }
}

/** Active member ids of a user group (primary group or multi-group assignment). */
function notif_group_member_ids(int $group_id, int $exclude_user_id = 0): array
{
    if ($group_id < 1) return [];
    try {
        $stmt = db()->prepare(
            'SELECT DISTINCT u.id
               FROM users u
          LEFT JOIN user_group_assignments uga ON uga.user_id = u.id
              WHERE u.is_active = 1 AND (u.group_id = ? OR uga.group_id = ?)'
        );
        $stmt->execute([$group_id, $group_id]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('notif_group_member_ids: ' . $e->getMessage());
        return [];
    }
    return array_values(array_filter($ids, fn ($id) => $id !== $exclude_user_id));
}

/** Notify every active member of a user group (optionally excluding one user). */
function notify_group_members(int $group_id, string $title, ?string $body = null, ?string $link = null, string $type = 'general', int $exclude_user_id = 0): void
{
    notify_users(notif_group_member_ids($group_id, $exclude_user_id), $title, $body, $link, $type);
}

/** Unread notification count (for the bell badge). */
function notif_unread_count(int $user_id): int
{
    if ($user_id < 1) return 0;
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Latest notifications (for the bell dropdown). */
function notif_recent(int $user_id, int $limit = 10): array
{
    if ($user_id < 1) return [];
    try {
        $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, (int)$limit));
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Mark one notification as read (scoped to its owner). */
function notif_mark_read(int $user_id, int $id): void
{
    if ($user_id < 1 || $id < 1) return;
    try {
        db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ? AND is_read = 0')
            ->execute([$id, $user_id]);
    } catch (Throwable $e) {
        error_log('notif_mark_read: ' . $e->getMessage());
    }
}

/** Mark all of a user's notifications as read. */
function notif_mark_all_read(int $user_id): void
{
    if ($user_id < 1) return;
    try {
        db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0')
            ->execute([$user_id]);
    } catch (Throwable $e) {
        error_log('notif_mark_all_read: ' . $e->getMessage());
    }
}

/** Compact "time ago" label for a datetime string. */
function notif_time_ago(string $dt): string
{
    $ts = strtotime($dt);
    if ($ts === false) return $dt;
    $diff = time() - $ts;
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M Y', $ts);
}

/** Short excerpt of a notification body for the dropdown. */
function notif_excerpt(?string $s, int $len = 110): string
{
    $s = trim((string)$s);
    if (function_exists('mb_strimwidth')) return mb_strimwidth($s, 0, $len, '…');
    return strlen($s) > $len ? substr($s, 0, $len - 1) . '…' : $s;
}

/**
 * Send an email using a stored email template (by action slug) when one is
 * configured, otherwise fall back to a built-in HTML email so notification
 * emails work out of the box.
 */
function notify_send_email(string $template_action, string $to_email, string $to_name, array $vars, string $fallback_subject, string $fallback_html): bool
{
    if ($to_email === '' || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) return false;
    try {
        if (send_template_email($template_action, $to_email, $to_name, $vars)) return true;
    } catch (Throwable $e) {
        error_log('notify_send_email(template): ' . $e->getMessage());
    }
    // Fallback: plain HTML email with the same headers as the template mailer.
    $from_email = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: =?UTF-8?B?' . base64_encode(APP_NAME) . '?= <' . $from_email . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $from_email . "\r\n";
    $headers .= 'X-Mailer: PHP/' . PHP_VERSION;
    return @mail($to_email, $fallback_subject, $fallback_html, $headers, '-f' . $from_email);
}
