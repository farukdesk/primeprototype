<?php
/**
 * Shared helpers for the Law & Legal Affairs admin module.
 */

require_once __DIR__ . '/../change-log/helpers.php';

// ── Upload constants ──────────────────────────────────────────────────────────
const LL_PHOTO_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const LL_PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const LL_PHOTO_MAX   = 5 * 1024 * 1024; // 5 MB
const LL_UPLOAD_DIR  = 'law-legal';

// ── Permission helpers ────────────────────────────────────────────────────────

function ll_can_edit(): bool
{
    return is_super_admin() || can_access('law-legal', 'can_edit');
}

function ll_can_create(): bool
{
    return is_super_admin() || can_access('law-legal', 'can_create');
}

function ll_can_delete(): bool
{
    return is_super_admin() || can_access('law-legal', 'can_delete');
}

// ── Settings helper ───────────────────────────────────────────────────────────

/**
 * Load all ll_settings into an associative array.
 */
function ll_load_settings(): array
{
    $s = [];
    try {
        $rows = db()->query('SELECT setting_key, setting_val FROM ll_settings')->fetchAll();
        foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
    } catch (Throwable $e) {}
    return $s;
}

/**
 * Get a setting value with a fallback default.
 */
function ll_s(array $s, string $key, string $default = ''): string
{
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

// ── Photo upload helper ───────────────────────────────────────────────────────

function ll_upload_photo(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > LL_PHOTO_MAX)     return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, LL_PHOTO_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, LL_PHOTO_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/' . LL_UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;

    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;

    return $name;
}

/**
 * Delete a photo file from the upload directory.
 */
function ll_delete_photo(string $filename): void
{
    if ($filename === '') return;
    $path = UPLOAD_DIR . '/' . LL_UPLOAD_DIR . '/' . $filename;
    if (is_file($path)) @unlink($path);
}

// ── Badge helpers ─────────────────────────────────────────────────────────────

function ll_category_badge(string $cat): string
{
    return match ($cat) {
        'notice'       => '<span class="badge" style="background:#dbeafe;color:#1e40af;border-radius:20px;font-size:.73rem;">Notice</span>',
        'circular'     => '<span class="badge" style="background:#fce7f3;color:#9d174d;border-radius:20px;font-size:.73rem;">Circular</span>',
        'policy'       => '<span class="badge" style="background:#d1fae5;color:#065f46;border-radius:20px;font-size:.73rem;">Policy</span>',
        'announcement' => '<span class="badge" style="background:#fef3c7;color:#92400e;border-radius:20px;font-size:.73rem;">Announcement</span>',
        default        => '<span class="badge bg-secondary" style="border-radius:20px;font-size:.73rem;">' . h($cat) . '</span>',
    };
}
