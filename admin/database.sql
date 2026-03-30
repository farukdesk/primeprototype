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
-- Table: email_templates
-- Stores system email templates keyed by action/trigger
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_templates` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(150)     NOT NULL,
    `action`      VARCHAR(100)     NOT NULL UNIQUE COMMENT 'trigger slug e.g. forgot_password',
    `subject`     VARCHAR(255)     NOT NULL,
    `body_html`   LONGTEXT         NOT NULL,
    `variables`   VARCHAR(500)     DEFAULT NULL COMMENT 'comma-separated available variables e.g. {{full_name}},{{reset_link}}',
    `is_active`   TINYINT(1)       DEFAULT 1,
    `created_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: password_resets
-- Stores one-time password reset tokens for admin users
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(191)     NOT NULL,
    `token`      VARCHAR(100)     NOT NULL UNIQUE,
    `created_at` DATETIME         DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email` (`email`),
    KEY `idx_token` (`token`)
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
('Module Access',  'access',        'Assign module access to groups',      'fas fa-shield-alt',     5),
('Email Templates','email-templates','Manage system email templates',      'fas fa-envelope-open-text', 6),
('CMS – Menus',   'cms-menus',   'Manage website navigation menus',     'fas fa-bars',              10),
('CMS – News',    'cms-news',    'Manage latest news articles',          'fas fa-newspaper',         11),
('CMS – Sliders', 'cms-sliders', 'Manage homepage slider images',        'fas fa-images',            12);

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

-- -------------------------------------------------------
-- Seed: Forgot Password email template
-- Variables: {{full_name}}, {{reset_link}}, {{app_name}}, {{expire_minutes}}
-- -------------------------------------------------------
INSERT INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`) VALUES (
  'Forgot Password',
  'forgot_password',
  'Reset Your Password – {{app_name}}',
  '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reset Your Password</title>
<style>
  body { margin:0; padding:0; background:#f4f6fb; font-family:''Inter'',Arial,sans-serif; }
  .wrapper { max-width:580px; margin:40px auto; background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); overflow:hidden; }
  .header { background:linear-gradient(135deg,#1a1f36 0%,#2d3561 100%); padding:36px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:1.5rem; font-weight:700; }
  .header p  { color:rgba(255,255,255,.7); margin:8px 0 0; font-size:.9rem; }
  .body { padding:36px 40px; color:#374151; }
  .body p  { margin:0 0 16px; line-height:1.7; font-size:.925rem; }
  .btn-wrap { text-align:center; margin:28px 0; }
  .btn { display:inline-block; padding:14px 36px; background:linear-gradient(135deg,#4f8ef7,#2d63e8); color:#fff !important;
         text-decoration:none; border-radius:10px; font-weight:600; font-size:.95rem; }
  .expire { background:#fff8e1; border-left:4px solid #f5a623; padding:12px 16px; border-radius:6px; font-size:.85rem; color:#7a5c00; margin:20px 0; }
  .footer { background:#f4f6fb; padding:20px 40px; text-align:center; font-size:.78rem; color:#9ca3af; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>Password Reset Request</h1>
    <p>{{app_name}}</p>
  </div>
  <div class="body">
    <p>Hi <strong>{{full_name}}</strong>,</p>
    <p>We received a request to reset the password for your admin account. Click the button below to choose a new password:</p>
    <div class="btn-wrap">
      <a href="{{reset_link}}" class="btn">Reset My Password</a>
    </div>
    <div class="expire">
      <strong>⏰ This link expires in {{expire_minutes}} minutes.</strong><br>
      If you did not request a password reset, please ignore this email – your account remains secure.
    </div>
    <p>If the button above does not work, copy and paste the following link into your browser:</p>
    <p style="word-break:break-all;font-size:.82rem;color:#6b7280;">{{reset_link}}</p>
  </div>
  <div class="footer">
    &copy; {{app_name}} &mdash; This is an automated message, please do not reply.
  </div>
</div>
</body>
</html>',
  '{{full_name}},{{reset_link}},{{app_name}},{{expire_minutes}}',
  1
);

-- -------------------------------------------------------
-- CMS Tables
-- -------------------------------------------------------

-- Table: cms_menus
-- Stores navigation menu items in a self-referencing hierarchy
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_menus` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `parent_id`   INT UNSIGNED  DEFAULT NULL,
    `label`       VARCHAR(150)  NOT NULL,
    `url`         VARCHAR(500)  DEFAULT '#',
    `target`      ENUM('_self','_blank') DEFAULT '_self',
    `type`        ENUM('link','dropdown','megamenu') DEFAULT 'link',
    `icon`        VARCHAR(100)  DEFAULT NULL,
    `sort_order`  INT           DEFAULT 0,
    `is_active`   TINYINT(1)    DEFAULT 1,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_parent` (`parent_id`),
    FOREIGN KEY (`parent_id`) REFERENCES `cms_menus`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: cms_news
-- Stores news articles with optional HTML or plain-text content
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_news` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`           VARCHAR(500)  NOT NULL,
    `slug`            VARCHAR(500)  NOT NULL,
    `content`         LONGTEXT,
    `content_type`    ENUM('html','text') DEFAULT 'html',
    `featured_image`  VARCHAR(500)  DEFAULT NULL,
    `is_published`    TINYINT(1)    DEFAULT 0,
    `published_at`    DATETIME      DEFAULT NULL,
    `created_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: cms_news_attachments
-- Stores files / images attached to a news article
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_news_attachments` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `news_id`       INT UNSIGNED  NOT NULL,
    `original_name` VARCHAR(255)  NOT NULL,
    `stored_name`   VARCHAR(255)  NOT NULL,
    `mime_type`     VARCHAR(100)  DEFAULT NULL,
    `size`          INT UNSIGNED  DEFAULT 0,
    `created_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_news` (`news_id`),
    FOREIGN KEY (`news_id`) REFERENCES `cms_news`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Table: cms_sliders
-- Stores homepage/section slider images
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cms_sliders` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255)  DEFAULT NULL,
    `subtitle`    VARCHAR(500)  DEFAULT NULL,
    `image`       VARCHAR(500)  NOT NULL,
    `link_url`    VARCHAR(500)  DEFAULT NULL,
    `link_text`   VARCHAR(150)  DEFAULT NULL,
    `sort_order`  INT           DEFAULT 0,
    `is_active`   TINYINT(1)    DEFAULT 1,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
