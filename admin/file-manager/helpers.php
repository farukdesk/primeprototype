<?php
/**
 * Shared helpers for the File Manager module.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

// ── Upload constants ──────────────────────────────────────────────────────────
define('FM_UPLOAD_SUBDIR', 'file-manager');
define('FM_MAX_FILE_SIZE',  52428800); // 50 MB
define('FM_ALLOWED_EXTS',  ['pdf','doc','docx','xls','xlsx','ppt','pptx','zip','txt','jpg','jpeg','png','gif','webp']);
define('FM_ALLOWED_MIMES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip',
    'application/x-zip-compressed',
    'text/plain',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
]);

// ── Permission helpers ────────────────────────────────────────────────────────

function fm_can_manage(): bool
{
    return is_super_admin() || can_access('file-manager', 'can_create');
}

function fm_can_edit(): bool
{
    return is_super_admin() || can_access('file-manager', 'can_edit');
}

function fm_can_delete(): bool
{
    return is_super_admin() || can_access('file-manager', 'can_delete');
}

/**
 * Can the current user see a specific file?
 * Super admin sees all; otherwise must be creator, current holder, or tagged.
 */
function fm_can_view_file(array $file): bool
{
    if (is_super_admin()) return true;
    $user = auth_user();
    if ((int)$file['creator_id'] === (int)$user['id']) return true;
    if (isset($file['current_holder_id']) && (int)$file['current_holder_id'] === (int)$user['id']) return true;
    // Check tagged
    $stmt = db()->prepare(
        'SELECT 1 FROM file_manager_tagged_users WHERE file_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$file['id'], $user['id']]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Build the WHERE clause + params that restrict file list to visible files.
 */
function fm_visibility_where(): array
{
    if (is_super_admin()) {
        return ['1=1', []];
    }
    $user = auth_user();
    $uid  = (int)$user['id'];
    $sql  = '(f.creator_id = ? OR f.current_holder_id = ?
              OR EXISTS (SELECT 1 FROM file_manager_tagged_users fmtu
                         WHERE fmtu.file_id = f.id AND fmtu.user_id = ?))';
    return [$sql, [$uid, $uid, $uid]];
}

// ── File upload ───────────────────────────────────────────────────────────────

/**
 * Upload a file and return the stored filename, or false on failure.
 */
function fm_upload_file(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > FM_MAX_FILE_SIZE)  return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, FM_ALLOWED_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, FM_ALLOWED_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/' . FM_UPLOAD_SUBDIR;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return false;

    return $stored;
}

/**
 * Delete a stored file if it exists.
 */
function fm_delete_file(?string $filename): void
{
    if (!$filename) return;
    $path = UPLOAD_DIR . '/' . FM_UPLOAD_SUBDIR . '/' . $filename;
    if (is_file($path)) @unlink($path);
}

/**
 * Return a human-readable file size string.
 */
function fm_format_size(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024)       . ' KB';
    return $bytes . ' B';
}

/**
 * Return a Font Awesome icon class for a MIME type.
 */
function fm_mime_icon(string $mime): string
{
    if ($mime === 'application/pdf')                                             return 'fas fa-file-pdf text-danger';
    if (str_contains($mime, 'word'))                                             return 'fas fa-file-word text-primary';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet'))     return 'fas fa-file-excel text-success';
    if (str_contains($mime, 'powerpoint') || str_contains($mime, 'presentation')) return 'fas fa-file-powerpoint text-warning';
    if (str_contains($mime, 'zip'))                                              return 'fas fa-file-archive text-secondary';
    if (str_contains($mime, 'text'))                                             return 'fas fa-file-alt text-muted';
    if (str_contains($mime, 'image'))                                            return 'fas fa-file-image text-info';
    return 'fas fa-file text-muted';
}

// ── Initiator auto-fill ───────────────────────────────────────────────────────

/**
 * Return the current user's designation and department name (from faculty_profiles).
 * Returns ['designation' => ..., 'department' => ...] or nulls if not found.
 */
function fm_current_user_profile(): array
{
    $user = auth_user();
    $stmt = db()->prepare(
        'SELECT fp.designation, d.name AS department
         FROM faculty_profiles fp
         LEFT JOIN dept_departments d ON d.id = fp.dept_id
         WHERE fp.user_id = ? LIMIT 1'
    );
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    return [
        'designation' => $row['designation'] ?? null,
        'department'  => $row['department']  ?? null,
    ];
}

// ── Pages ─────────────────────────────────────────────────────────────────────

/**
 * Return all pages for a file, ordered by page_number.
 */
function fm_get_pages(int $file_id): array
{
    $stmt = db()->prepare(
        'SELECT p.*, u.full_name AS creator_name
         FROM file_manager_pages p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE p.file_id = ?
         ORDER BY p.page_number ASC, p.id ASC'
    );
    $stmt->execute([$file_id]);
    return $stmt->fetchAll();
}

/**
 * Return the next available page number for a file.
 */
