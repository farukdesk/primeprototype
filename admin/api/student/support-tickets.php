<?php
/**
 * Student Portal API – GET /api/student/support-tickets.php
 * ===========================================================
 * Lists the signed-in student's IT support tickets (newest first).
 *
 * Query params:
 *   page  = 1, 2, 3 … (default 1)
 *   limit = 50 (default, max 50)
 *
 * Success response:
 *   { "ok": true, "tickets": [...], "total": N, "page": N, "per_page": N }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx     = sp_api_auth();
$user_id = (int)$ctx['user']['user_id'];

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(50, max(1, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

try {
    $cnt = db()->prepare('SELECT COUNT(*) FROM support_tickets WHERE created_by = ?');
    $cnt->execute([$user_id]);
    $total = (int)$cnt->fetchColumn();

    $stmt = db()->prepare(
        'SELECT id, ticket_number, title, description, category, priority, status, deadline, created_at
         FROM support_tickets
         WHERE created_by = ?
         ORDER BY created_at DESC, id DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$user_id, $limit, $offset]);
    $rows = $stmt->fetchAll();

    $tickets = array_map(static function (array $t): array {
        return [
            'id'            => (int)$t['id'],
            'ticket_number' => $t['ticket_number'],
            'title'         => $t['title'],
            'description'   => (string)$t['description'],
            'category'      => $t['category'],
            'priority'      => $t['priority'],
            'status'        => $t['status'],
            'deadline'      => $t['deadline'] ? date('M d, Y H:i', strtotime($t['deadline'])) : null,
            'date'          => date('M d, Y H:i', strtotime($t['created_at'])),
        ];
    }, $rows);

    sp_api_ok([
        'tickets'  => $tickets,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $limit,
    ]);
} catch (Throwable $e) {
    error_log('support-tickets.php error: ' . $e->getMessage());
    sp_api_error(500, 'Failed to load support tickets. Please try again.');
}
