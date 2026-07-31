<?php
/**
 * Staff API – GET /api/staff/notifications.php
 * =============================================
 * Announcements published from the admin panel's "App Notification" module
 * whose audience includes employees (all users, an employee type, or
 * everyone), so staff can read push notifications inside the app.
 *
 * Individually targeted sends ('user' / 'group') are excluded because the
 * history table does not store the target, so they cannot be scoped safely.
 *
 * Query params:
 *   page   = 1, 2, 3 … (default 1)
 *   limit  = 50 (default, max 100)
 *
 * Success response (mirrors the student endpoint):
 *   { "ok": true, "notifications": [...], "total": N, "page": N, "per_page": N }
 */

require_once __DIR__ . '/includes/auth_staff_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error(405, 'Method Not Allowed. Use GET.');
}

staff_api_auth();

$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

// The history table stores a human audience label (see apn_audience_label()),
// e.g. "All users / employees", "Employee type: Faculty" or
// "Everyone (students + users)" – not a raw code. Match those labels (plus
// legacy raw codes) so employee announcements actually appear in the inbox.
$where = "status IN ('sent', 'partial') AND (
              audience IN ('users', 'all_users', 'employee_type', 'everyone')
           OR audience LIKE 'All users%'
           OR audience LIKE 'Employee type%'
           OR audience LIKE 'Everyone%'
          )";

try {
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

    api_ok([
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
    api_error(500, 'Failed to load notifications. Please try again.');
}