function fm_next_page_number(int $file_id): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(page_number), 0) + 1 FROM file_manager_pages WHERE file_id = ?');
    $stmt->execute([$file_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Delete a page's uploaded file from disk.
 */
function fm_delete_page_file(?string $filename): void
{
    fm_delete_file($filename); // same upload subdir
}

// ── Transfers ─────────────────────────────────────────────────────────────────

/**
 * Count pending transfer requests directed at the current user.
 */
function fm_pending_transfers_count(): int
{
    $user = auth_user();
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM file_manager_transfers
         WHERE to_user_id = ? AND status = 'pending'"
    );
    $stmt->execute([$user['id']]);
    return (int)$stmt->fetchColumn();
}

/**
 * Send email notification for a transfer request.
 */
function fm_notify_transfer_request(array $transfer, array $file, array $to_user, array $from_user): void
{
    send_template_email('fm_transfer_request', $to_user['email'], $to_user['full_name'], [
        'recipient_name'   => $to_user['full_name'],
        'sender_name'      => $from_user['full_name'],
        'sender_dept'      => $file['initiator_department'] ?? '—',
        'file_name'        => $file['file_name'],
        'file_category'    => $file['category'] ?? '—',
        'transfer_message' => $transfer['message'] ?: '(no message)',
        'transfer_url'     => APP_URL . '/file-manager/transfer.php?id=' . $transfer['id'],
    ]);
}

/**
 * Send email notification when a transfer is accepted.
 */
function fm_notify_transfer_accepted(array $transfer, array $file, array $from_user, array $to_user): void
{
    send_template_email('fm_transfer_accepted', $from_user['email'], $from_user['full_name'], [
        'sender_name'    => $from_user['full_name'],
        'recipient_name' => $to_user['full_name'],
        'file_name'      => $file['file_name'],
        'response_note'  => $transfer['response_note'] ?: '(no note)',
        'file_url'       => APP_URL . '/file-manager/view.php?id=' . $file['id'],
    ]);
}

/**
 * Send email notification when a transfer is rejected.
 */
function fm_notify_transfer_rejected(array $transfer, array $file, array $from_user, array $to_user): void
{
    send_template_email('fm_transfer_rejected', $from_user['email'], $from_user['full_name'], [
        'sender_name'    => $from_user['full_name'],
        'recipient_name' => $to_user['full_name'],
        'file_name'      => $file['file_name'],
        'response_note'  => $transfer['response_note'] ?: '(no reason given)',
        'file_url'       => APP_URL . '/file-manager/view.php?id=' . $file['id'],
    ]);
}

/**
 * Send email notification when a user is tagged on a file.
 */
function fm_notify_tagged(array $file, array $tagged_user, array $tagged_by): void
{
    send_template_email('fm_file_tagged', $tagged_user['email'], $tagged_user['full_name'], [
        'tagged_user_name' => $tagged_user['full_name'],
        'tagged_by_name'   => $tagged_by['full_name'],
        'file_name'        => $file['file_name'],
        'file_category'    => $file['category'] ?? '—',
        'file_url'         => APP_URL . '/file-manager/view.php?id=' . $file['id'],
    ]);
}

/**
 * Send email to a signer when they are requested to sign a note page.
 */
function fm_notify_sign_request(array $file, array $page, array $signer, array $requester): void
{
    send_template_email('fm_sign_request', $signer['email'], $signer['full_name'], [
        'signer_name'    => $signer['full_name'],
        'requester_name' => $requester['full_name'],
        'file_name'      => $file['file_name'],
        'page_subject'   => $page['subject'] ?: '(no subject)',
        'page_number'    => $page['page_number'],
        'sign_url'       => APP_URL . '/file-manager/page-sign.php?page_id=' . $page['id'],
    ]);
}

// ── Sign positions helpers (page-level) ───────────────────────────────────────

/**
 * Return all sign positions for a page, with user and signature status.
 */
function fm_get_page_positions(int $page_id): array
{
    $stmt = db()->prepare(
        'SELECT pos.*, u.full_name, u.email,
                sig.id AS sig_id, sig.signed_at
         FROM file_manager_page_sign_positions pos
         JOIN users u ON u.id = pos.user_id
         LEFT JOIN file_manager_page_signatures sig
               ON sig.page_id = pos.page_id AND sig.user_id = pos.user_id
         WHERE pos.page_id = ?
         ORDER BY pos.sort_order ASC, pos.id ASC'
    );
    $stmt->execute([$page_id]);
    return $stmt->fetchAll();
}

/**
 * Check if the current user needs to sign a page (assigned & not yet signed).
 */
function fm_needs_to_sign_page(int $page_id): bool
{
    $user = auth_user();
    $stmt = db()->prepare(
        'SELECT 1 FROM file_manager_page_sign_positions pos
         WHERE pos.page_id = ? AND pos.user_id = ?
           AND NOT EXISTS (
               SELECT 1 FROM file_manager_page_signatures sig
               WHERE sig.page_id = pos.page_id AND sig.user_id = pos.user_id
           )
         LIMIT 1'
    );
    $stmt->execute([$page_id, $user['id']]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Count pending (unsigned) signers for a page.
 */
function fm_page_pending_signers(int $page_id): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM file_manager_page_sign_positions pos
         WHERE pos.page_id = ?
           AND NOT EXISTS (
               SELECT 1 FROM file_manager_page_signatures sig
               WHERE sig.page_id = pos.page_id AND sig.user_id = pos.user_id
           )'
    );
    $stmt->execute([$page_id]);
    return (int)$stmt->fetchColumn();
}
