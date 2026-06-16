-- ============================================================
-- Exam Invigilation – Add preferred department to slots
-- Run after exam-invigilation.sql
-- ============================================================

ALTER TABLE `ei_slots`
    ADD COLUMN `dept_id` int(10) UNSIGNED DEFAULT NULL
        COMMENT 'Preferred department for Invigilator 1 (auto-assign hint)'
        AFTER `room_number`,
    ADD KEY `idx_ei_slots_dept` (`dept_id`),
    ADD CONSTRAINT `fk_ei_slots_dept` FOREIGN KEY (`dept_id`)
        REFERENCES `dept_departments` (`id`) ON DELETE SET NULL;
