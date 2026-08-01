<?php
/**
 * Staff API – GET /api/staff/notifications.php
 * =============================================
 * Announcements published from the admin panel's "App Notification" module
 * whose audience includes this employee: broadcast audiences (all users,
 * all employees, an employee type, or everyone) plus notifications that
 * targeted this user individually or via their user group (using the
 * targeting stored since the resend migration and the recipient log).
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
$gid = staff_user_group_id($uid);

$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

// The history table stores a human audience label (see apn_audience_label()),
// e.g. "All users / employees", "All employees (administrative + faculty)",
// "Employee type: Faculty" or "Everyone (students + users)" – not a raw code.
// Match those labels (plus legacy raw codes) so employee announcements
// actually appear in the inbox.
$broadcast = "audience IN ('users', 'all_users', 'all_employees', 'employee_type', 'everyone')
           OR audience LIKE 'All users%'
           OR audience LIKE 'All employees%'
           OR audience LIKE 'Employee type%'
           OR audience LIKE 'Everyone%'";

// Preferred filter: broadcasts + notifications targeted at this user
// (individually, via their group, or logged as a recipient). The extra
// columns/table come from the app-notifications-resend.sql and
// app-notifications-recipients.sql migrations.
$whereTargeted = "status IN ('sent', 'partial') AND (
              {$broadcast}
           OR (audience_code = 'user'  AND target_user_id  = ?)
           OR (audience_code = 'group' AND target_group_id = ?)
           OR EXISTS (SELECT 1 FROM app_notification_recipients r
                       WHERE r.notification_id = app_notifications.id
                         AND r.source = 'user'
                         AND r.recipient_user_id = ?)
          )";

// Fallback for deployments where those migrations are not applied yet.
$whereLegacy = "status IN ('sent', 'partial') AND ({$broadcast})";

$attempts = [
    ['where' => $whereTargeted, 'params' => [$uid, $gid, $uid]],
    ['where' => $whereLegacy,   'params' => []],
];

$lastError = null;
foreach ($attempts as $attempt) {
    try {
        $countStmt = db()->prepare(
            "SELECT COUNT(*) FROM app_notifications WHERE {$attempt['where']}"
        );
        $countStmt->execute($attempt['params']);
        $total = (int)$countStmt->fetchColumn();

        $stmt = db()->prepare(
            "SELECT id, title, body, url, created_at
             FROM app_notifications
             WHERE {$attempt['where']}
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($attempt['params'], [$limit, $offset]));
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
        exit;
    } catch (Throwable $e) {
        // Missing column/table (migration not applied) – try the next filter.
        $lastError = $e;
    }
}

api_error(500, 'Failed to load notifications. Please try again.');
