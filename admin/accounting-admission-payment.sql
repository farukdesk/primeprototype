-- ============================================================
-- Accounting – Admission Fee Payments for Pre-Enrollment Applicants
-- Run after admissions.sql, admissions-form-sale.sql, admissions-student-id.sql
-- and accounting.sql
-- ============================================================

-- Tracks admission fee payments collected for applicants who do not yet
-- have a student record (no student_id assigned yet).
CREATE TABLE IF NOT EXISTS `adm_admission_fee_payments` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `application_id` INT UNSIGNED  NOT NULL
                     COMMENT 'admissions_applications.id',
    `voucher_id`     INT UNSIGNED  NOT NULL
                     COMMENT 'acc_vouchers.id',
    `amount`         DECIMAL(12,2) NOT NULL,
    `collected_by`   INT UNSIGNED  NULL
                     COMMENT 'users.id of the staff member who collected',
    `collected_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_afp_application` (`application_id`),
    INDEX `idx_afp_voucher`     (`voucher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Admission fee payments collected before a student ID is assigned';
