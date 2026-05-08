-- ============================================================
-- Accounting – Enforce uniqueness of transaction_number
-- Run after accounting-student-payment.sql and accounting-admission-payment.sql
-- ============================================================

-- Prevent the same external transaction/challan number from being recorded
-- more than once across all payment tables.
--
-- NOTE: In MySQL/MariaDB a UNIQUE index on a nullable column allows multiple
-- NULL values (each NULL is treated as distinct), so no existing cash-payment
-- rows (where transaction_number IS NULL) are affected.

ALTER TABLE `sfp_payments`
    ADD UNIQUE INDEX `uq_sfpp_txn` (`transaction_number`);

ALTER TABLE `adm_admission_fee_payments`
    ADD UNIQUE INDEX `uq_afp_txn` (`transaction_number`);
