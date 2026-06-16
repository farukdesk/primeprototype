-- ============================================================
-- Exam Invigilation – Add gender and signature to faculty
-- Run after exam-invigilation.sql
-- ============================================================

ALTER TABLE `ei_faculty`
    ADD COLUMN `gender`    ENUM('Male','Female') DEFAULT NULL
        COMMENT 'Faculty gender'
        AFTER `designation`,
    ADD COLUMN `signature` varchar(500) DEFAULT NULL
        COMMENT 'Signature image filename in uploads/exam-invigilation/signatures/'
        AFTER `contact_number`;
