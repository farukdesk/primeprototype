-- Student Accounts: Bi-Tri Shift Merge fee
-- Adds a bi_tri_shift_fee column to student fee packages.
--
-- One-time fee that absorbs the Fixed Institutional Fees removed by the
-- bulk edit "Target Monthly Total" rebalance, for the few students who
-- moved from bi-semester (6-month semesters, e.g. 3 x 6 = 18 months) to
-- trimester (4-month semesters, e.g. 4 x 4 = 16 months). The two extra
-- months' worth of fees is parked here so the Grand Total (incl.
-- Admission, Form & ID Card & Project Fees) stays unchanged while the
-- monthly payment drops to the target. Stays 0.00 for all other students.
--
-- Run once BEFORE using the bulk edit "Target Monthly Total" field on
-- admin/student-accounts/index.php.

ALTER TABLE sfp_packages
    ADD COLUMN bi_tri_shift_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER project_fee;
