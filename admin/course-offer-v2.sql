-- Course Offer v2 Migration
-- Changes:
--   1. Switches batch_id FK from course_curriculum_intakes → student_batches
--      (students are assigned to student_batches, so offers should use the same table)
--   2. Adds `semester` for the offering period (e.g. "Spring 2026")
--   3. Adds `academic_intake` for the academic standing (e.g. "1st Year 1st Semester")
--
-- Run AFTER course-offer.sql and students-v3.sql.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── 1. Drop the old FK that pointed at course_curriculum_intakes ───────────────
ALTER TABLE `co_offers`
    DROP FOREIGN KEY IF EXISTS `fk_co_batch`;

-- ── 2. Re-add FK pointing at student_batches ──────────────────────────────────
ALTER TABLE `co_offers`
    ADD CONSTRAINT `fk_co_batch`
        FOREIGN KEY (`batch_id`) REFERENCES `student_batches`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE;

-- ── 3. Add semester column (nullable – existing rows stay intact) ──────────────
ALTER TABLE `co_offers`
    ADD COLUMN IF NOT EXISTS `semester`
        VARCHAR(50) DEFAULT NULL
        COMMENT 'e.g. "Spring 2026", "Summer 2026", "Fall 2026"'
        AFTER `curriculum_id`;

-- ── 4. Add academic_intake column ─────────────────────────────────────────────
ALTER TABLE `co_offers`
    ADD COLUMN IF NOT EXISTS `academic_intake`
        VARCHAR(100) DEFAULT NULL
        COMMENT 'e.g. "1st Year 1st Semester", "2nd Year 2nd Semester"'
        AFTER `semester`;

SET FOREIGN_KEY_CHECKS = 1;
