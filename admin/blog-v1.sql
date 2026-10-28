-- ────────────────────────────────────────────────────────────────────────────
-- Blog module — v1
-- Categories, posts and (moderated) comments for the public website blog.
-- Auto-migrated at runtime by blog_ensure_schema() in admin/blog/helpers.php;
-- run manually only if the DB user lacks DDL privileges.
-- ────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS blog_categories (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_posts (
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
    is_pinned        TINYINT(1) NOT NULL DEFAULT 0,
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
    KEY idx_blog_post_pinned (is_pinned, published_at),
    KEY idx_blog_post_cat (category_id),
    CONSTRAINT fk_blog_post_cat FOREIGN KEY (category_id)
        REFERENCES blog_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_comments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register the module so it can be assigned from Access Control.
INSERT IGNORE INTO modules
    (name, slug, description, icon, parent_id, sort_order,
     is_active, can_view, can_create, can_edit, can_delete)
VALUES
    ('Blog', 'blog',
     'Website blog: posts, categories and comment moderation.',
     'fas fa-blog', NULL, 0, 1, 1, 1, 1, 1);
