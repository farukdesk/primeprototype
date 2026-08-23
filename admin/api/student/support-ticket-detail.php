<?php
/**
 * Student Portal API – GET /api/student/support-ticket-detail.php
 * ==================================================================
 * Returns one of the signed-in student's IT support tickets together with
 * its attachments and public (non-internal) comments.
 *
 * Query params:
 *   id  (required) – ticket id; must belong to the signed-in student
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx     = sp_api_auth();
$user_id = (int)$ctx['user']['user_id'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) sp_api_error(422, 'Ticket id is required.');

try {
    $db = db();

    $stmt = $db->prepare('SELECT * FROM support_tickets WHERE id = ? AND created_by = ? LIMIT 1');
    $stmt->execute([$id, $user_id]);
    $t = $stmt->fetch();
    if (!$t) sp_api_error(404, 'Ticket not found.');

    $base_url = (defined('UPLOAD_URL') ? UPLOAD_URL : '') . '/support-tickets/';

    // ── Ticket attachments ────────────────────────────────────────────────
    $att = $db->prepare(
        'SELECT original_name, stored_name, file_size
         FROM support_ticket_attachments
         WHERE ticket_id = ?
         ORDER BY uploaded_at ASC, id ASC'
    );
    $att->execute([$id]);
    $attachments = array_map(static fn (array $a): array => [
        'name' => (string)$a['original_name'],
        'url'  => $base_url . $a['stored_name'],
        'size' => (int)$a['file_size'],
    ], $att->fetchAll());

    // ── Public comments (internal IT notes are never exposed) ────────────
    $cs = $db->prepare(
        'SELECT c.id, c.comment, c.created_at, c.created_by, u.full_name AS author_name
         FROM support_ticket_comments c
         JOIN users u ON u.id = c.created_by
         WHERE c.ticket_id = ? AND c.is_internal = 0
         ORDER BY c.created_at ASC, c.id ASC'
    );
    $cs->execute([$id]);
    $rows = $cs->fetchAll();

    $catt = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $ca  = $db->prepare(
            "SELECT comment_id, original_name, stored_name, file_size
             FROM support_ticket_comment_attachments
             WHERE comment_id IN ({$ph})"
        );
        $ca->execute($ids);
        foreach ($ca->fetchAll() as $a) {
            $catt[$a['comment_id']][] = [
                'name' => (string)$a['original_name'],
                'url'  => $base_url . $a['stored_name'],
                'size' => (int)$a['file_size'],
            ];
        }
    }

    $comments = array_map(static fn (array $c): array => [
        'id'          => (int)$c['id'],
        'author'      => (string)$c['author_name'],
        'is_own'      => (int)$c['created_by'] === $user_id,
        'comment'     => (string)$c['comment'],
        'date'        => date('M d, Y H:i', strtotime($c['created_at'])),
        'attachments' => $catt[$c['id']] ?? [],
    ], $rows);

    sp_api_ok([
        'ticket' => [
            'id'            => (int)$t['id'],
            'ticket_number' => $t['ticket_number'],
            'title'         => $t['title'],
            'description'   => (string)$t['description'],
            'category'      => $t['category'],
            'priority'      => $t['priority'],
            'status'        => $t['status'],
            'deadline'      => $t['deadline'] ? date('M d, Y H:i', strtotime($t['deadline'])) : null,
            'date'          => date('M d, Y H:i', strtotime($t['created_at'])),
        ],
        'attachments' => $attachments,
        'comments'    => $comments,
    ]);
} catch (Throwable $e) {
    error_log('support-ticket-detail.php error: ' . $e->getMessage());
    sp_api_error(500, 'Failed to load the ticket. Please try again.');
}
