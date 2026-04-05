<?php
/**
 * Shared helper functions for the Clubs module.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Upload constants ──────────────────────────────────────────────────────────
define('CLUB_UPLOAD_COVERS',     UPLOAD_DIR . '/clubs/covers');
define('CLUB_UPLOAD_LOGOS',      UPLOAD_DIR . '/clubs/logos');
define('CLUB_UPLOAD_GALLERY',    UPLOAD_DIR . '/clubs/gallery');
define('CLUB_UPLOAD_ACTIVITIES', UPLOAD_DIR . '/clubs/activities');
define('CLUB_UPLOAD_EVENTS',     UPLOAD_DIR . '/clubs/events');

define('CLUB_URL_COVERS',     UPLOAD_URL . '/clubs/covers');
define('CLUB_URL_LOGOS',      UPLOAD_URL . '/clubs/logos');
define('CLUB_URL_GALLERY',    UPLOAD_URL . '/clubs/gallery');
define('CLUB_URL_ACTIVITIES', UPLOAD_URL . '/clubs/activities');
define('CLUB_URL_EVENTS',     UPLOAD_URL . '/clubs/events');

define('CLUB_IMG_EXTS',  ['jpg','jpeg','png','gif','webp']);
define('CLUB_IMG_MIMES', ['image/jpeg','image/png','image/gif','image/webp']);
define('CLUB_MAX_IMG',   5 * 1024 * 1024); // 5 MB

// ── Access helpers ────────────────────────────────────────────────────────────

function clubs_is_staff(): bool
{
    return is_super_admin() || can_access('clubs', 'can_edit');
}

function clubs_can_create(): bool
{
    return is_super_admin() || can_access('clubs', 'can_create');
}

function clubs_can_delete(): bool
{
    return is_super_admin() || can_access('clubs', 'can_delete');
}

// ── Slug helpers ──────────────────────────────────────────────────────────────

function clubs_slug(string $title): string
{
    $slug = mb_strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'club';
}

function unique_club_slug(string $base, int $exclude_id = 0): string
{
    $slug = $base;
    $i    = 2;
    $db   = db();
    while (true) {
        $st = $db->prepare('SELECT id FROM clubs WHERE slug = ? AND id != ?');
        $st->execute([$slug, $exclude_id]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function unique_event_slug(string $base, int $exclude_id = 0): string
{
    $slug = $base;
    $i    = 2;
    $db   = db();
    while (true) {
        $st = $db->prepare('SELECT id FROM club_events WHERE slug = ? AND id != ?');
        $st->execute([$slug, $exclude_id]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

// ── Image upload helper ───────────────────────────────────────────────────────

/**
 * Validate & move an uploaded image file.
 * Returns stored filename on success, or throws RuntimeException on error.
 */
function clubs_upload_image(array $file, string $dest_dir): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code: ' . $file['error']);
    }
    if ($file['size'] > CLUB_MAX_IMG) {
        throw new RuntimeException('Image must be ≤ 5 MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, CLUB_IMG_EXTS, true)) {
        throw new RuntimeException('Only JPG, PNG, GIF, WEBP images are allowed.');
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, CLUB_IMG_MIMES, true)) {
        throw new RuntimeException('Invalid image file.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest_dir . '/' . $stored)) {
        throw new RuntimeException('Could not save image.');
    }
    return $stored;
}

function clubs_delete_image(string $dir, ?string $filename): void
{
    if ($filename) {
        $path = $dir . '/' . $filename;
        if (is_file($path)) unlink($path);
    }
}

// ── Status badge ──────────────────────────────────────────────────────────────

function clubs_status_badge(int $is_active): string
{
    return $is_active
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary">Inactive</span>';
}

function clubs_reg_status_badge(string $status): string
{
    return match ($status) {
        'approved' => '<span class="badge bg-success">Approved</span>',
        'rejected' => '<span class="badge bg-danger">Rejected</span>',
        default    => '<span class="badge bg-warning text-dark">Pending</span>',
    };
}
