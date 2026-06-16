-- Exam Invigilation – Auto-assign settings

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ei_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_val` text DEFAULT NULL,
  `created_at`  datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ei_settings` (`setting_key`, `setting_val`)
VALUES ('auto_assign_max_slots', '12')
ON DUPLICATE KEY UPDATE `setting_val` = '12';
