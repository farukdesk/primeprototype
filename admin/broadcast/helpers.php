<?php
/**
 * Broadcast Module – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';

// ── Allowed attachment types ──────────────────────────────────────────────────
const BC_ATTACH_EXTS = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','zip','txt'];
const BC_ATTACH_MIMES = [
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
const BC_MAX_FILE_SIZE  = 5 * 1024 * 1024;  // 5 MB per file
const BC_MAX_TOTAL_SIZE = 20 * 1024 * 1024; // 20 MB total
const BC_UPLOAD_SUBDIR  = 'broadcast';

// ── Permission helpers ────────────────────────────────────────────────────────

/**
 * Returns true for super admins and users with can_edit on broadcast.
 */
function bc_is_staff(): bool
{
    return is_super_admin() || can_access('broadcast', 'can_edit');
}

// ── File upload ───────────────────────────────────────────────────────────────

/**
 * Upload a single attachment file.
 * Returns stored filename on success, false on failure.
 */
function bc_upload_file(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > BC_MAX_FILE_SIZE)  return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, BC_ATTACH_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, BC_ATTACH_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/' . BC_UPLOAD_SUBDIR;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return false;

    return $stored;
}

// ── Recipient resolution ──────────────────────────────────────────────────────

/**
 * Resolve the list of recipient rows [id, email, full_name] for a broadcast.
 *
 * @param  string      $type             'individual' | 'group' | 'all' | 'students'
 * @param  int|null    $user_id          required when $type === 'individual'
 * @param  int|null    $group_id         required when $type === 'group'
 * @param  int|null    $student_dept_id    optional dept filter for 'students'
 * @param  int|null    $student_program_id optional program filter for 'students'
 * @param  string|null $student_status     optional status filter for 'students' (Active/Inactive/Graduated/Dropped)
 * @param  string|null $student_semester   optional admitted_semester filter for 'students'
 * @return array[]
 */
