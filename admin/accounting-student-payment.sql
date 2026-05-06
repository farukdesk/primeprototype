-- ============================================================
-- Student Fee Payment Tracking
-- Run AFTER accounting.sql AND student-fee-package.sql
-- ============================================================
--
-- Purpose
-- -------
-- sfp_payments records every actual cash receipt that is tied to
-- a specific student fee obligation.  Each row is always backed by
-- an acc_vouchers receipt voucher so the money flows through the
-- double-entry ledger automatically.
--
-- Fee types
-- ---------
--   admission          – one-time admission-day payment
--   registration       – per-semester registration fee
--   semester_tuition   – semester tuition (may be partial)
--   fixed_fee          – share of fixed institutional fee
--   english_fee        – share of English course fee
--   other              – any miscellaneous student charge
--
-- Outstanding balance = obligation − SUM(sfp_payments.amount)
-- The obligation amounts come from sfp_packages / sfp_semester_fees.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `sfp_payments` (
    `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `student_id`      INT UNSIGNED      NOT NULL
                      COMMENT 'FK students.id',
    `package_id`      INT UNSIGNED      NOT NULL
                      COMMENT 'FK sfp_packages.id',
    `semester_fee_id` INT UNSIGNED      DEFAULT NULL
                      COMMENT 'FK sfp_semester_fees.id – NULL for admission/registration/other',
    `fee_type`        ENUM(
                          'admission',
                          'registration',
                          'semester_tuition',
                          'fixed_fee',
                          'english_fee',
                          'other'
                      ) NOT NULL,
    `semester_number` TINYINT UNSIGNED  DEFAULT NULL
                      COMMENT 'Semester number (1-based) – mirrors semester_fee_id.semester_number for easy filtering',
    `amount`          DECIMAL(10,2)     NOT NULL
                      COMMENT 'Amount actually received in this payment',
    `voucher_id`      INT UNSIGNED      NOT NULL
                      COMMENT 'FK acc_vouchers.id – the receipt voucher that recorded the money',
    `note`            TEXT              DEFAULT NULL,
    `collected_by`    INT UNSIGNED      DEFAULT NULL,
    `collected_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sfpp_student`  (`student_id`),
    KEY `idx_sfpp_package`  (`package_id`),
    KEY `idx_sfpp_sem_fee`  (`semester_fee_id`),
    KEY `idx_sfpp_type`     (`fee_type`),
    KEY `idx_sfpp_voucher`  (`voucher_id`),
    CONSTRAINT `fk_sfpp_student`   FOREIGN KEY (`student_id`)      REFERENCES `students`(`id`)           ON DELETE CASCADE,
    CONSTRAINT `fk_sfpp_package`   FOREIGN KEY (`package_id`)      REFERENCES `sfp_packages`(`id`)       ON DELETE CASCADE,
    CONSTRAINT `fk_sfpp_sem_fee`   FOREIGN KEY (`semester_fee_id`) REFERENCES `sfp_semester_fees`(`id`)  ON DELETE SET NULL,
    CONSTRAINT `fk_sfpp_voucher`   FOREIGN KEY (`voucher_id`)      REFERENCES `acc_vouchers`(`id`),
    CONSTRAINT `fk_sfpp_collector` FOREIGN KEY (`collected_by`)    REFERENCES `users`(`id`)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Actual student fee payments; each row is backed by an acc_vouchers receipt';

SET FOREIGN_KEY_CHECKS = 1;
