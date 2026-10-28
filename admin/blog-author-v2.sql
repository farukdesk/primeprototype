-- ────────────────────────────────────────────────────────────────────────────
-- Blog module — v2
-- Author display fields + featured-image caption for the public detail page.
-- Auto-migrated at runtime by blog_ensure_schema() in admin/blog/helpers.php;
-- run manually only if the DB user lacks DDL privileges.
-- ────────────────────────────────────────────────────────────────────────────

ALTER TABLE blog_posts
    ADD COLUMN author_name       VARCHAR(190) NULL AFTER meta_description,
    ADD COLUMN author_role       VARCHAR(190) NULL AFTER author_name,
    ADD COLUMN author_department VARCHAR(190) NULL AFTER author_role,
    ADD COLUMN author_bio        TEXT NULL AFTER author_department,
    ADD COLUMN image_caption     VARCHAR(500) NULL AFTER featured_image;
