<?php
/**
 * Journal Management - Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';

// ── File constraints ─────────────────────────────────────────────────────────
define('JM_PDF_MAX',   25 * 1024 * 1024); // 25 MB
define('JM_IMG_MAX',   5  * 1024 * 1024); // 5 MB
define('JM_IMG_EXTS',  ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('JM_IMG_MIMES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ── Permission helpers ────────────────────────────────────────────────────────
function jm_is_staff(): bool   { return is_super_admin() || can_access('journal', 'can_edit'); }
function jm_can_create(): bool { return is_super_admin() || can_access('journal', 'can_create'); }
function jm_can_delete(): bool { return is_super_admin() || can_access('journal', 'can_delete'); }

/** Gate for write operations (create or edit). */
function jm_require_write(): void
{
    if (!jm_is_staff() && !jm_can_create()) {
        throw new RuntimeException('You do not have permission to modify journal data.');
    }
}

// ── Settings ──────────────────────────────────────────────────────────────────
function jm_setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = db()->prepare('SELECT setting_val FROM journal_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    $cache[$key] = ($val !== false && $val !== null) ? (string)$val : $default;
    return $cache[$key];
}

function jm_save_setting(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO journal_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)'
    )->execute([$key, $value]);
}

// ── Slugs ─────────────────────────────────────────────────────────────────────
function jm_slugify(string $text): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii !== false && $ascii !== '') $text = $ascii;
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $slug !== '' ? $slug : bin2hex(random_bytes(4));
}

/** Generate a slug unique within $table (must have slug + id columns). */
function jm_unique_slug(string $table, string $text, int $ignore_id = 0): string
{
    if (!in_array($table, ['journal_journals', 'journal_articles'], true)) {
        throw new InvalidArgumentException('Invalid slug table.');
    }
    $base = jm_slugify(mb_substr($text, 0, 170));
    $slug = $base;
    $i = 1;
    while (true) {
        $sql = "SELECT id FROM {$table} WHERE slug = ?" . ($ignore_id ? ' AND id <> ?' : '') . ' LIMIT 1';
        $stmt = db()->prepare($sql);
        $stmt->execute($ignore_id ? [$slug, $ignore_id] : [$slug]);
        if (!$stmt->fetch()) return $slug;
        $slug = $base . '-' . (++$i);
    }
}

// ── Uploads ───────────────────────────────────────────────────────────────────
/** Upload an article PDF to UPLOAD_DIR/journal/pdfs. Returns stored filename. */
function jm_upload_pdf(array $file): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('PDF upload failed (error code ' . $file['error'] . ').');
    }
    if ($file['size'] > JM_PDF_MAX) {
        throw new RuntimeException('PDF exceeds the maximum size of 25 MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        throw new RuntimeException('Only PDF files are allowed.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($file['tmp_name']) !== 'application/pdf') {
        throw new RuntimeException('The uploaded file is not a valid PDF.');
    }
    $dir = UPLOAD_DIR . '/journal/pdfs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored = bin2hex(random_bytes(16)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('Failed to store the PDF.');
    }
    return $stored;
}

/** Upload an image (cover / board photo) to UPLOAD_DIR/journal/$subdir. */
function jm_upload_image(array $file, string $subdir): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed (error code ' . $file['error'] . ').');
    }
    if ($file['size'] > JM_IMG_MAX) {
        throw new RuntimeException('Image exceeds the maximum size of 5 MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, JM_IMG_EXTS, true)) {
        throw new RuntimeException('Invalid image extension: ' . $ext);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if (!in_array($finfo->file($file['tmp_name']), JM_IMG_MIMES, true)) {
        throw new RuntimeException('Invalid image type.');
    }
    $subdir = preg_replace('/[^a-z0-9_-]/', '', $subdir);
    $dir = UPLOAD_DIR . '/journal/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('Failed to store the image.');
    }
    return $stored;
}

// ── Data helpers ─────────────────────────────────────────────────────────────
/** All journals (optionally active only) ordered for display. */
function jm_journals(bool $active_only = false): array
{
    $sql = 'SELECT * FROM journal_journals'
         . ($active_only ? " WHERE status = 'active'" : '')
         . ' ORDER BY sort_order ASC, name ASC';
    return db()->query($sql)->fetchAll();
}

/** Permanent public URLs. */
function jm_article_url(string $slug): string { return SITE_URL . '/journal-article.php?slug=' . rawurlencode($slug); }
function jm_pdf_url(string $slug): string     { return SITE_URL . '/journal-pdf.php?slug=' . rawurlencode($slug); }
