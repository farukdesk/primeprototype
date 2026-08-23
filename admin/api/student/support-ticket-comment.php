<?php
/**
 * Student Portal API – POST /api/student/support-ticket-comment.php
 * ===================================================================
 * Adds a public comment (with optional attachments) to one of the
 * signed-in student's own IT support tickets. IT staff are notified by
 * push (FCM HTTP v1) and email, mirroring the web portal behaviour.
 *
 * POST fields:
 *   ticket_id      (required)
 *   comment        (required, plain text, max 5000 chars)
 *   attachments[]  (optional files – 10 MB max each, safe-listed types)
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sp_api_error(405, 'Method Not Allowed. Use POST.');
}

$ctx     = sp_api_auth();
$user    = $ctx['user'];
$user_id = (int)$user['user_id'];

$ticket_id = (int)($_POST['ticket_id'] ?? 0);
$comment   = trim((string)($_POST['comment'] ?? ''));

if ($ticket_id <= 0)            sp_api_error(422, 'ticket_id is required.');
if ($comment === '')            sp_api_error(422, 'Comment cannot be empty.');
if (mb_strlen($comment) > 5000) sp_api_error(422, 'Comment must be 5000 characters or less.');

try {
    $db = db();

    $stmt = $db->prepare('SELECT * FROM support_tickets WHERE id = ? AND created_by = ? LIMIT 1');
    $stmt->execute([$ticket_id, $user_id]);
    $ticket = $stmt->fetch();
    if (!$ticket) sp_api_error(404, 'Ticket not found.');

    $db->prepare(
        'INSERT INTO support_ticket_comments (ticket_id, comment, is_internal, created_by)
         VALUES (?,?,0,?)'
    )->execute([$ticket_id, $comment, $user_id]);
    $comment_id = (int)$db->lastInsertId();

    // ── Attachments (optional) ────────────────────────────────────────────
    $allowed_exts  = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','zip','txt'];
    $allowed_mimes = [
        'image/jpeg','image/png','image/gif','image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip','application/x-zip-compressed',
        'text/plain',
    ];
    $base_url = (defined('UPLOAD_URL') ? UPLOAD_URL : '') . '/support-tickets/';
    $saved_attachments = [];
    if (!empty($_FILES['attachments']['name'][0])) {
        $dir = UPLOAD_DIR . '/support-tickets';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        foreach ($_FILES['attachments']['tmp_name'] as $i => $tmp) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ((int)$_FILES['attachments']['size'][$i] > 10 * 1024 * 1024) continue;
            $orig = (string)$_FILES['attachments']['name'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts, true)) continue;
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = (string)$finfo->file($tmp);
            if (!in_array($mime, $allowed_mimes, true)) continue;
            $stored = bin2hex(random_bytes(12)) . '.' . $ext;
            if (!move_uploaded_file($tmp, $dir . '/' . $stored)) continue;
            $db->prepare(
                'INSERT INTO support_ticket_comment_attachments
                   (comment_id, original_name, stored_name, mime_type, file_size)
                 VALUES (?,?,?,?,?)'
            )->execute([$comment_id, $orig, $stored, $mime, (int)$_FILES['attachments']['size'][$i]]);
            $saved_attachments[] = [
                'name' => $orig,
                'url'  => $base_url . $stored,
                'size' => (int)$_FILES['attachments']['size'][$i],
            ];
        }
    }

    $db->prepare('UPDATE support_tickets SET updated_at = NOW() WHERE id = ?')->execute([$ticket_id]);

    // ── Best-effort push notification to IT staff (FCM HTTP v1) ──────────
    try {
        require_once dirname(__DIR__, 2) . '/app-notifications/helpers.php';
        $sa     = apn_fcm_service_account();
        $access = $sa !== null ? apn_fcm_access_token($sa) : null;
        if ($sa !== null && $access !== null) {
            if (!empty($ticket['assigned_to'])) {
                $staff_ids = [(int)$ticket['assigned_to']];
            } else {
                $staff_ids = $db->query(
                    "SELECT DISTINCT u.id FROM users u
                     JOIN user_groups g ON g.id = u.group_id
                     WHERE u.is_active = 1 AND (g.is_super = 1
                           OR u.id IN (
                               SELECT uma.user_id FROM user_module_access uma
                               JOIN modules m ON m.id = uma.module_id
                               WHERE m.slug = 'support-tickets' AND uma.can_view = 1
                           )
                           OR g.id IN (
                               SELECT gma.group_id FROM group_module_access gma
                               JOIN modules m ON m.id = gma.module_id
                               WHERE m.slug = 'support-tickets' AND gma.can_view = 1
                           )
                     )"
                )->fetchAll(PDO::FETCH_COLUMN);
            }
            $staff_ids = array_values(array_unique(array_map('intval', $staff_ids)));
            if ($staff_ids) {
                $ph  = implode(',', array_fill(0, count($staff_ids), '?'));
                $tok = $db->prepare(
                    "SELECT DISTINCT fcm_token FROM api_push_tokens
                     WHERE user_id IN ({$ph}) AND fcm_token IS NOT NULL AND fcm_token != ''"
                );
                $tok->execute($staff_ids);
                foreach ($tok->fetchAll(PDO::FETCH_COLUMN) as $fcm_token) {
                    apn_fcm_send_single(
                        $access, $sa['project_id'], $fcm_token,
                        'New reply on ' . $ticket['ticket_number'],
                        $user['full_name'] . ': ' . mb_substr($comment, 0, 150),
                        ['type' => 'support_ticket', 'ticket_id' => (string)$ticket_id, 'ticket_number' => $ticket['ticket_number']]
                    );
                }
            }
        }
    } catch (Throwable $e) {
        error_log('support-ticket-comment.php push failed: ' . $e->getMessage());
    }

    // ── Best-effort email notifications ──────────────────────────────────
    try {
        if (!function_exists('h')) {
            function h(mixed $val): string {
                return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        require_once dirname(__DIR__, 2) . '/includes/mailer.php';
        if (function_exists('send_template_email')) {
            $excerpt    = nl2br(h(mb_substr($comment, 0, 300)));
            $ticket_url = (defined('APP_URL') ? APP_URL : '') . '/support-tickets/view.php?id=' . $ticket_id;
            $already    = [(string)($user['email'] ?? '')];

            if (!empty($ticket['assigned_to'])) {
                $as = $db->prepare('SELECT full_name, email FROM users WHERE id = ?');
                $as->execute([(int)$ticket['assigned_to']]);
                if ($assignee = $as->fetch()) {
                    send_template_email('ticket_comment_added', $assignee['email'], $assignee['full_name'], [
                        'full_name'       => $assignee['full_name'],
                        'ticket_number'   => $ticket['ticket_number'],
                        'commenter_name'  => $user['full_name'],
                        'comment_excerpt' => $excerpt,
                        'ticket_url'      => $ticket_url,
                    ]);
                    $already[] = (string)$assignee['email'];
                }
            }

            $ns = $db->prepare('SELECT `value` FROM support_settings WHERE `key` = ? LIMIT 1');
            $ns->execute(['notify_emails']);
            foreach (array_filter(array_map('trim', explode(',', (string)($ns->fetchColumn() ?: '')))) as $admin_email) {
                if (in_array($admin_email, $already, true)) continue;
                send_template_email('ticket_comment_notify', $admin_email, 'IT Support Team', [
                    'ticket_number'   => $ticket['ticket_number'],
                    'commenter_name'  => $user['full_name'],
                    'comment_excerpt' => $excerpt,
                    'ticket_url'      => $ticket_url,
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('support-ticket-comment.php email failed: ' . $e->getMessage());
    }

    sp_api_ok([
        'message' => 'Comment posted.',
        'comment' => [
            'id'          => $comment_id,
            'author'      => $user['full_name'],
            'is_own'      => true,
            'comment'     => $comment,
            'date'        => date('M d, Y H:i'),
            'attachments' => $saved_attachments,
        ],
    ]);
} catch (Throwable $e) {
    error_log('support-ticket-comment.php error: ' . $e->getMessage());
    sp_api_error(500, 'Failed to post the comment. Please try again.');
}
