-- Prime University Admin Panel Database Schema
-- Run this SQL to set up the required tables

CREATE DATABASE IF NOT EXISTS `prime_university`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `prime_university`;

-- -------------------------------------------------------
-- Table: modules
-- Stores all available admin modules / sections
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `modules` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100)     NOT NULL,
    `slug`        VARCHAR(100)     NOT NULL UNIQUE,
    `description` TEXT,
    `icon`        VARCHAR(100)     DEFAULT 'fas fa-circle',
    `parent_id`   INT UNSIGNED     DEFAULT NULL,
    `sort_order`  INT              DEFAULT 0,
    `is_active`   TINYINT(1)       DEFAULT 1,
    `created_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_slug` (`slug`),
    KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: user_groups
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_groups` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100)     NOT NULL,
    `description` TEXT,
    `is_super`    TINYINT(1)       DEFAULT 0 COMMENT '1 = super admin group',
    `is_active`   TINYINT(1)       DEFAULT 1,
    `created_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: group_module_access
-- Maps which modules each group can access
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `group_module_access` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `group_id`    INT UNSIGNED     NOT NULL,
    `module_id`   INT UNSIGNED     NOT NULL,
    `can_view`    TINYINT(1)       DEFAULT 1,
    `can_create`  TINYINT(1)       DEFAULT 0,
    `can_edit`    TINYINT(1)       DEFAULT 0,
    `can_delete`  TINYINT(1)       DEFAULT 0,
    `created_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_group_module` (`group_id`, `module_id`),
    FOREIGN KEY (`group_id`)  REFERENCES `user_groups`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`module_id`) REFERENCES `modules`(`id`)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: users
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `group_id`     INT UNSIGNED     NOT NULL,
    `username`     VARCHAR(60)      NOT NULL UNIQUE,
    `email`        VARCHAR(191)     NOT NULL UNIQUE,
    `password`     VARCHAR(255)     NOT NULL COMMENT 'bcrypt hash',
    `full_name`    VARCHAR(150)     NOT NULL,
    `phone`        VARCHAR(30)      DEFAULT NULL,
    `avatar`       VARCHAR(255)     DEFAULT NULL,
    `is_active`    TINYINT(1)       DEFAULT 1,
    `last_login`   DATETIME         DEFAULT NULL,
    `created_at`   DATETIME         DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_username` (`username`),
    KEY `idx_email`    (`email`),
    FOREIGN KEY (`group_id`) REFERENCES `user_groups`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Seed: Super Admin group
-- -------------------------------------------------------
INSERT INTO `user_groups` (`name`, `description`, `is_super`, `is_active`)
VALUES ('Super Admin', 'Full system access – unrestricted.', 1, 1);

-- -------------------------------------------------------
-- Seed: Core modules
-- -------------------------------------------------------
INSERT INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`) VALUES
('Dashboard',      'dashboard',     'Admin dashboard overview',            'fas fa-tachometer-alt', 1),
('Users',          'users',         'Manage system users',                 'fas fa-users',          2),
('User Groups',    'user-groups',   'Manage user groups and permissions',  'fas fa-layer-group',    3),
('Modules',        'modules',       'Manage system modules',               'fas fa-cubes',          4),
('Module Access',  'access',        'Assign module access to groups',      'fas fa-shield-alt',     5);

-- -------------------------------------------------------
-- Seed: Default super admin user
-- Password: Admin@123  (change immediately after first login!)
-- -------------------------------------------------------
INSERT INTO `users` (`group_id`, `username`, `email`, `password`, `full_name`, `is_active`)
VALUES (
    1,
    'superadmin',
    'admin@primeuniversity.edu',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'System Super Admin',
    1
);
-- NOTE: The hash above corresponds to the plain-text password: password
-- Run the following PHP snippet to generate a hash for your own password:
--   echo password_hash('YourNewPassword', PASSWORD_BCRYPT, ['cost' => 12]);
-- Then UPDATE the users table with the new hash before going live.
