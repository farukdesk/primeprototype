-- Spring Result – Add credit column to sr_result_entries
-- Run AFTER spring-result.sql

ALTER TABLE `sr_result_entries`
  ADD COLUMN `credit` DECIMAL(4,2) DEFAULT NULL
    COMMENT 'Credit hours for the course'
    AFTER `grade_point`;
