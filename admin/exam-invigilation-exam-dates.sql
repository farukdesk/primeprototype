-- ============================================================
-- Exam Invigilation – Add start_date and end_date to ei_exams
-- Run after exam-invigilation.sql
-- ============================================================

ALTER TABLE `ei_exams`
    ADD COLUMN `start_date` date DEFAULT NULL
        COMMENT 'Exam period start date'
        AFTER `exam_year`,
    ADD COLUMN `end_date`   date DEFAULT NULL
        COMMENT 'Exam period end date'
        AFTER `start_date`;
