<?php
/**
 * Shared helper functions for the Gallery module.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Upload constants ──────────────────────────────────────────────────────────
define('GAL_UPLOAD_COVERS', UPLOAD_DIR . '/gallery/covers');
define('GAL_UPLOAD_PHOTOS', UPLOAD_DIR . '/gallery/photos');

define('GAL_URL_COVERS', UPLOAD_URL . '/gallery/covers');
define('GAL_URL_PHOTOS', UPLOAD_URL . '/gallery/photos');

define('GAL_IMG_EXTS',  ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('GAL_IMG_MIMES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('GAL_MAX_IMG',   8 * 1024 * 1024); // 8 MB

// ── Access helpers ────────────────────────────────────────────────────────────

function gallery_can_edit(): bool
{
    return is_super_admin() || can_access('gallery', 'can_edit');
}

function gallery_can_create(): bool
{
    return is_super_admin() || can_access('gallery', 'can_create');
}

function gallery_can_delete(): bool
{
    return is_super_admin() || can_access('gallery', 'can_delete');
}

// ── Image upload helper ───────────────────────────────────────────────────────

/**
 * Validate and move a single uploaded image.
 * Returns stored filename on success, or throws RuntimeException on error.
 */
function gallery_upload_image(array $file, string $dest_dir): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code: ' . $file['error']);
    }
    if ($file['size'] > GAL_MAX_IMG) {
        throw new RuntimeException('Image must be ≤ 8 MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, GAL_IMG_EXTS, true)) {
        throw new RuntimeException('Only JPG, PNG, GIF, WEBP images are allowed.');
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, GAL_IMG_MIMES, true)) {
        throw new RuntimeException('Invalid image file.');
    }
    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0775, true);
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest_dir . '/' . $stored)) {
        throw new RuntimeException('Could not save image.');
    }
    return $stored;
}

function gallery_delete_image(string $dir, ?string $filename): void
{
    if ($filename) {
        $path = $dir . '/' . $filename;
        if (is_file($path)) unlink($path);
    }
}

// ── Status badge ──────────────────────────────────────────────────────────────

function gallery_status_badge(string $status): string
{
    return match ($status) {
        'approved' => '<span class="badge bg-success">Approved</span>',
        'rejected' => '<span class="badge bg-danger">Rejected</span>',
        default    => '<span class="badge bg-warning text-dark">Pending</span>',
    };
}

function gallery_active_badge(int $is_active): string
{
    return $is_active
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary">Inactive</span>';
}
