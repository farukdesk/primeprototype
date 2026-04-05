<?php
/**
 * Shared helpers for the File Manager module.
 */

require_once __DIR__ . '/../includes/auth.php';

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
    if ($mime === 'application/pdf')                                              return 'fas fa-file-pdf text-danger';
    if (str_contains($mime, 'word'))                                              return 'fas fa-file-word text-primary';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet'))      return 'fas fa-file-excel text-success';
    if (str_contains($mime, 'powerpoint') || str_contains($mime, 'presentation')) return 'fas fa-file-powerpoint text-warning';
    if (str_contains($mime, 'zip'))                                               return 'fas fa-file-archive text-secondary';
    if (str_contains($mime, 'text'))                                              return 'fas fa-file-alt text-muted';
    if (str_contains($mime, 'image'))                                             return 'fas fa-file-image text-info';
    return 'fas fa-file text-muted';
}
