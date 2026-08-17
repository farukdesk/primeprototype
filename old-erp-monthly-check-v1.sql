-- ═══════════════════════════════════════════════════════════════════════════
-- OLD ERP Monthly Payment Cross-Check v1
-- Stores the "Monthly Payment" read from the OLD ERP proof screenshot so it
-- can be auto-compared against the Month-wise Breakdown (Semester 1) monthly
-- total (±5 BDT tolerance). Run once on the live database, after
-- old-erp-payable-check-v1.sql.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE sfp_packages
    ADD COLUMN old_erp_monthly_amount DECIMAL(12,2) NULL DEFAULT NULL
        COMMENT 'Monthly Payment read from the OLD ERP proof screenshot (OCR or manual)';
