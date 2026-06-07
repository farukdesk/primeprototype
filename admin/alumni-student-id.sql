-- ============================================================
-- Alumni Module – Add student_id column
-- Run after alumni.sql
-- Idempotent: safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `alumni`
    ADD COLUMN IF NOT EXISTS `student_id` varchar(50) DEFAULT NULL
        COMMENT 'University student ID (e.g. 201-15-2345)'
        AFTER `dept_id`;
