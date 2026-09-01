<?php
/**
 * Student Portal API – GET /api/student/notifications.php
 * ========================================================
 * Returns the announcements published from the admin panel's "App
 * Notification" module (see admin/app-notifications/), so students can read
 * every push notification inside the app even after dismissing the system
 * notification.
 *
 * Includes:
 *   • student-wide broadcasts (audience "All students" / "Everyone" / legacy), and
 *   • notifications addressed DIRECTLY to the signed-in student through the
 *     app_notification_recipients log – e.g. the automatic ID card status
 *     updates sent by the ID Card module (admin/id-card/).
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

$ctx = sp_api_auth();
$uid = (int)$ctx['user']['user_id'];

$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

// Audiences that include students; legacy rows (NULL) were student sends.
// The audience column stores a human label (see apn_audience_label()), e.g.
// "All students" or "Everyone (students + users)" – match labels and codes.
// The column is feature-detected so the inbox keeps working (listing every
// sent announcement) on deployments where the audience migration has not
// been applied yet – previously this raised a 500 and the app showed an
// empty inbox.
$has_audience = false;
try {
    db()->query('SELECT `audience` FROM app_notifications LIMIT 1');
    $has_audience = true;
} catch (Throwable $e) {
    // audience column missing (run admin/app-notifications-audience.sql).
}

// Personal notifications (e.g. ID card status updates) are matched through
// the recipients log; feature-detected the same way.
$has_recipients = false;
try {
    db()->query('SELECT notification_id FROM app_notification_recipients LIMIT 1');
    $has_recipients = true;
} catch (Throwable $e) {
    // recipients table missing (run admin/app-notifications-recipients.sql).
}

$where  = "status IN ('sent', 'partial')";
$params = [];
if ($has_audience) {
    $broadcast = "(audience IS NULL
               OR audience IN ('students', 'everyone')
               OR audience LIKE 'All students%'
               OR audience LIKE 'Everyone%')";
    if ($has_recipients) {
        // Broadcasts to students PLUS notifications addressed directly to
        // THIS student (ID card status updates, etc.).
        $where .= " AND ({$broadcast}
               OR EXISTS (SELECT 1 FROM app_notification_recipients r
                           WHERE r.notification_id = app_notifications.id
                             AND r.source = 'student'
                             AND r.recipient_user_id = ?))";
        $params[] = $uid;
    } else {
        $where .= " AND {$broadcast}";
    }
}

try {
    // Only announcements that actually reached devices (skip failed sends).
    $count = db()->prepare("SELECT COUNT(*) FROM app_notifications WHERE {$where}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = db()->prepare(
        "SELECT id, title, body, url, created_at
         FROM app_notifications
         WHERE {$where}
         ORDER BY created_at DESC, id DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$limit, $offset]));
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
