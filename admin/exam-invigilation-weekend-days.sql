-- Add per-faculty weekly weekend day support
ALTER TABLE `ei_faculty`
  ADD COLUMN `weekend_days` varchar(50) NOT NULL DEFAULT '0,6'
  COMMENT 'Faculty weekly weekend/off days; date(w) values (0=Sun..6=Sat)'
  AFTER `weekend_available`;

-- Backfill from existing weekend_available values
UPDATE `ei_faculty`
SET `weekend_days` = CASE
    WHEN `weekend_available` = 1 THEN ''
    ELSE '0,6'
END;
