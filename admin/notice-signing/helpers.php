<?php
/**
 * Shared helpers for the Notice Signing module.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Upload constants ──────────────────────────────────────────────────────────
define('NS_UPLOAD_SUBDIR',  'notice-signing');
define('NS_SIG_SUBDIR',     'signatures');
define('NS_MAX_DOC_SIZE',   20971520); // 20 MB – notice documents
define('NS_MAX_SIG_SIZE',    2097152); //  2 MB – signature PNG
define('NS_DOC_EXTS',   ['pdf','jpg','jpeg','png','gif','webp']);
define('NS_DOC_MIMES',  [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
]);
define('NS_SIG_EXTS',   ['png','jpg','jpeg']);
define('NS_SIG_MIMES',  ['image/png','image/jpeg']);

// ── Permission helpers ────────────────────────────────────────────────────────

function ns_can_manage(): bool
{
    return is_super_admin() || can_access('notice-signing', 'can_create');
}

function ns_can_edit(): bool
{
    return is_super_admin() || can_access('notice-signing', 'can_edit');
}

function ns_can_delete(): bool
{
    return is_super_admin() || can_access('notice-signing', 'can_delete');
}

// ── File upload helpers ───────────────────────────────────────────────────────

/**
 * Upload a notice document (PDF or image).
 */
function ns_upload_document(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > NS_MAX_DOC_SIZE)  return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, NS_DOC_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, NS_DOC_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/' . NS_UPLOAD_SUBDIR;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return false;

    return $stored;
}

/**
 * Upload a user signature image.
 */
function ns_upload_signature(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > NS_MAX_SIG_SIZE)  return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, NS_SIG_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, NS_SIG_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/' . NS_SIG_SUBDIR;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return false;

    return $stored;
}

/**
 * Delete a stored notice document.
 */
function ns_delete_document(?string $filename): void
{
    if (!$filename) return;
    $path = UPLOAD_DIR . '/' . NS_UPLOAD_SUBDIR . '/' . $filename;
    if (is_file($path)) @unlink($path);
}

/**
 * Delete a stored signature image.
 */
function ns_delete_signature(?string $filename): void
{
    if (!$filename) return;
    $path = UPLOAD_DIR . '/' . NS_SIG_SUBDIR . '/' . $filename;
    if (is_file($path)) @unlink($path);
}

// ── Data helpers ──────────────────────────────────────────────────────────────

/**
 * Return all sign positions for a document, with user info and signature status.
 */
function ns_get_positions(int $doc_id): array
{
    $stmt = db()->prepare(
        'SELECT p.*, u.full_name, u.email,
                s.id AS sig_id, s.signed_at, s.ip_address
         FROM notice_sign_positions p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN notice_signatures s ON s.document_id = p.document_id AND s.user_id = p.user_id
         WHERE p.document_id = ?
         ORDER BY p.sort_order, p.id'
    );
    $stmt->execute([$doc_id]);
    return $stmt->fetchAll();
}

/**
 * Return how many signers are still pending for a document.
 */
function ns_pending_count(int $doc_id): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM notice_sign_positions p
         LEFT JOIN notice_signatures s ON s.document_id = p.document_id AND s.user_id = p.user_id
         WHERE p.document_id = ? AND s.id IS NULL'
    );
    $stmt->execute([$doc_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Check if a given user is a required signer for a document and hasn't signed yet.
 */
function ns_needs_to_sign(int $doc_id, int $user_id): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM notice_sign_positions p
         LEFT JOIN notice_signatures s ON s.document_id = p.document_id AND s.user_id = p.user_id
         WHERE p.document_id = ? AND p.user_id = ? AND s.id IS NULL'
    );
    $stmt->execute([$doc_id, $user_id]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Status badge HTML.
 */
function ns_status_badge(string $status): string
{
    return match ($status) {
        'draft'     => '<span class="badge bg-secondary">Draft</span>',
        'active'    => '<span class="badge bg-primary">Active</span>',
        'completed' => '<span class="badge bg-success">Completed</span>',
        default     => '<span class="badge bg-light text-dark">' . h($status) . '</span>',
    };
}

/**
 * Detect document type from mime.
 */
function ns_doc_type_from_file(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return $ext === 'pdf' ? 'pdf' : 'image';
}
