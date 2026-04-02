<?php
/**
 * Homepage Management – shared helpers.
 * Included by all files in admin/homepage/.
 */
require_once __DIR__ . '/../includes/auth.php';

/* ── Upload helpers ────────────────────────────────────────────────────────── */
const HP_IMG_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const HP_IMG_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
define('HP_UPLOAD_DIR', UPLOAD_DIR . '/homepage');
define('HP_UPLOAD_URL', UPLOAD_URL . '/homepage');

function hp_upload_photo(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, HP_IMG_EXTS, true)) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if (!in_array($finfo->file($file['tmp_name']), HP_IMG_MIMES, true)) return false;
    if (!is_dir(HP_UPLOAD_DIR)) mkdir(HP_UPLOAD_DIR, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], HP_UPLOAD_DIR . '/' . $name) ? $name : false;
}

function hp_delete_photo(string $filename): void
{
    $path = HP_UPLOAD_DIR . '/' . basename($filename);
    if ($filename && file_exists($path)) @unlink($path);
}
