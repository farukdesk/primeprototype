<?php
/**
 * Staff API – GET /api/staff/notifications.php
 * =============================================
 * The employee's announcements inbox. Merges:
 *  1. Broadcast announcements from the "App Notification" module whose
 *     audience includes employees (all users, an employee type, everyone).
 *  2. Announcements individually targeted at this employee (audience "user" /
 *     "group"), resolved through app_notification_recipients when the
 *     recipients migration is applied.
 *  3. The employee's personal in-app notifications (notifications table):
 *     leave request approved / rejected, leave requests awaiting approval, …
 *     so push messages remain readable after the system notification is
 *     dismissed.
 *
 * Every optional table / column is feature-detected so the endpoint keeps
 * working on deployments where a migration has not been applied yet.
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

$ctx = staff_api_auth();
$uid = (int)$ctx['user']['user_id'];

$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

/** Whether a table (and optionally a column) exists – migration tolerant. */
$exists = static function (string $table, ?string $column = null): bool {
    try {
        db()->query('SELECT ' . ($column !== null ? "`$column`" : '1') . " FROM `$table` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
};

$has_audience   = $exists('app_notifications', 'audience');
$has_recipients = $exists('app_notification_recipients');
$has_personal   = $exists('notifications');

// ── Broadcast announcements ───────────────────────────────────────────
// The history table stores a human audience label (see apn_audience_label()),
// e.g. "All users / employees", "Employee type: Faculty" or
// "Everyone (students + users)" – match those labels plus legacy raw codes.
// Without the audience migration every recorded send is treated as broadcast.
$broadcast_where  = "n.status IN ('sent', 'partial')";
$broadcast_params = [];
if ($has_audience) {
    $aud = "(n.audience IN ('users', 'all_users', 'employee_type', 'everyone')
             OR n.audience LIKE 'All users%'
             OR n.audience LIKE 'Employee type%'
             OR n.audience LIKE 'Everyone%')";
    if ($has_recipients) {
        // Individually targeted sends (user / group) are visible to their
        // recorded recipients only.
        $aud = '(' . $aud . " OR EXISTS (
                    SELECT 1 FROM app_notification_recipients r
                     WHERE r.notification_id = n.id
                       AND r.source = 'user'
                       AND r.recipient_user_id = ?))";
        $broadcast_params[] = $uid;
    }
    $broadcast_where .= ' AND ' . $aud;
}

$union_parts  = [
    "SELECT n.id, n.title, n.body, n.url, n.created_at
       FROM app_notifications n
      WHERE {$broadcast_where}",
];
$union_params = $broadcast_params;

// ── Personal notifications (leave decisions, approval requests, …) ──────────
if ($has_personal) {
    $union_parts[]  = 'SELECT p.id, p.title, p.body, p.link AS url, p.created_at
       FROM notifications p
      WHERE p.user_id = ?';
    $union_params[] = $uid;
}

$union = implode(' UNION ALL ', $union_parts);

try {
    $count_stmt = db()->prepare("SELECT COUNT(*) FROM ({$union}) x");
    $count_stmt->execute($union_params);
    $total = (int)$count_stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT * FROM ({$union}) x
          ORDER BY x.created_at DESC, x.id DESC
          LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($union_params, [$limit, $offset]));
    $rows = $stmt->fetchAll();

    api_ok([
        'notifications' => array_map(static function (array $n): array {
            return [
                'id'    => (int)$n['id'],
                'title' => $n['title'],
                'body'  => $n['body'],
                'url'   => $n['url] ?: null,
                'date'  => date('Y-m-d H:i', strtotime($n['created_at'])),
            ];
        }, $rows),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $limit,
    ]);
} catch (Throwable $e) {
    error_log('staff notifications: ' . $e->getMessage());
    api_error(500, 'Failed to load notifications. Please try again.');
}
