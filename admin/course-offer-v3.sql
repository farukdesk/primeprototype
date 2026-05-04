-- Course Offer v3 Migration
-- Adds multi-subject support: one course offer can now contain multiple
-- subjects, each with its own set of assigned teachers.
--
-- Changes:
--   1. Creates co_offer_subjects  (offer_id → many curriculum_ids)
--   2. Creates co_offer_subject_teachers (offer_subject_id → many faculty_ids)
--   3. Migrates existing co_offers.curriculum_id rows → co_offer_subjects
--   4. Migrates existing co_offer_teachers rows   → co_offer_subject_teachers
--   5. Drops co_offer_teachers
--   6. Drops curriculum_id + its FK/index from co_offers
--   7. Drops the old unique key uq_co_batch_subject from co_offers
--
-- Run AFTER course-offer-v2.sql.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── 1. co_offer_subjects: many subjects per offer ─────────────────────────────
CREATE TABLE IF NOT EXISTS `co_offer_subjects` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `offer_id`      INT UNSIGNED NOT NULL,
  `curriculum_id` INT UNSIGNED NOT NULL COMMENT 'FK → course_curriculum.id',
  `sort_order`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cos_offer_curriculum` (`offer_id`, `curriculum_id`),
  KEY `idx_cos_curriculum` (`curriculum_id`),
  CONSTRAINT `fk_cos_offer`
    FOREIGN KEY (`offer_id`) REFERENCES `co_offers`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cos_curriculum`
    FOREIGN KEY (`curriculum_id`) REFERENCES `course_curriculum`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. co_offer_subject_teachers: many teachers per offer-subject ─────────────
CREATE TABLE IF NOT EXISTS `co_offer_subject_teachers` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `offer_subject_id` INT UNSIGNED NOT NULL,
  `faculty_id`       INT UNSIGNED NOT NULL,
  `sort_order`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cost_subject_faculty` (`offer_subject_id`, `faculty_id`),
  KEY `idx_cost_faculty` (`faculty_id`),
  CONSTRAINT `fk_cost_subject`
    FOREIGN KEY (`offer_subject_id`) REFERENCES `co_offer_subjects`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cost_faculty`
    FOREIGN KEY (`faculty_id`) REFERENCES `dept_faculty`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Migrate: co_offers.curriculum_id → co_offer_subjects ──────────────────
INSERT IGNORE INTO `co_offer_subjects` (`offer_id`, `curriculum_id`, `sort_order`)
SELECT `id`, `curriculum_id`, 0
FROM `co_offers`
WHERE `curriculum_id` IS NOT NULL;

-- ── 4. Migrate: co_offer_teachers → co_offer_subject_teachers ────────────────
INSERT IGNORE INTO `co_offer_subject_teachers` (`offer_subject_id`, `faculty_id`, `sort_order`)
SELECT cos.`id`, cot.`faculty_id`, cot.`sort_order`
FROM `co_offer_subjects` cos
JOIN `co_offers` o   ON o.`id`  = cos.`offer_id`
JOIN `co_offer_teachers` cot ON cot.`offer_id` = o.`id`;

-- ── 5. Drop co_offer_teachers ─────────────────────────────────────────────────
DROP TABLE IF EXISTS `co_offer_teachers`;

-- ── 6. Drop curriculum_id FK, index, and column from co_offers ────────────────
ALTER TABLE `co_offers`
    DROP FOREIGN KEY IF EXISTS `fk_co_curriculum`;

ALTER TABLE `co_offers`
    DROP KEY IF EXISTS `idx_co_curriculum`;

-- ── 7. Drop old unique key (batch_id, curriculum_id) ─────────────────────────
ALTER TABLE `co_offers`
    DROP KEY IF EXISTS `uq_co_batch_subject`;

ALTER TABLE `co_offers`
    DROP COLUMN IF EXISTS `curriculum_id`;

SET FOREIGN_KEY_CHECKS = 1;
