-- ============================================================================
-- Exam Invigilation: unique-slot payees  (v1)
-- ============================================================================
-- Requires: ei-office-departments-v1.sql (dept_type column on dept_departments).
--
-- Some employees are paid per exam SITTING (unique slot_date + time_slot)
-- instead of per room duty:
--   * Office staff (Controller of Examinations, Office of the Treasurer,
--     Office of Accounts & Audit) are paid on ALL sittings of the exam.
--   * Department staff flagged pay_by_unique_slot are paid only on their
--     OWN department's sittings.
-- Per-sitting attendance is stored in ei_unique_slot_attendance; sittings
-- marked absent are NOT paid.
--
-- Run this BEFORE deploying the matching code changes.
-- ============================================================================

ALTER TABLE ei_faculty
    ADD COLUMN pay_by_unique_slot TINYINT(1) NOT NULL DEFAULT 0 AFTER remuneration_per_slot;

CREATE TABLE IF NOT EXISTS ei_unique_slot_attendance (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id INT NOT NULL,
    faculty_id INT NOT NULL,
    slot_date DATE NOT NULL,
    time_slot VARCHAR(100) NOT NULL,
    attended TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_sitting (exam_id, faculty_id, slot_date, time_slot),
    KEY idx_exam (exam_id),
    KEY idx_faculty (faculty_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
