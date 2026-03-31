-- Faculty Profiles Migration
-- Run once to set up faculty profile tables, module, and permissions.

-- 1. Create faculty_profiles table
CREATE TABLE IF NOT EXISTS `faculty_profiles` (
    `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`                  INT UNSIGNED NOT NULL,
    `photo`                    VARCHAR(255) DEFAULT NULL,
    `designation`              VARCHAR(200) DEFAULT NULL,
    `qualification`            TEXT DEFAULT NULL,
    `official_email`           VARCHAR(200) DEFAULT NULL,
    `personal_email`           VARCHAR(200) DEFAULT NULL,
    `phone`                    VARCHAR(50)  DEFAULT NULL,
    `bio`                      TEXT DEFAULT NULL,
    `research_interest`        TEXT DEFAULT NULL,
    `publications`             TEXT DEFAULT NULL,
    `experience`               TEXT DEFAULT NULL,
    `office_location`          VARCHAR(300) DEFAULT NULL,
    `room_number`              VARCHAR(100) DEFAULT NULL,
    `office_hours`             VARCHAR(300) DEFAULT NULL,
    `courses_taught`           TEXT DEFAULT NULL,
    `google_scholar`           VARCHAR(500) DEFAULT NULL,
    `orcid`                    VARCHAR(500) DEFAULT NULL,
    `research_profiles`        TEXT DEFAULT NULL,
    `cv_file`                  VARCHAR(255) DEFAULT NULL,
    `awards`                   TEXT DEFAULT NULL,
    `professional_memberships` TEXT DEFAULT NULL,
    `social_links`             TEXT DEFAULT NULL,
    `projects_grants`          TEXT DEFAULT NULL,
    `supervision`              TEXT DEFAULT NULL,
    `skills`                   TEXT DEFAULT NULL,
    `languages`                VARCHAR(500) DEFAULT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_id` (`user_id`),
    CONSTRAINT `fk_fp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Add user_id column to dept_faculty (links dept entry to a faculty user account)
ALTER TABLE `dept_faculty`
    ADD COLUMN IF NOT EXISTS `user_id` INT UNSIGNED DEFAULT NULL AFTER `dept_id`,
    ADD CONSTRAINT `fk_df_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- 3. Insert Faculty user group
INSERT IGNORE INTO `user_groups` (`name`, `description`, `is_super`, `is_active`)
VALUES ('Faculty', 'Faculty members who can manage their own profile', 0, 1);

-- 4. Insert Faculty Profile module
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`, `is_active`)
VALUES ('Faculty Profile', 'faculty-profile', 'Faculty profile management', 'fas fa-id-card', NULL, 90, 1);

-- 5. Grant Faculty group access to the Faculty Profile module
INSERT IGNORE INTO `group_module_access` (`group_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT ug.id, m.id, 1, 0, 1, 0
FROM `user_groups` ug, `modules` m
WHERE ug.name = 'Faculty' AND m.slug = 'faculty-profile';
