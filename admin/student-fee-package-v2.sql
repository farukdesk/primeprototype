-- Student Fee Package v2 – Multi-scholarship support per semester
-- Run AFTER student-fee-package.sql
-- Each semester can now carry multiple named scholarships; totals are aggregated
-- back into sfp_semester_fees for fast queries.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. sfp_semester_scholarships
--    One row per individual scholarship applied to a semester fee row.
--    After any insert / delete here, call sfp_recalculate_semester() in PHP
--    (or run the UPDATE below manually) to keep sfp_semester_fees totals in sync.
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sfp_semester_scholarships` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sf_id`        INT UNSIGNED  NOT NULL
                 COMMENT 'FK sfp_semester_fees.id',
  `label`        VARCHAR(200)  NOT NULL
                 COMMENT 'Scholarship type label, e.g. "Initial Waiver", "Sports", "Freedom Fighter"',
  `discount_pct` DECIMAL(5,2)  NOT NULL DEFAULT 0.00
                 COMMENT 'Percentage of tuition_fee to waive',
  `amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00
                 COMMENT 'Calculated: tuition_fee * discount_pct / 100 at time of creation',
  `note`         TEXT          DEFAULT NULL,
  `created_by`   INT UNSIGNED  DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY `idx_sfpss_sf` (`sf_id`),
  CONSTRAINT `fk_sfpss_sf`      FOREIGN KEY (`sf_id`)      REFERENCES `sfp_semester_fees`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sfpss_created` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Individual scholarship entries per semester; totals aggregated into sfp_semester_fees';

SET FOREIGN_KEY_CHECKS = 1;