function bc_resolve_recipients(
    string  $type,
    ?int    $user_id,
    ?int    $group_id,
    ?int    $student_dept_id    = null,
    ?int    $student_program_id = null,
    ?string $student_status     = null,
    ?string $student_semester   = null
): array {
    $pdo = db();

    if ($type === 'individual' && $user_id) {
        $stmt = $pdo->prepare(
            'SELECT id, email, full_name FROM users WHERE id = ? AND is_active = 1 AND email != "" LIMIT 1'
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }

    if ($type === 'group' && $group_id) {
        $stmt = $pdo->prepare(
            'SELECT id, email, full_name FROM users WHERE group_id = ? AND is_active = 1 AND email != "" ORDER BY full_name'
        );
        $stmt->execute([$group_id]);
        return $stmt->fetchAll();
    }

    if ($type === 'students') {
        $where  = ["s.email IS NOT NULL AND s.email != ''"];
        $params = [];

        if ($student_dept_id) {
            $where[]  = 's.dept_id = ?';
            $params[] = $student_dept_id;
        }
        if ($student_program_id) {
            $where[]  = 's.program_id = ?';
            $params[] = $student_program_id;
        }
        $valid_statuses = ['Active', 'Inactive', 'Graduated', 'Dropped'];
        if ($student_status && in_array($student_status, $valid_statuses, true)) {
            $where[]  = 's.status = ?';
            $params[] = $student_status;
        }
        if ($student_semester) {
            $where[]  = 's.admitted_semester = ?';
            $params[] = $student_semester;
        }

        $sql  = 'SELECT NULL AS id, s.email, s.full_name FROM students s'; // id is NULL: students don't have user accounts
        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY s.full_name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // 'all'
    return $pdo->query(
        'SELECT id, email, full_name FROM users WHERE is_active = 1 AND email != "" ORDER BY full_name'
    )->fetchAll();
}

// ── Email sender ──────────────────────────────────────────────────────────────

/**
 * Send an HTML email with optional file attachments via PHP mail().
 *
 * @param  string   $to_email
 * @param  string   $to_name
 * @param  string   $subject
 * @param  string   $body_html
 * @param  array[]  $attachments  [['path'=>..., 'name'=>..., 'mime'=>...], ...]
 * @return bool
 */
function bc_send_email(
    string $to_email,
    string $to_name,
    string $subject,
    string $body_html,
    array  $attachments = []
): bool {
    $from_email    = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@primeuniversity.ac.bd';
    $from_name_enc = '=?UTF-8?B?' . base64_encode(APP_NAME) . '?=';
    $to_enc        = '=?UTF-8?B?' . base64_encode($to_name) . '?= <' . $to_email . '>';
    $boundary      = 'BC_' . bin2hex(random_bytes(12));

    if (empty($attachments)) {
        // Simple HTML-only message
        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $headers .= 'From: ' . $from_name_enc . ' <' . $from_email . '>' . "\r\n";
        $headers .= 'Reply-To: ' . $from_email . "\r\n";
        $headers .= 'X-Mailer: PHP/' . PHP_VERSION;

        return mail($to_email, $subject, $body_html, $headers, '-f' . escapeshellarg($from_email));
    }

    // Multipart message with attachments
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
    $headers .= 'From: ' . $from_name_enc . ' <' . $from_email . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $from_email . "\r\n";
    $headers .= 'X-Mailer: PHP/' . PHP_VERSION;

    $body  = '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
    $body .= chunk_split(base64_encode($body_html)) . "\r\n";

    foreach ($attachments as $att) {
        if (!is_readable($att['path'])) continue;
        $data = file_get_contents($att['path']);
        if ($data === false) continue;

        $name_enc = '=?UTF-8?B?' . base64_encode($att['name']) . '?=';

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: ' . $att['mime'] . '; name="' . $name_enc . '"' . "\r\n";
        $body .= 'Content-Transfer-Encoding: base64' . "\r\n";
        $body .= 'Content-Disposition: attachment; filename="' . $name_enc . '"' . "\r\n\r\n";
        $body .= chunk_split(base64_encode($data)) . "\r\n";
    }

    $body .= '--' . $boundary . '--';

    return mail($to_email, $subject, $body, $headers, '-f' . escapeshellarg($from_email));
}

// ── Broadcast sender ──────────────────────────────────────────────────────────

/**
 * Send a broadcast to all resolved recipients and record results.
 *
 * @param  int    $broadcast_id  Already-inserted broadcast row id
 * @param  array  $recipients    Output of bc_resolve_recipients()
 * @param  string $subject
 * @param  string $body_html
 * @param  array  $attach_rows   Rows from broadcast_attachments for this broadcast
 * @return array{sent: int, failed: int}
 */
function bc_send_broadcast(int $broadcast_id, array $recipients, string $subject, string $body_html, array $attach_rows): array
{
    $pdo  = db();
    $sent = 0;
    $fail = 0;

    // Build attachment path list
    $attachments = [];
    foreach ($attach_rows as $a) {
        $path = UPLOAD_DIR . '/' . BC_UPLOAD_SUBDIR . '/' . $a['stored_name'];
        if (is_readable($path)) {
            $attachments[] = [
                'path' => $path,
                'name' => $a['original_name'],
                'mime' => $a['mime_type'],
            ];
        }
    }

    $ins = $pdo->prepare(
        'INSERT INTO broadcast_recipients (broadcast_id, user_id, email, full_name, status) VALUES (?,?,?,?,?)'
    );

    foreach ($recipients as $r) {
        // Personalise greeting in body (insert as plain text into existing HTML body)
        $personalised = str_replace('{{full_name}}', $r['full_name'], $body_html);

        $ok = bc_send_email($r['email'], $r['full_name'], $subject, $personalised, $attachments);
        $ins->execute([
            $broadcast_id,
            $r['id'] ?? null,
            $r['email'],
            $r['full_name'],
            $ok ? 'sent' : 'failed',
        ]);
        $ok ? $sent++ : $fail++;
    }

    return ['sent' => $sent, 'failed' => $fail];
}

// ── Misc helpers ──────────────────────────────────────────────────────────────

function bc_recipient_label(array $broadcast): string
{
    if ($broadcast['recipient_type'] === 'all') return 'All Users';
    if ($broadcast['recipient_type'] === 'group') {
        return 'Group: ' . h($broadcast['group_name'] ?? '—');
    }
    if ($broadcast['recipient_type'] === 'students') {
        $parts = [];
        if (!empty($broadcast['dept_name'])) {
            $parts[] = h($broadcast['dept_name']);
        }
        if (!empty($broadcast['program_name'])) {
            $parts[] = h($broadcast['program_name']);
        }
        if (!empty($broadcast['student_status'])) {
            $parts[] = h($broadcast['student_status']);
        }
        if (!empty($broadcast['student_semester'])) {
            $parts[] = h($broadcast['student_semester']);
        }
        return 'Students' . ($parts ? ': ' . implode(', ', $parts) : ' (All)');
    }
    return 'User: ' . h($broadcast['user_name'] ?? '—');
}

function bc_status_badge(string $status): string
{
    $map = [
        'draft'            => ['secondary', 'Draft'],
        'sent'             => ['success',   'Sent'],
        'partial'          => ['warning',   'Partial'],
        'pending_approval' => ['info',      '<i class="fas fa-clock me-1"></i>Pending Approval'],
        'rejected'         => ['danger',    'Rejected'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', ucfirst($status)];
    return '<span class="badge bg-' . $cls . '">' . $label . '</span>';
}

function bc_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024)       . ' KB';
    return $bytes . ' B';
}
