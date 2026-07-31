<?php
/**
 * Student Portal API – GET /api/student/notifications.php
 * ========================================================
 * Returns the announcements published from the admin panel's "App
 * Notification" module (see admin/app-notifications/), so students can read
 * every push notification inside the app even after dismissing the system
 * notification.
 *
 * Query params:
 *   page   = 1, 2, 3 … (default 1)
 *   limit  = 50 (default, max 100)
 *
 * Success response:
 *   { "ok": true, "notifications": [...], "total": N, "page": N, "per_page": N }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

sp_api_auth();

$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

// Audiences that include students; legacy rows (NULL) were student sends.
// The audience column stores a human label (see apn_audience_label()), e.g.
// "All students" or "Everyone (students + users)" – match labels and codes.
$where = "status IN ('sent', 'partial')
          AND (audience IS NULL
               OR audience IN ('students', 'everyone')
               OR audience LIKE 'All students%'
               OR audience LIKE 'Everyone%')";

try {
    // Only announcements that actually reached devices (skip failed sends).
    $total = (int)db()->query(
        "SELECT COUNT(*) FROM app_notifications WHERE {$where}"
    )->fetchColumn();

    $stmt = db()->prepare(
        "SELECT id, title, body, url, created_at
         FROM app_notifications
         WHERE {$where}
         ORDER BY created_at DESC, id DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$limit, $offset]);
    $rows = $stmt->fetchAll();

    sp_api_ok([
        'notifications' => array_map(static function (array $n): array {
            return [
                'id'    => (int)$n['id'],
                'title' => $n['title'],
                'body'  => $n['body'],
                'url'   => $n['url'] ?: null,
                'date'  => date('Y-m-d H:i', strtotime($n['created_at'])),
            ];
        }, $rows),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $limit,
    ]);
} catch (Throwable $e) {
    sp_api_error(500, 'Failed to load notifications. Please try again.');
}
