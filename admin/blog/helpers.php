<?php
/**
 * Shared helpers for the Blog admin module.
 */

// ── Allowed image types ───────────────────────────────────────────────────────
const BLOG_IMG_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const BLOG_IMG_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const BLOG_IMG_MAX   = 5 * 1024 * 1024; // 5 MB

// ── Schema / module auto-migration ───────────────────────────────────────────

/**
 * Create blog tables and register the "blog" module (mirrors admin/blog-v1.sql).
 */
function blog_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db = db();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS blog_categories (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(190) NOT NULL,
                slug        VARCHAR(190) NOT NULL,
                description TEXT NULL,
                sort_order  INT NOT NULL DEFAULT 0,
                is_active   TINYINT(1) NOT NULL DEFAULT 1,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_blog_cat_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS blog_posts (
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                title            VARCHAR(500) NOT NULL,
                slug             VARCHAR(190) NOT NULL,
                excerpt          TEXT NULL,
                content          LONGTEXT NULL,
                content_type     ENUM('html','text') NOT NULL DEFAULT 'html',
                featured_image   VARCHAR(255) NULL,
                category_id      INT UNSIGNED NULL,
                tags             VARCHAR(500) NULL,
                status           ENUM('draft','published') NOT NULL DEFAULT 'draft',
                published_at     DATETIME NULL,
                allow_comments   TINYINT(1) NOT NULL DEFAULT 1,
                views            INT UNSIGNED NOT NULL DEFAULT 0,
                meta_title       VARCHAR(255) NULL,
                meta_description VARCHAR(500) NULL,
                created_by       INT NULL,
                created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_blog_post_slug (slug),
                KEY idx_blog_post_status (status, published_at),
                KEY idx_blog_post_cat (category_id),
                CONSTRAINT fk_blog_post_cat FOREIGN KEY (category_id)
                    REFERENCES blog_categories (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS blog_comments (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id      INT UNSIGNED NOT NULL,
                author_name  VARCHAR(190) NOT NULL,
                author_email VARCHAR(190) NULL,
                content      TEXT NOT NULL,
                status       ENUM('pending','approved','spam') NOT NULL DEFAULT 'pending',
                ip_address   VARCHAR(45) NULL,
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_blog_comment_post (post_id, status),
                CONSTRAINT fk_blog_comment_post FOREIGN KEY (post_id)
                    REFERENCES blog_posts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "INSERT IGNORE INTO modules
                (name, slug, description, icon, parent_id, sort_order,
                 is_active, can_view, can_create, can_edit, can_delete)
             VALUES
                ('Blog', 'blog',
                 'Website blog: posts, categories and comment moderation.',
                 'fas fa-blog', NULL, 0, 1, 1, 1, 1, 1)"
        );
    } catch (Throwable $e) {
        // Insufficient privileges – run admin/blog-v1.sql manually.
    }
}

// ── Permission helpers ────────────────────────────────────────────────────────

function blog_can(string $perm = 'can_view'): bool
{
    return is_super_admin() || can_access('blog', $perm);
}

// ── Slug helpers ─────────────────────────────────────────────────────────────

function blog_slugify(string $text): string
{
    $slug = mb_strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'untitled';
}

function blog_unique_slug(string $table, string $base, int $exclude_id = 0): string
{
    $base = mb_substr($base, 0, 180);
    $slug = $base;
    $i    = 2;
    $db   = db();
    while (true) {
        $st = $db->prepare("SELECT id FROM {$table} WHERE slug = ? AND id != ?");
        $st->execute([$slug, $exclude_id]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

// ── Upload helper ─────────────────────────────────────────────────────────────

/**
 * Upload a blog featured image. Returns stored filename or false.
 */
function blog_upload_image(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > BLOG_IMG_MAX)     return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, BLOG_IMG_EXTS, true)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, BLOG_IMG_MIMES, true)) return false;

    $dir = UPLOAD_DIR . '/blog';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;

    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;

    return $name;
}

/**
 * Delete a stored blog image if present.
 */
function blog_delete_image(?string $name): void
{
    if (!$name) return;
    $path = UPLOAD_DIR . '/blog/' . basename($name);
    if (is_file($path)) @unlink($path);
}

// ── Data helpers ─────────────────────────────────────────────────────────────

/** All categories ordered for admin drop-downs. */
function blog_categories(): array
{
    return db()->query(
        'SELECT * FROM blog_categories ORDER BY sort_order, name'
    )->fetchAll();
}

/** Number of comments pending moderation. */
function blog_pending_comment_count(): int
{
    return (int)db()->query(
        "SELECT COUNT(*) FROM blog_comments WHERE status = 'pending'"
    )->fetchColumn();
}

/** Fetch a post by id or null. */
function blog_find_post(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM blog_posts WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}
