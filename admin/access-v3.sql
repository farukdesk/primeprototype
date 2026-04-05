-- ============================================================
-- Access v3 migration
-- 1. Multi-group support: user_group_assignments junction table
-- 2. Department scope: group_dept_scope + user_dept_scope
-- 3. Department sub-modules registered in modules table
-- Run after database.sql, access-v2.sql
-- ============================================================

-- -------------------------------------------------------
-- 1. Multi-group: junction table
--    Each user can belong to multiple groups.
--    is_primary=1 marks the "display" group (shown in UI, kept in users.group_id).
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_group_assignments` (
    `user_id`     INT UNSIGNED NOT NULL,
    `group_id`    INT UNSIGNED NOT NULL,
    `is_primary`  TINYINT(1)   NOT NULL DEFAULT 0,
    `assigned_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `group_id`),
    KEY `idx_uga_group` (`group_id`),
    FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)        ON DELETE CASCADE,
    FOREIGN KEY (`group_id`) REFERENCES `user_groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill existing users: their current group_id becomes the primary group assignment
INSERT IGNORE INTO `user_group_assignments` (`user_id`, `group_id`, `is_primary`)
SELECT `id`, `group_id`, 1 FROM `users`;

-- -------------------------------------------------------
-- 2. Department scope: which departments a group/user can manage
--    NULL dept_id = unrestricted (all departments)
--    Specific dept_id rows = restricted to those departments
-- -------------------------------------------------------

-- Group-level dept scope
CREATE TABLE IF NOT EXISTS `group_dept_scope` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_id`   INT UNSIGNED NOT NULL,
    `dept_id`    INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_gds` (`group_id`, `dept_id`),
    KEY `idx_gds_group` (`group_id`),
    KEY `idx_gds_dept`  (`dept_id`),
    FOREIGN KEY (`group_id`) REFERENCES `user_groups`(`id`)      ON DELETE CASCADE,
    FOREIGN KEY (`dept_id`)  REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User-level dept scope override
CREATE TABLE IF NOT EXISTS `user_dept_scope` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `dept_id`    INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uds` (`user_id`, `dept_id`),
    KEY `idx_uds_user` (`user_id`),
    KEY `idx_uds_dept` (`dept_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)             ON DELETE CASCADE,
    FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- 3. Register departments parent module and sub-modules
-- -------------------------------------------------------
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`)
VALUES ('Departments', 'departments', 'Manage university departments', 'fas fa-building-columns', 20);

-- Sub-modules referencing the departments parent
SET @dept_mod_id = (SELECT `id` FROM `modules` WHERE `slug` = 'departments' LIMIT 1);

INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`) VALUES
  ('Dept: Routines',           'dept-routines',          'Department class and exam routines',   'fas fa-clock',               @dept_mod_id, 21),
  ('Dept: Events',             'dept-events',            'Department events',                    'fas fa-calendar-alt',         @dept_mod_id, 22),
  ('Dept: Notices',            'dept-notices',           'Department notices',                   'fas fa-bell',                 @dept_mod_id, 23),
  ('Dept: Faculty',            'dept-faculty',           'Department faculty members',            'fas fa-chalkboard-teacher',   @dept_mod_id, 24),
  ('Dept: Alumni',             'dept-alumni',            'Department alumni records',             'fas fa-user-graduate',        @dept_mod_id, 25),
  ('Dept: Clubs',              'dept-clubs',             'Department clubs',                     'fas fa-users',                @dept_mod_id, 26),
  ('Dept: Facilities',         'dept-facilities',        'Department facilities',                'fas fa-building',             @dept_mod_id, 27),
  ('Dept: Academic Programs',  'dept-academic-programs', 'Department academic programs',         'fas fa-book-open',            @dept_mod_id, 28),
  ('Dept: Prime Pride',        'dept-prime-pride',       'Department prime pride content',       'fas fa-star',                 @dept_mod_id, 29),
  ('Dept: Hero Slides',        'dept-hero-slides',       'Department hero section slides',       'fas fa-images',               @dept_mod_id, 30);
