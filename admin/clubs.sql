-- ─────────────────────────────────────────────────────────────────────────────
-- Club Module  –  clubs.sql
-- Run once against admin_primepnew2026
-- ─────────────────────────────────────────────────────────────────────────────

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. clubs: core club record
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `clubs` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`      INT UNSIGNED  DEFAULT NULL,
  `program_id`   INT UNSIGNED  DEFAULT NULL,
  `name`         VARCHAR(200)  NOT NULL,
  `slug`         VARCHAR(220)  NOT NULL UNIQUE,
  `goal`         TEXT          DEFAULT NULL,
  `facilities`   TEXT          DEFAULT NULL,
  `notice`       TEXT          DEFAULT NULL,
  `cover_photo`  VARCHAR(300)  DEFAULT NULL,
  `logo`         VARCHAR(300)  DEFAULT NULL,
  `is_active`    TINYINT(1)    NOT NULL DEFAULT 1,
  `created_by`   INT UNSIGNED  DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_clubs_dept`    (`dept_id`),
  KEY `idx_clubs_program` (`program_id`),
  KEY `idx_clubs_active`  (`is_active`),
  CONSTRAINT `fk_clubs_dept`    FOREIGN KEY (`dept_id`)    REFERENCES `dept_departments`(`id`)        ON DELETE SET NULL,
  CONSTRAINT `fk_clubs_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. club_members
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `club_members` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `club_id`        INT UNSIGNED NOT NULL,
  `full_name`      VARCHAR(200) NOT NULL,
  `student_id_no`  VARCHAR(30)  DEFAULT NULL COMMENT 'Student ID string (not FK)',
  `role_position`  VARCHAR(100) DEFAULT NULL,
  `sort_order`     INT          NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_clubmem_club` (`club_id`),
  CONSTRAINT `fk_clubmem_club` FOREIGN KEY (`club_id`) REFERENCES `clubs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. club_photos: photo gallery
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `club_photos` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `club_id`       INT UNSIGNED NOT NULL,
  `caption`       VARCHAR(300) DEFAULT NULL,
  `stored_name`   VARCHAR(300) NOT NULL,
  `original_name` VARCHAR(300) NOT NULL,
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `uploaded_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_clubphoto_club` (`club_id`),
  CONSTRAINT `fk_clubphoto_club` FOREIGN KEY (`club_id`) REFERENCES `clubs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. club_activities
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `club_activities` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `club_id`       INT UNSIGNED NOT NULL,
  `title`         VARCHAR(255) NOT NULL,
  `description`   TEXT         DEFAULT NULL,
  `activity_date` DATE         DEFAULT NULL,
  `photo`         VARCHAR(300) DEFAULT NULL,
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_clubact_club` (`club_id`),
  CONSTRAINT `fk_clubact_club` FOREIGN KEY (`club_id`) REFERENCES `clubs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. club_events
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `club_events` (
  `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `club_id`               INT UNSIGNED  NOT NULL,
  `title`                 VARCHAR(255)  NOT NULL,
  `slug`                  VARCHAR(280)  NOT NULL UNIQUE,
  `description`           TEXT          DEFAULT NULL,
  `event_date`            DATE          DEFAULT NULL,
  `event_time`            TIME          DEFAULT NULL,
  `venue`                 VARCHAR(255)  DEFAULT NULL,
  `capacity`              INT UNSIGNED  DEFAULT NULL COMMENT 'NULL = unlimited',
  `registration_deadline` DATE          DEFAULT NULL,
  `cover_photo`           VARCHAR(300)  DEFAULT NULL,
  `is_published`          TINYINT(1)    NOT NULL DEFAULT 0,
  `created_by`            INT UNSIGNED  DEFAULT NULL,
  `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_clubev_club`      (`club_id`),
  KEY `idx_clubev_published` (`is_published`),
  CONSTRAINT `fk_clubev_club` FOREIGN KEY (`club_id`) REFERENCES `clubs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. club_event_registrations
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `club_event_registrations` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `event_id`    INT UNSIGNED  NOT NULL,
  `full_name`   VARCHAR(200)  NOT NULL,
  `student_id_no` VARCHAR(30) DEFAULT NULL,
  `email`       VARCHAR(200)  DEFAULT NULL,
  `phone`       VARCHAR(30)   DEFAULT NULL,
  `department`  VARCHAR(200)  DEFAULT NULL,
  `program`     VARCHAR(200)  DEFAULT NULL,
  `message`     TEXT          DEFAULT NULL,
  `status`      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` INT UNSIGNED  DEFAULT NULL,
  `reviewed_at` DATETIME      DEFAULT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_clubreg_event`  (`event_id`),
  KEY `idx_clubreg_status` (`status`),
  CONSTRAINT `fk_clubreg_event` FOREIGN KEY (`event_id`) REFERENCES `club_events`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- 7. Register module
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`)
VALUES ('Clubs', 'clubs', 'Manage university clubs, members, events, gallery and activities', 'fas fa-users', 55);
