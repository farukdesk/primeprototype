<?php
/**
 * Shared helpers for the staff-profiles admin module.
 */

require_once __DIR__ . '/../change-log/helpers.php';

// ── Allowed photo types ───────────────────────────────────────────────────────
const SP_PHOTO_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const SP_PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const SP_PHOTO_MAX   = 5 * 1024 * 1024; // 5 MB

// ── Permission helpers ────────────────────────────────────────────────────────

/**
 * Returns true if the current user can manage staff profiles (admin functions).
 */
function sp_is_admin(): bool
{
    return is_super_admin() || can_access('staff-profile', 'can_edit');
}

/**
 * Returns true if the current user can manage the staff department list.
 */
function sp_can_manage_depts(): bool
{
    return is_super_admin() || can_access('staff-departments', 'can_edit');
}

// ── Upload helper ─────────────────────────────────────────────────────────────

/**
 * Upload a staff profile photo.
 * Returns the generated filename on success, false on failure.
 */
function sp_upload_photo(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > SP_PHOTO_MAX)     return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, SP_PHOTO_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, SP_PHOTO_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/staff-profiles';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;

    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;

    return $name;
}

// ── Badge helpers ─────────────────────────────────────────────────────────────

function sp_dept_type_badge(string $type): string
{
    return match ($type) {
        'administrative' => '<span class="badge bg-primary">Administrative</span>',
        'educational'    => '<span class="badge bg-success">Educational</span>',
        default          => '<span class="badge bg-secondary">' . h($type) . '</span>',
    };
}
