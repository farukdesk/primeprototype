-- ============================================================
-- Access v2 migration
-- 1. Adds per-user module access override table
-- 2. Merges the legacy 'cms-news' module into 'cms-notice-board'
--    (notices with publish_as_news=1 already serve as news)
-- Run after database.sql and news-notice-v2.sql
-- ============================================================

-- -------------------------------------------------------
-- 1. Per-user module access (overrides group permissions)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_module_access` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `module_id`   INT UNSIGNED NOT NULL,
    `can_view`    TINYINT(1) DEFAULT 1,
    `can_create`  TINYINT(1) DEFAULT 0,
    `can_edit`    TINYINT(1) DEFAULT 0,
    `can_delete`  TINYINT(1) DEFAULT 0,
    `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_module` (`user_id`, `module_id`),
    KEY `idx_uma_user`   (`user_id`),
    KEY `idx_uma_module` (`module_id`),
    FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)   ON DELETE CASCADE,
    FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- 2. Merge cms-news into cms-notice-board
--    a) Ensure cms-notice-board module exists (safe insert)
-- -------------------------------------------------------
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`)
VALUES ('Notice Board', 'cms-notice-board', 'Manage public notices and news', 'fas fa-bullhorn', 11);

-- b) Migrate any group_module_access rows that pointed to cms-news
--    → upsert them onto cms-notice-board (OR the existing cms-notice-board row)
UPDATE `group_module_access` gma_old
JOIN `modules` m_old  ON m_old.id  = gma_old.module_id AND m_old.slug  = 'cms-news'
JOIN `modules` m_new  ON m_new.slug = 'cms-notice-board'
LEFT JOIN `group_module_access` gma_new
       ON gma_new.group_id = gma_old.group_id AND gma_new.module_id = m_new.id
SET gma_old.module_id = m_new.id,
    gma_old.can_view   = GREATEST(gma_old.can_view,   COALESCE(gma_new.can_view,   0)),
    gma_old.can_create = GREATEST(gma_old.can_create, COALESCE(gma_new.can_create, 0)),
    gma_old.can_edit   = GREATEST(gma_old.can_edit,   COALESCE(gma_new.can_edit,   0)),
    gma_old.can_delete = GREATEST(gma_old.can_delete, COALESCE(gma_new.can_delete, 0))
WHERE gma_new.id IS NULL;

-- c) Remove any duplicate rows that now point to the same (group, cms-notice-board) pair
DELETE gma_old
FROM `group_module_access` gma_old
JOIN `modules` m_old ON m_old.id = gma_old.module_id AND m_old.slug = 'cms-news'
JOIN `modules` m_new ON m_new.slug = 'cms-notice-board'
JOIN `group_module_access` gma_new
  ON gma_new.group_id = gma_old.group_id AND gma_new.module_id = m_new.id;

-- d) Remove the now-redundant cms-news module
DELETE FROM `modules` WHERE `slug` = 'cms-news';

-- -------------------------------------------------------
-- 3. Register 'Module Access' module if missing (added
--    description for user access)
-- -------------------------------------------------------
UPDATE `modules`
SET `description` = 'Assign module access to groups and individual users'
WHERE `slug` = 'access';
