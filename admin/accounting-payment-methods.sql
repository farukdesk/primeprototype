-- ============================================================
-- Accounting: Payment Method & Transaction Tracking
-- Run after accounting-student-payment.sql and
-- accounting-admission-payment.sql
-- ============================================================

ALTER TABLE `sfp_payments`
  ADD COLUMN IF NOT EXISTS `payment_method` ENUM('cash','bank','mobile_banking')
    NOT NULL DEFAULT 'cash'
    COMMENT 'How payment was received'
    AFTER `month_number`,
  ADD COLUMN IF NOT EXISTS `mobile_banking_provider` ENUM('bkash','nagad','rocket')
    DEFAULT NULL
    COMMENT 'Provider when payment_method=mobile_banking'
    AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `transaction_number` VARCHAR(100)
    DEFAULT NULL
    COMMENT 'External transaction/challan/reference number for non-cash payments'
    AFTER `mobile_banking_provider`;

ALTER TABLE `adm_admission_fee_payments`
  ADD COLUMN IF NOT EXISTS `payment_method` ENUM('cash','bank','mobile_banking')
    NOT NULL DEFAULT 'cash'
    COMMENT 'How payment was received'
    AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `mobile_banking_provider` ENUM('bkash','nagad','rocket')
    DEFAULT NULL
    COMMENT 'Provider when payment_method=mobile_banking'
    AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `transaction_number` VARCHAR(100)
    DEFAULT NULL
    COMMENT 'External transaction/challan/reference number for non-cash payments'
    AFTER `mobile_banking_provider`;
