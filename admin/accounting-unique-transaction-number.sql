-- ============================================================
-- Accounting – Enforce uniqueness of transaction_number
-- Run after accounting-student-payment.sql and accounting-admission-payment.sql
-- ============================================================

-- Prevent the same external transaction/challan number from being recorded
-- more than once across all payment tables.

ALTER TABLE `sfp_payments`
    ADD UNIQUE INDEX `uq_sfpp_txn` (`transaction_number`);

ALTER TABLE `adm_admission_fee_payments`
    ADD UNIQUE INDEX `uq_afp_txn` (`transaction_number`);
