-- ---------------------------------------------------------------------------
-- Student Transfer — Department Transfer & Batch Transfer
--
-- One row per transfer EVENT (a permanent history/log entry — transfers are
-- never edited or undone here; to reverse one, record another transfer in
-- the opposite direction, same as a real academic office would).
--
--   kind = 'department' — a real, one-way move: the student's department AND
--     (optionally) academic program are changed on `students` itself, and the
--     student's Student ID may optionally be reissued for the new
--     department/program cohort. This is DIFFERENT from the pre-existing
--     `student_batch_transfers` table, which only adds a student to a second
--     batch without changing anything (see student-batch-transfer.sql).
--
--   kind = 'batch' — a real, one-way move of `students.batch_id` / `batch`
--     to a different batch. This is a separate, new mechanism from
--     `student_batch_transfers` (dual-batch membership) — the two coexist:
--     that table keeps handling "also show this student in another batch",
--     this table handles "this student's batch has actually changed".
--
-- Every from_*/to_* name column is a snapshot taken at transfer time, so the
-- history reads correctly even if a department/program/batch is later
-- renamed or deactivated.
--
-- Fee package handling (department transfers only): a student's fee package
-- (sfp_packages) has no DB relationship to department/program at all — see
-- STUDENT-FEE-ARCHITECTURE.md — so a department/program change never touches
-- it automatically. `package_action` records what the admin decided to do
-- about the OLD package (if any) at the time of the transfer:
--   'none'                 — not yet reviewed (still needs a decision)
--   'ended'                — the old package had no payments and was removed
--   'kept'                 — admin reviewed and chose to leave it unchanged
--   'blocked_has_payments' — could not be removed automatically (has recorded
--                            payments); must be handled manually in Student Accounts
--
-- Run this once against the application database.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `student_transfers` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`        int(10) UNSIGNED NOT NULL,
  `kind`               enum('department','batch') NOT NULL,

  -- Department transfer fields (kind = 'department')
  `from_dept_id`       int(10) UNSIGNED DEFAULT NULL,
  `to_dept_id`         int(10) UNSIGNED DEFAULT NULL,
  `from_dept_name`     varchar(200) DEFAULT NULL COMMENT 'Snapshot at transfer time',
  `to_dept_name`       varchar(200) DEFAULT NULL COMMENT 'Snapshot at transfer time',
  `from_program_id`    int(10) UNSIGNED DEFAULT NULL,
  `to_program_id`      int(10) UNSIGNED DEFAULT NULL,
  `from_program_name`  varchar(200) DEFAULT NULL COMMENT 'Snapshot at transfer time',
  `to_program_name`    varchar(200) DEFAULT NULL COMMENT 'Snapshot at transfer time',
  `old_student_id`     varchar(20)  DEFAULT NULL COMMENT 'Student ID string before this transfer',
  `new_student_id`     varchar(20)  DEFAULT NULL COMMENT 'Student ID string after this transfer (same as old when unchanged)',

  -- Batch transfer fields (kind = 'batch')
  `from_batch_id`      int(10) UNSIGNED DEFAULT NULL,
  `to_batch_id`        int(10) UNSIGNED DEFAULT NULL,
  `from_batch_name`    varchar(100) DEFAULT NULL COMMENT 'Snapshot at transfer time',
  `to_batch_name`      varchar(100) DEFAULT NULL COMMENT 'Snapshot at transfer time',

  -- Fee package outcome (department transfers only — see notes above)
  `old_package_id`     int(10) UNSIGNED DEFAULT NULL COMMENT 'sfp_packages.id that existed at transfer time, if any',
  `package_action`      enum('none','ended','kept','blocked_has_payments') NOT NULL DEFAULT 'none',
  `new_package_id`      int(10) UNSIGNED DEFAULT NULL COMMENT 'sfp_packages.id assigned afterwards, once linked back',

  `reason`             text DEFAULT NULL,
  `created_by`         int(10) UNSIGNED DEFAULT NULL,
  `created_at`         datetime NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY (`id`),
  KEY `idx_st_student` (`student_id`),
  KEY `idx_st_kind` (`kind`),
  KEY `idx_st_to_dept` (`to_dept_id`),
  KEY `idx_st_to_batch` (`to_batch_id`),
  CONSTRAINT `fk_st_student`      FOREIGN KEY (`student_id`)     REFERENCES `students` (`id`)              ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_st_from_dept`    FOREIGN KEY (`from_dept_id`)   REFERENCES `dept_departments` (`id`)      ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_st_to_dept`      FOREIGN KEY (`to_dept_id`)     REFERENCES `dept_departments` (`id`)      ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_st_from_program` FOREIGN KEY (`from_program_id`) REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_st_to_program`   FOREIGN KEY (`to_program_id`)  REFERENCES `dept_academic_programs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_st_from_batch`   FOREIGN KEY (`from_batch_id`)  REFERENCES `student_batches` (`id`)       ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_st_to_batch`     FOREIGN KEY (`to_batch_id`)    REFERENCES `student_batches` (`id`)       ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_st_old_package`  FOREIGN KEY (`old_package_id`) REFERENCES `sfp_packages` (`id`)          ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_st_new_package`  FOREIGN KEY (`new_package_id`) REFERENCES `sfp_packages` (`id`)          ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
