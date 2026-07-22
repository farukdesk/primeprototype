-- ═══════════════════════════════════════════════════════════════════════════
-- Project Fee (one-time) – v1
-- ═══════════════════════════════════════════════════════════════════════════
-- Adds a one-time "Project Fee" to the protected student fee packages
-- (sfp_packages). The fee is stored (snapshotted) in the DATABASE, not in
-- code, so it is protected the same way as every other package fee constant:
-- editing course/programme fees or re-importing CSVs will NOT change it.
--
-- Default is 0.00 for every batch. Batch 261 is set to 3,000.00.
--
-- SAFETY CHECKLIST (run on a staging copy first, and take a backup):
--   1. mysqldump the sfp_packages and sfp_payments tables before running.
--   2. Verify the live fee_type ENUM matches the list below before running
--      step 2 (phpMyAdmin → sfp_payments → Structure, or):
--        SHOW COLUMNS FROM sfp_payments LIKE 'fee_type';
--      If the live list differs, append 'project_fee' to the LIVE list
--      instead of replacing it with this one.
--   3. Run inside a transaction where possible and verify counts before
--      committing (verification queries at the bottom).
-- ═══════════════════════════════════════════════════════════════════════════

-- ── 1. Snapshot column on the protected fee package ─────────────────────────
-- DECIMAL like the other money columns; NOT NULL DEFAULT 0.00 so every
-- existing and future package is 0 unless explicitly assigned.
ALTER TABLE `sfp_packages`
  ADD COLUMN `project_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'One-time Project Fee (snapshotted; 0.00 for batches without one)'
  AFTER `form_id_fee`;

-- ── 2. Allow project-fee payments to be recorded ────────────────────────────
-- 'project_fee' is APPENDED at the end of the ENUM so existing stored values
-- keep their positions (metadata-only change on MySQL/MariaDB).
ALTER TABLE `sfp_payments`
  MODIFY `fee_type` ENUM(
    'admission','form_fee','id_card_fee','registration','semester_tuition',
    'fixed_fee','english_fee','retake_fee','improvement_fee',
    'special_exam_midterm','special_exam_final','other','project_fee'
  ) NOT NULL;

-- ── 3. Assign 3,000.00 Project Fee to batch 261 only ────────────────────────
-- Matches the same batch-membership rule used by the student list filter:
--   • students whose home batch is 261 (students.batch_id), plus
--   • students actively transferred INTO batch 261 (student_batch_transfers).
-- Remove the transfer clause below if transferred-in students must NOT be
-- charged the project fee.
UPDATE `sfp_packages` p
JOIN `students` s ON s.id = p.student_id
SET p.project_fee = 3000.00
WHERE s.batch_id = (SELECT b.id FROM `student_batches` b WHERE b.name = '261' LIMIT 1)
   OR s.id IN (
        SELECT t.student_id
        FROM `student_batch_transfers` t
        JOIN `student_batches` tb ON tb.id = t.to_batch_id
        WHERE tb.name = '261' AND t.is_active = 1
   );

-- ═══════════════════════════════════════════════════════════════════════════
-- VERIFICATION
-- ═══════════════════════════════════════════════════════════════════════════
-- a) How many packages received the fee (should equal the number of batch-261
--    students that have a fee package):
--      SELECT COUNT(*) FROM sfp_packages WHERE project_fee = 3000.00;
--
-- b) Spot-check the affected students:
--      SELECT s.student_id, s.full_name, b.name AS batch, p.project_fee
--      FROM sfp_packages p
--      JOIN students s ON s.id = p.student_id
--      LEFT JOIN student_batches b ON b.id = s.batch_id
--      WHERE p.project_fee > 0
--      ORDER BY s.student_id;
--
-- c) Confirm no other batch was touched:
--      SELECT COUNT(*) FROM sfp_packages p
--      JOIN students s ON s.id = p.student_id
--      LEFT JOIN student_batches b ON b.id = s.batch_id
--      WHERE p.project_fee > 0 AND (b.name IS NULL OR b.name <> '261')
--        AND s.id NOT IN (
--            SELECT t.student_id FROM student_batch_transfers t
--            JOIN student_batches tb ON tb.id = t.to_batch_id
--            WHERE tb.name = '261' AND t.is_active = 1);
--      -- expected: 0
--
-- NOTES
--   • Packages assigned to batch-261 students AFTER this migration default to
--     0.00 — re-run step 3 (or set project_fee manually) for those students.
--   • This direct UPDATE bypasses the app change_log; keep this file and the
--     run date/operator on record for the audit trail.
--   • Rollback: UPDATE sfp_packages SET project_fee = 0 WHERE project_fee = 3000.00;
--     (only safe before any 'project_fee' payments are collected).
