-- Staff Attendance: user-group-specific holidays — v1
--
-- Adds att_holiday_groups so a holiday (att_holidays) can be limited to one
-- or more user groups instead of always applying to every staff member.
-- A holiday with NO rows in this table applies to ALL staff (the existing
-- behaviour); a holiday with rows applies only to members of those groups
-- (the primary group users.group_id or any additional membership in
-- user_group_assignments).
--
-- Safe to run once on an existing database.

CREATE TABLE IF NOT EXISTS att_holiday_groups (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  holiday_id INT UNSIGNED NOT NULL,
  group_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_holiday_group (holiday_id, group_id),
  KEY idx_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
