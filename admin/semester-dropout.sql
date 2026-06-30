-- =============================================================================
-- Semester Drop / Dropout
-- =============================================================================
-- Extends the existing Semester Drop module so the same screen can also record
-- an *official dropout*: a student who has formally left the university and no
-- longer communicates with us.
--
--   * kind = 'drop'    -> existing semester drop (deferral of monthly tuition)
--   * kind = 'dropout' -> official dropout. From the chosen effective date the
--                         student's account is FROZEN: it is no longer counted
--                         as a due in any financial fact, and the student's
--                         status is set to 'Dropped'.
--
-- Re-instating a dropout requires supporting evidence and a comment, captured
-- in the existing cancel_* columns plus the new reinstate_evidence_file_id.
--
-- Idempotent: safe to run more than once (MariaDB IF NOT EXISTS).
-- =============================================================================

-- ── Record kind + reinstatement evidence on semester_drops ───────────────────
ALTER TABLE `semester_drops`
  ADD COLUMN IF NOT EXISTS `kind`
      ENUM('drop','dropout') NOT NULL DEFAULT 'drop'
      COMMENT 'drop = semester deferral, dropout = official freeze'
      AFTER `student_id`;

ALTER TABLE `semester_drops`
  ADD COLUMN IF NOT EXISTS `reinstate_evidence_file_id`
      int(10) UNSIGNED DEFAULT NULL
      COMMENT 'FK student_files.id – evidence uploaded when re-instating a dropout'
      AFTER `cancel_reason`;

-- Helpful index for the freeze lookups (active dropout per student).
ALTER TABLE `semester_drops`
  ADD INDEX IF NOT EXISTS `idx_sd_kind_status` (`student_id`, `kind`, `status`);

-- ── Rename the module so it reads "Semester Drop / Dropout" everywhere ───────
UPDATE `modules`
   SET `name`        = 'Semester Drop / Dropout',
       `description` = 'Record semester drops (deferral) and official dropouts (account freeze).'
 WHERE `slug` = 'semester-drop';
