<?php
/**
 * IT Support Tickets – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

// ── Allowed attachment types ──────────────────────────────────────────────────
const ST_ATTACH_EXTS = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','zip','txt'];
const ST_ATTACH_MIMES = [
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
const ST_MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

// ── Permission helpers ────────────────────────────────────────────────────────

/**
 * Returns true for super admins and users with can_edit on support-tickets (IT staff).
 */
function st_is_staff(): bool
{
    return is_super_admin() || can_access('support-tickets', 'can_edit');
}

// ── Ticket number generator ───────────────────────────────────────────────────

function st_generate_ticket_number(): string
{
    $year   = date('Y');
    $prefix = 'TKT-' . $year . '-';
    $stmt   = db()->prepare(
        "SELECT ticket_number FROM support_tickets
         WHERE ticket_number LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

// ── SLA helpers ───────────────────────────────────────────────────────────────

function st_get_sla_hours(string $priority): int
{
    $stmt = db()->prepare('SELECT hours FROM support_sla_rules WHERE priority = ?');
    $stmt->execute([$priority]);
    $row = $stmt->fetch();
    return $row ? (int)$row['hours'] : 72;
}

function st_compute_deadline(string $priority): string
{
    $hours = st_get_sla_hours($priority);
    return date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
}

// ── File upload ───────────────────────────────────────────────────────────────

function st_upload_file(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > ST_MAX_FILE_SIZE)  return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ST_ATTACH_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ST_ATTACH_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/support-tickets';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

// ── Badge helpers ─────────────────────────────────────────────────────────────

function st_status_badge(string $status): string
{
    $map = [
        'Open'        => 'bg-primary',
        'In Progress' => 'bg-info text-dark',
        'Pending'     => 'bg-warning text-dark',
        'Resolved'    => 'bg-success',
        'Closed'      => 'bg-secondary',
        'Reopened'    => 'bg-danger',
    ];
    $cls = $map[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($status) . '</span>';
}

function st_priority_badge(string $priority): string
{
    $map = [
        'Low'      => 'bg-success',
        'Medium'   => 'bg-info text-dark',
        'High'     => 'bg-warning text-dark',
        'Critical' => 'bg-danger',
    ];
    $cls = $map[$priority] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($priority) . '</span>';
}

// ── Overdue check ─────────────────────────────────────────────────────────────

function st_is_overdue(array $ticket): bool
{
    if (in_array($ticket['status'], ['Resolved','Closed'], true)) return false;
    if (empty($ticket['deadline'])) return false;
    return strtotime($ticket['deadline']) < time();
}

// ── Misc helpers ──────────────────────────────────────────────────────────────

function st_format_size(int $bytes): string
{
    if ($bytes < 1024)    return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

function st_file_icon(string $ext): string
{
    return match (strtolower($ext)) {
        'pdf'              => 'fas fa-file-pdf text-danger',
        'doc','docx'       => 'fas fa-file-word text-primary',
        'xls','xlsx'       => 'fas fa-file-excel text-success',
        'ppt','pptx'       => 'fas fa-file-powerpoint text-warning',
        'zip'              => 'fas fa-file-archive text-secondary',
        'jpg','jpeg',
        'png','gif','webp' => 'fas fa-file-image text-info',
        'txt'              => 'fas fa-file-alt text-muted',
        default            => 'fas fa-file text-muted',
    };
}

function st_ticket_url(int $id): string
{
    return APP_URL . '/support-tickets/view.php?id=' . $id;
}

// ── Settings helpers ─────────────────────────────────────────────────────────

function st_get_setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $stmt = db()->prepare('SELECT `value` FROM support_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $cache[$key] = ($row !== false ? (string)$row['value'] : $default);
}

function st_set_setting(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO support_settings (`key`, `value`) VALUES (?,?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    )->execute([$key, $value]);
}

/**
 * Returns an array of admin notification email addresses from settings.
 */
function st_get_notify_emails(): array
{
    $raw = st_get_setting('notify_emails', '');
    if ($raw === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

// ── @mention helpers ─────────────────────────────────────────────────────────

/**
 * Extract @username mentions from a comment string.
 * Returns array of lowercase usernames.
 */
function st_extract_mentions(string $text): array
{
    preg_match_all('/@([A-Za-z0-9_\.]+)/', $text, $matches);
    return array_unique(array_map('strtolower', $matches[1] ?? []));
}

/**
 * Render @mentions as highlighted spans (for display in view.php).
 */
function st_render_mentions(string $text): string
{
    return preg_replace(
        '/@([A-Za-z0-9_\.]+)/',
        '<span class="badge bg-info text-dark" style="font-size:.8em;">@$1</span>',
        h($text)
    );
}

// ── Email notifications ───────────────────────────────────────────────────────

function st_notify_ticket_created(array $ticket, array $creator): void
{
    $deadline = $ticket['deadline']
        ? date('M d, Y H:i', strtotime($ticket['deadline']))
        : 'Not set';
    $vars = [
        'full_name'       => $creator['full_name'],
        'ticket_number'   => $ticket['ticket_number'],
        'ticket_title'    => $ticket['title'],
        'ticket_priority' => $ticket['priority'],
        'ticket_category' => $ticket['category'],
        'ticket_deadline' => $deadline,
        'ticket_url'      => st_ticket_url($ticket['id']),
    ];
    send_template_email('ticket_created', $creator['email'], $creator['full_name'], $vars);

    // Also notify configured IT admin emails
    $submitter_name  = $creator['full_name'];
    $submitter_email = $creator['email'];
    $notify_vars = array_merge($vars, [
        'submitter_name'  => $submitter_name,
        'submitter_email' => $submitter_email,
        'user_type'       => $ticket['user_type'] ?? 'N/A',
    ]);
    foreach (st_get_notify_emails() as $admin_email) {
        if ($admin_email === $creator['email']) continue;
        send_template_email('ticket_created_notify', $admin_email, 'IT Support Team', $notify_vars);
    }
}

/**
 * Notify IT admins when a public (guest) ticket is created.
 * Also sends confirmation to the submitter.
 */
function st_notify_public_ticket_created(array $ticket): void
{
    $deadline  = $ticket['deadline']
        ? date('M d, Y H:i', strtotime($ticket['deadline']))
        : 'Not set';
    $track_url = SITE_URL . '/support-ticket.php?track=' . urlencode($ticket['ticket_number']);
    $vars = [
        'full_name'       => $ticket['submitter_name'],
        'ticket_number'   => $ticket['ticket_number'],
        'ticket_title'    => $ticket['title'],
        'ticket_priority' => $ticket['priority'],
        'ticket_category' => $ticket['category'],
        'ticket_deadline' => $deadline,
        'track_url'       => $track_url,
    ];
    // Confirmation to submitter
    if (!empty($ticket['submitter_email'])) {
        send_template_email('ticket_public_confirmation', $ticket['submitter_email'], $ticket['submitter_name'], $vars);
    }
    // Notify IT admins
    $notify_vars = array_merge($vars, [
        'submitter_name'  => $ticket['submitter_name'],
        'submitter_email' => $ticket['submitter_email'] ?? '',
        'user_type'       => $ticket['user_type'] ?? 'N/A',
        'ticket_url'      => APP_URL . '/support-tickets/view.php?id=' . $ticket['id'],
    ]);
    foreach (st_get_notify_emails() as $admin_email) {
        send_template_email('ticket_created_notify', $admin_email, 'IT Support Team', $notify_vars);
    }
}

function st_notify_assigned(array $ticket, array $assignee, array $submitter): void
{
    send_template_email('ticket_assigned', $assignee['email'], $assignee['full_name'], [
        'full_name'       => $assignee['full_name'],
        'ticket_number'   => $ticket['ticket_number'],
        'ticket_title'    => $ticket['title'],
        'ticket_priority' => $ticket['priority'],
        'submitter_name'  => $submitter['full_name'],
        'ticket_url'      => st_ticket_url($ticket['id']),
    ]);
}

function st_notify_status_changed(array $ticket, ?array $creator, string $old_status, string $new_status): void
{
    if ($creator) {
        send_template_email('ticket_status_changed', $creator['email'], $creator['full_name'], [
            'full_name'      => $creator['full_name'],
            'ticket_number'  => $ticket['ticket_number'],
            'ticket_title'   => $ticket['title'],
            'old_status'     => $old_status,
            'new_status'     => $new_status,
            'ticket_url'     => st_ticket_url($ticket['id']),
        ]);
    }

    // Also notify configured IT admin emails
    $skip_email = $creator ? $creator['email'] : '';
    foreach (st_get_notify_emails() as $admin_email) {
        if ($admin_email === $skip_email) continue;
        send_template_email('ticket_status_changed', $admin_email, 'IT Support Team', [
            'full_name'      => 'IT Support Team',
            'ticket_number'  => $ticket['ticket_number'],
            'ticket_title'   => $ticket['title'],
            'old_status'     => $old_status,
            'new_status'     => $new_status,
            'ticket_url'     => st_ticket_url($ticket['id']),
        ]);
    }

    // If public ticket, notify submitter by their stored email
    if (!empty($ticket['submitter_email'])) {
        $track_url = SITE_URL . '/support-ticket.php?track=' . urlencode($ticket['ticket_number']);
        send_template_email('ticket_status_public', $ticket['submitter_email'], $ticket['submitter_name'] ?? 'User', [
            'full_name'      => $ticket['submitter_name'] ?? 'User',
            'ticket_number'  => $ticket['ticket_number'],
            'ticket_title'   => $ticket['title'],
            'old_status'     => $old_status,
            'new_status'     => $new_status,
            'track_url'      => $track_url,
        ]);
    }
}

function st_notify_comment_added(array $ticket, array $recipient, array $commenter, string $comment_text): void
{
    $excerpt = nl2br(h(mb_substr(strip_tags($comment_text), 0, 300)));
    send_template_email('ticket_comment_added', $recipient['email'], $recipient['full_name'], [
        'full_name'       => $recipient['full_name'],
        'ticket_number'   => $ticket['ticket_number'],
        'commenter_name'  => $commenter['full_name'],
        'comment_excerpt' => $excerpt,
        'ticket_url'      => st_ticket_url($ticket['id']),
    ]);
}

/**
 * Notify all configured admin emails when a comment is added (non-internal only).
 * Skips emails that are already receiving the comment_added notification.
 */
function st_notify_comment_to_admins(array $ticket, array $commenter, string $comment_text, array $already_notified_emails = []): void
{
    $excerpt = nl2br(h(mb_substr(strip_tags($comment_text), 0, 300)));
    foreach (st_get_notify_emails() as $admin_email) {
        if (in_array($admin_email, $already_notified_emails, true)) continue;
        if ($admin_email === $commenter['email']) continue;
        send_template_email('ticket_comment_notify', $admin_email, 'IT Support Team', [
            'ticket_number'   => $ticket['ticket_number'],
            'commenter_name'  => $commenter['full_name'],
            'comment_excerpt' => $excerpt,
            'ticket_url'      => st_ticket_url($ticket['id']),
        ]);
    }

    // Also notify public submitter (if different from the commenter)
    if (!empty($ticket['submitter_email']) && $ticket['submitter_email'] !== $commenter['email']
        && !in_array($ticket['submitter_email'], $already_notified_emails, true))
    {
        send_template_email('ticket_comment_added', $ticket['submitter_email'], $ticket['submitter_name'] ?? 'User', [
            'full_name'       => $ticket['submitter_name'] ?? 'User',
            'ticket_number'   => $ticket['ticket_number'],
            'commenter_name'  => $commenter['full_name'],
            'comment_excerpt' => $excerpt,
            'ticket_url'      => SITE_URL . '/support-ticket.php?track=' . urlencode($ticket['ticket_number']),
        ]);
    }
}

/**
 * Detect @username mentions in a comment and notify those users.
 */
function st_notify_mentions(array $ticket, array $commenter, string $comment_text): void
{
    $mentions = st_extract_mentions($comment_text);
    if (empty($mentions)) return;

    $excerpt = nl2br(h(mb_substr(strip_tags($comment_text), 0, 300)));
    foreach ($mentions as $uname) {
        $stmt = db()->prepare(
            'SELECT id, full_name, email, username FROM users WHERE LOWER(username) = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$uname]);
        $mentioned = $stmt->fetch();
        if (!$mentioned) continue;
        if ((int)$mentioned['id'] === (int)$commenter['id']) continue;

        send_template_email('ticket_comment_mention', $mentioned['email'], $mentioned['full_name'], [
            'full_name'       => $mentioned['full_name'],
            'username'        => $mentioned['username'],
            'commenter_name'  => $commenter['full_name'],
            'ticket_number'   => $ticket['ticket_number'],
            'ticket_title'    => $ticket['title'],
            'comment_excerpt' => $excerpt,
            'ticket_url'      => st_ticket_url($ticket['id']),
        ]);
    }
}

function st_notify_tagged(array $ticket, array $tagged_user, array $tagger): void
{
    send_template_email('ticket_tagged', $tagged_user['email'], $tagged_user['full_name'], [
        'full_name'       => $tagged_user['full_name'],
        'ticket_number'   => $ticket['ticket_number'],
        'ticket_title'    => $ticket['title'],
        'ticket_priority' => $ticket['priority'],
        'tagger_name'     => $tagger['full_name'],
        'ticket_url'      => st_ticket_url($ticket['id']),
    ]);
}

// ── Fetch + access-gate a single ticket ──────────────────────────────────────

function st_get_ticket(int $id): array
{
    $stmt = db()->prepare(
        'SELECT t.*,
                COALESCE(u.full_name,  t.submitter_name)  AS creator_name,
                COALESCE(u.email,      t.submitter_email) AS creator_email,
                u.username AS creator_username,
                a.full_name AS assignee_name
         FROM support_tickets t
         LEFT JOIN users u ON u.id = t.created_by
         LEFT JOIN users a ON a.id = t.assigned_to
         WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        flash_set('error', 'Ticket not found.');
        redirect(APP_URL . '/support-tickets/index.php');
    }

    $user = auth_user();
    if (!st_is_staff() && (int)$ticket['created_by'] !== (int)$user['id']) {
        // Also allow access if user is tagged or mentioned
        $tagged = db()->prepare('SELECT 1 FROM support_ticket_user_tags WHERE ticket_id = ? AND user_id = ?');
        $tagged->execute([$ticket['id'], $user['id']]);
        $mentioned = db()->prepare(
            "SELECT 1 FROM support_ticket_comments WHERE ticket_id = ? AND comment LIKE ?"
        );
        $mentioned->execute([$ticket['id'], '%@' . addcslashes($user['username'], '%_') . '%']);
        if (!$tagged->fetch() && !$mentioned->fetch()) {
            flash_set('error', 'You do not have permission to view this ticket.');
            redirect(APP_URL . '/support-tickets/index.php');
        }
    }

    return $ticket;
}

/**
 * Publicly fetch a ticket by ticket_number for tracking (no auth required).
 * Returns false if not found.
 */
function st_get_public_ticket(string $ticket_number): array|false
{
    $stmt = db()->prepare(
        'SELECT t.*,
                COALESCE(u.full_name,  t.submitter_name)  AS creator_name,
                COALESCE(u.email,      t.submitter_email) AS creator_email,
                a.full_name AS assignee_name
         FROM support_tickets t
         LEFT JOIN users u ON u.id = t.created_by
         LEFT JOIN users a ON a.id = t.assigned_to
         WHERE t.ticket_number = ?'
    );
    $stmt->execute([$ticket_number]);
    return $stmt->fetch() ?: false;
}
