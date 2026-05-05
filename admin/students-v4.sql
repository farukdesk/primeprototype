-- =============================================================
-- students-v4.sql
-- Adds semester_type column to students table.
-- Run AFTER students.sql, students-v2.sql, and students-v3.sql.
-- =============================================================

SET NAMES utf8mb4;

ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `semester_type` VARCHAR(30) DEFAULT NULL
        COMMENT 'Semester system: bi_semester or trimester'
        AFTER `admitted_semester`;
