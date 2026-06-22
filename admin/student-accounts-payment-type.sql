-- ---------------------------------------------------------------------------
-- Student fee packages: payment type (Fixed vs Merit based)
--
-- A package can either be:
--   * 'merit' (default) – the monthly fee is derived from the per-semester
--     tuition/fixed/English calculation and may change when tuition is edited
--     or scholarships/concessions are applied.
--   * 'fixed' – the student pays a flat `monthly_payment` every month. This
--     amount never changes automatically; it only changes when an admin edits
--     the package manually or a manual scholarship is added to a semester.
--
-- `monthly_payment` stores the agreed flat monthly amount (used only when
-- payment_type = 'fixed'). For merit-based packages it stays 0 and is ignored.
--
-- Run this once against the application database.
-- ---------------------------------------------------------------------------

ALTER TABLE `sfp_packages`
    ADD COLUMN `payment_type` ENUM('merit','fixed') NOT NULL DEFAULT 'merit'
        COMMENT 'merit = calculated monthly fee; fixed = flat monthly_payment that never changes automatically'
        AFTER `program_name`,
    ADD COLUMN `monthly_payment` DECIMAL(10,2) NOT NULL DEFAULT 0.00
        COMMENT 'Flat agreed monthly fee, used only when payment_type = fixed'
        AFTER `payment_type`;
