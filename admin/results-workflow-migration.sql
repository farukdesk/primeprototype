-- ─────────────────────────────────────────────────────────────────────────────
-- Results Workflow – Configurable Approval Chain Migration
-- Admin defines chains per dept/program; no hard-coded user-group roles.
-- Run AFTER results.sql and database.sql
-- ─────────────────────────────────────────────────────────────────────────────

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. wf_chains – named workflow chains (one per dept or dept+program)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `wf_chains` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(200)  NOT NULL                 COMMENT 'Human-readable chain name',
  `description` TEXT          DEFAULT NULL,
  `dept_id`     INT UNSIGNED  DEFAULT NULL             COMMENT 'NULL = global / all depts',
  `program_id`  INT UNSIGNED  DEFAULT NULL             COMMENT 'NULL = all programs in dept',
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_by`  INT UNSIGNED  DEFAULT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_wfc_dept`    (`dept_id`),
  KEY `idx_wfc_program` (`program_id`),
  CONSTRAINT `fk_wfc_dept`    FOREIGN KEY (`dept_id`)    REFERENCES `dept_departments`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_wfc_program` FOREIGN KEY (`program_id`) REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wfc_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)                  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Named workflow chains – one per dept/program scope';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. wf_chain_steps – ordered steps within a chain
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `wf_chain_steps` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `chain_id`    INT UNSIGNED  NOT NULL,
  `step_order`  TINYINT UNSIGNED NOT NULL             COMMENT 'Ascending: 1, 2, 3…',
  `step_label`  VARCHAR(200)  NOT NULL                COMMENT 'e.g. Course Teacher, HOD',
  `group_id`    INT UNSIGNED  NOT NULL                COMMENT 'FK → user_groups.id',
  `is_entry`    TINYINT(1)    NOT NULL DEFAULT 0      COMMENT '1 = this step submits the sheet',
  `is_final`    TINYINT(1)    NOT NULL DEFAULT 0      COMMENT '1 = approving this step publishes',
  UNIQUE KEY `uq_chain_step`  (`chain_id`, `step_order`),
  KEY `idx_wfcs_chain`  (`chain_id`),
  KEY `idx_wfcs_group`  (`group_id`),
  CONSTRAINT `fk_wfcs_chain` FOREIGN KEY (`chain_id`) REFERENCES `wf_chains`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_wfcs_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups`(`id`)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Ordered approval steps within a workflow chain';

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. result_mark_sheets – one sheet per subject/semester/chain instance
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `result_mark_sheets` (
  `id`                 INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `chain_id`           INT UNSIGNED  DEFAULT NULL             COMMENT 'FK → wf_chains.id',
  `current_step_order` TINYINT UNSIGNED DEFAULT NULL          COMMENT 'Step awaiting action',
  `dept_id`            INT UNSIGNED  NOT NULL,
  `program_id`         INT UNSIGNED  DEFAULT NULL,
  `semester`           VARCHAR(100)  NOT NULL,
  `academic_year`      VARCHAR(20)   DEFAULT NULL,
  `curriculum_id`      INT UNSIGNED  DEFAULT NULL,
  `subject_code`       VARCHAR(50)   DEFAULT NULL,
  `subject_title`      VARCHAR(300)  NOT NULL,
  `credits`            DECIMAL(4,2)  DEFAULT NULL,
  `workflow_status`    ENUM('draft','pending','returned','published') NOT NULL DEFAULT 'draft',
  `created_by`         INT UNSIGNED  DEFAULT NULL,
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_rms_chain`   (`chain_id`),
  KEY `idx_rms_dept`    (`dept_id`),
  KEY `idx_rms_program` (`program_id`),
  KEY `idx_rms_status`  (`workflow_status`),
  KEY `idx_rms_creator` (`created_by`),
  CONSTRAINT `fk_rms_chain`       FOREIGN KEY (`chain_id`)     REFERENCES `wf_chains`(`id`)              ON DELETE SET NULL,
  CONSTRAINT `fk_rms_dept`        FOREIGN KEY (`dept_id`)      REFERENCES `dept_departments`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_rms_program`     FOREIGN KEY (`program_id`)   REFERENCES `dept_academic_programs`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rms_curriculum`  FOREIGN KEY (`curriculum_id`) REFERENCES `course_curriculum`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_rms_creator`     FOREIGN KEY (`created_by`)   REFERENCES `users`(`id`)                 ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Workflow mark sheet header (one per subject per semester)';

-- ─────────────────────────────────────────────────────────────────────────────
-- 4. result_sheet_grades – student marks within a sheet
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `result_sheet_grades` (
  `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sheet_id`     INT UNSIGNED   NOT NULL,
  `student_id`   INT UNSIGNED   DEFAULT NULL,
  `student_sid`  VARCHAR(25)    NOT NULL,
  `student_name` VARCHAR(200)   DEFAULT NULL,
  `is_absent`    TINYINT(1)     NOT NULL DEFAULT 0,
  `attendance`   DECIMAL(5,2)   DEFAULT NULL,
  `class_test`   DECIMAL(5,2)   DEFAULT NULL,
  `mid_term`     DECIMAL(5,2)   DEFAULT NULL,
  `final_exam`   DECIMAL(5,2)   DEFAULT NULL,
  `total_marks`  DECIMAL(5,2)   DEFAULT NULL,
  `letter_grade` VARCHAR(10)    DEFAULT NULL,
  `grade_point`  DECIMAL(4,2)   DEFAULT NULL,
  `created_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_sheet_student` (`sheet_id`, `student_sid`),
  KEY `idx_rsg_sheet`   (`sheet_id`),
  KEY `idx_rsg_student` (`student_id`),
  CONSTRAINT `fk_rsg_sheet`   FOREIGN KEY (`sheet_id`)   REFERENCES `result_mark_sheets`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rsg_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 5. wf_sheet_history – full audit trail of every action on a sheet
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `wf_sheet_history` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sheet_id`         INT UNSIGNED  NOT NULL,
  `step_order`       TINYINT UNSIGNED NOT NULL,
  `step_label`       VARCHAR(200)  DEFAULT NULL,
  `group_id`         INT UNSIGNED  DEFAULT NULL,
  `action`           ENUM('created','submitted','approved','returned','published') NOT NULL,
  `acted_by`         INT UNSIGNED  DEFAULT NULL,
  `acted_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `remarks`          TEXT          DEFAULT NULL,
  `returned_to_step` TINYINT UNSIGNED DEFAULT NULL COMMENT 'Set on return actions',
  KEY `idx_wfsh_sheet` (`sheet_id`),
  CONSTRAINT `fk_wfsh_sheet` FOREIGN KEY (`sheet_id`) REFERENCES `result_mark_sheets`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wfsh_user`  FOREIGN KEY (`acted_by`) REFERENCES `users`(`id`)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Audit trail: every workflow action on every mark sheet';

-- ─────────────────────────────────────────────────────────────────────────────
-- 6. Register module slugs
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `is_active`)
VALUES
  ('Result Workflow', 'results-workflow', 'Mark sheet workflow (submit, approve, publish)', 1),
  ('Result Chains',   'results-chains',   'Admin: configure approval chain templates',      1);

SET FOREIGN_KEY_CHECKS = 1;
