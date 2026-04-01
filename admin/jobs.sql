-- ============================================================
-- Jobs Module – SQL Migration
-- ============================================================

-- Jobs table
CREATE TABLE IF NOT EXISTS `jobs` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(500)  NOT NULL,
    `slug`          VARCHAR(500)  NOT NULL,
    `department`    VARCHAR(200)  NOT NULL DEFAULT '',
    `job_type`      ENUM('full-time','part-time','contract','internship') NOT NULL DEFAULT 'full-time',
    `location`      VARCHAR(200)  NOT NULL DEFAULT '',
    `description`   LONGTEXT      NOT NULL,
    `requirements`  LONGTEXT      NULL,
    `salary_range`  VARCHAR(100)  NULL,
    `deadline`      DATE          NULL,
    `is_published`  TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_job_slug` (`slug`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Job applications table
CREATE TABLE IF NOT EXISTS `job_applications` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `job_id`            INT UNSIGNED  NOT NULL,
    `full_name`         VARCHAR(200)  NOT NULL,
    `email`             VARCHAR(200)  NOT NULL,
    `phone`             VARCHAR(30)   NULL,
    `cover_letter`      TEXT          NULL,
    `cv_filename`       VARCHAR(255)  NULL,
    `cv_original_name`  VARCHAR(255)  NULL,
    `status`            ENUM('pending','reviewing','shortlisted','rejected') NOT NULL DEFAULT 'pending',
    `applied_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_job_applications_job` (`job_id`),
    KEY `idx_job_applications_status` (`status`),
    CONSTRAINT `fk_job_applications_job`
        FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register module
INSERT IGNORE INTO `modules` (`name`, `slug`, `icon`, `sort_order`, `is_active`)
VALUES ('Jobs', 'jobs', 'fas fa-briefcase', 90, 1);
