-- Exam Invigilation – Daily slot cap setting
-- Run after exam-invigilation-settings.sql

SET NAMES utf8mb4;

INSERT IGNORE INTO `ei_settings` (`setting_key`, `setting_val`)
VALUES ('auto_assign_max_slots_per_day', '3');
