<?php
/**
 * Shared helpers for faculty-profiles admin pages.
 */

/**
 * Upload a file for the faculty-profiles module.
 * Returns the generated filename on success, false on failure.
 */
function fp_upload_file(array $file, array $allowed_exts, array $allowed_mimes): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/faculty-profiles';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}
