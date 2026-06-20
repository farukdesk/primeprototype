<?php
/**
 * Shared helpers for the Knowledge Base module.
 */

require_once __DIR__ . '/../includes/auth.php';

// ── File upload constants ─────────────────────────────────────────────────────
define('KB_UPLOAD_SUBDIR', 'knowledge-base');
define('KB_MAX_FILE_SIZE',  52428800); // 50 MB
define('KB_ALLOWED_EXTS',  ['pdf','doc','docx','xls','xlsx','ppt','pptx','zip','txt']);
define('KB_ALLOWED_MIMES', [
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
]);
define('KB_THUMB_EXTS',  ['jpg','jpeg','png','gif','webp']);
define('KB_THUMB_MIMES', ['image/jpeg','image/png','image/gif','image/webp']);

/**
 * Upload a KB file (document attachment or thumbnail) and return the stored filename.
 * Returns false on failure.
 */
function kb_upload_file(array $file, array $allowed_exts, array $allowed_mimes): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > KB_MAX_FILE_SIZE)  return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;

    $dir = UPLOAD_DIR . '/' . KB_UPLOAD_SUBDIR;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return false;

    return $stored;
}

/**
 * Delete a stored KB file if it exists.
 */
function kb_delete_file(?string $filename): void
{
    if (!$filename) return;
    $path = UPLOAD_DIR . '/' . KB_UPLOAD_SUBDIR . '/' . $filename;
    if (is_file($path)) unlink($path);
}

/**
 * Extract a YouTube or Vimeo embed URL from a watch/share URL.
 * Returns the iframe-embeddable URL or the original string unchanged.
 */
function kb_embed_url(string $url): string
{
    $url = trim($url);

    // YouTube: youtu.be/<id>  or  youtube.com/watch?v=<id>
    if (preg_match('/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([a-zA-Z0-9_\-]{11})/', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }

    // Vimeo: vimeo.com/<id>
    if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }

    return $url; // Return as-is (may be a direct embed URL already)
}

/**
 * Return a human-readable file size string.
 */
function kb_format_size(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024)       . ' KB';
    return $bytes . ' B';
}

/**
 * Return an icon class for a MIME type.
 */
function kb_mime_icon(string $mime): string
{
    if ($mime === 'application/pdf')                  return 'fas fa-file-pdf text-danger';
    if (str_contains($mime, 'word'))                  return 'fas fa-file-word text-primary';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) return 'fas fa-file-excel text-success';
    if (str_contains($mime, 'powerpoint') || str_contains($mime, 'presentation')) return 'fas fa-file-powerpoint text-warning';
    if (str_contains($mime, 'zip'))                   return 'fas fa-file-archive text-secondary';
    if (str_contains($mime, 'text'))                  return 'fas fa-file-alt text-muted';
    return 'fas fa-file text-muted';
}
