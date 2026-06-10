-- ============================================================
-- Admissions – Form Sale Student Details Token
-- Run this file after admissions-form-sale.sql
-- ============================================================

-- ── 24-hour fill-up tokens ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `adm_form_sale_tokens` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_sale_id` INT UNSIGNED NOT NULL,
    `token`        VARCHAR(64)  NOT NULL,
    `expires_at`   DATETIME     NOT NULL,
    `used_at`      DATETIME     NULL DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_fst_token` (`token`),
    KEY `idx_fst_form_sale` (`form_sale_id`),
    CONSTRAINT `fk_fst_form_sale`
        FOREIGN KEY (`form_sale_id`) REFERENCES `adm_form_sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Student-submitted personal details ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `adm_form_sale_student_details` (
    `id`                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `form_sale_id`             INT UNSIGNED  NOT NULL,
    `token_id`                 INT UNSIGNED  NOT NULL,
    `student_name`             VARCHAR(255)  NOT NULL,
    `father_name`              VARCHAR(255)  NULL,
    `mother_name`              VARCHAR(255)  NULL,
    `gender`                   VARCHAR(20)   NULL,
    `date_of_birth`            DATE          NULL,
    `blood_group`              VARCHAR(10)   NULL,
    `nationality`              VARCHAR(100)  NULL,
    `place_of_birth`           VARCHAR(255)  NULL,
    `nid_birth_cert`           VARCHAR(100)  NULL,
    `religion`                 VARCHAR(50)   NULL,
    `permanent_address_1`      VARCHAR(255)  NULL,
    `permanent_address_2`      VARCHAR(255)  NULL,
    `permanent_area`           VARCHAR(255)  NULL,
    `permanent_district_id`    INT UNSIGNED  NULL,
    `permanent_thana_id`       INT UNSIGNED  NULL,
    `permanent_post_code`      VARCHAR(20)   NULL,
    `present_same_as_permanent` TINYINT(1)  NOT NULL DEFAULT 0,
    `present_address_1`        VARCHAR(255)  NULL,
    `present_address_2`        VARCHAR(255)  NULL,
    `present_area`             VARCHAR(255)  NULL,
    `present_district_id`      INT UNSIGNED  NULL,
    `present_thana_id`         INT UNSIGNED  NULL,
    `present_post_code`        VARCHAR(20)   NULL,
    `submitted_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_fssd_form_sale` (`form_sale_id`),
    CONSTRAINT `fk_fssd_form_sale`
        FOREIGN KEY (`form_sale_id`) REFERENCES `adm_form_sales` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fssd_token`
        FOREIGN KEY (`token_id`) REFERENCES `adm_form_sale_tokens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
