-- Exam Invigilation: Fixed Payment option for unique-slot payees
-- Run once. Requires ei-unique-slot-payees-v1.sql to have been run first.
--
-- Fixed payees belong to the same unique-slot payee group (per-sitting
-- attendance is still marked), but the remuneration bill pays a fixed
-- amount per exam instead of rate x attended sittings.

ALTER TABLE ei_faculty
    ADD COLUMN pay_fixed TINYINT(1) NOT NULL DEFAULT 0 AFTER pay_by_unique_slot,
    ADD COLUMN fixed_payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER pay_fixed;
