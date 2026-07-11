-- ---------------------------------------------------------------------------
-- Student Management — Individual Batch Transfer
--
-- Lets an individual student be transferred to another batch while keeping
-- their original (home) batch intact.  After a transfer the student appears in
-- BOTH batches on the student list: their home batch (via students.batch_id)
-- and the target batch (via an active row in this table), where they are
-- clearly marked as a transfer student.
--
-- A student may have several active transfers (e.g. transferred across two
-- different batches).  Setting `is_active = 0` (or deleting the row) reverts a
-- transfer without touching the student's home batch.
--
-- Run this once against the application database.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `student_batch_transfers` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id`     int(10) UNSIGNED NOT NULL,
  `from_batch_id`  int(10) UNSIGNED DEFAULT NULL COMMENT 'Home batch at time of transfer (students.batch_id)',
  `to_batch_id`    int(10) UNSIGNED NOT NULL COMMENT 'Batch the student was transferred into',
  `note`           varchar(255) DEFAULT NULL COMMENT 'Optional reason / remark',
  `is_active`      tinyint(1) NOT NULL DEFAULT 1,
  `transferred_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by`     int(10) UNSIGNED DEFAULT NULL,
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sbt_student_to_batch` (`student_id`, `to_batch_id`),
  KEY `idx_sbt_student` (`student_id`),
  KEY `idx_sbt_to_batch` (`to_batch_id`),
  KEY `idx_sbt_from_batch` (`from_batch_id`),
  CONSTRAINT `fk_sbt_student`    FOREIGN KEY (`student_id`)    REFERENCES `students` (`id`)         ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sbt_from_batch` FOREIGN KEY (`from_batch_id`) REFERENCES `student_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sbt_to_batch`   FOREIGN KEY (`to_batch_id`)   REFERENCES `student_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
