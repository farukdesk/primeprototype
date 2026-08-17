-- ═══════════════════════════════════════════════════════════════════════════
-- OLD ERP Payable Cross-Check v1
-- Stores the "Payable Amount" read from the OLD ERP proof screenshot so it
-- can be auto-compared against the new-ERP Grand Total (±50 BDT tolerance).
-- Run once on the live database.
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE sfp_packages
    ADD COLUMN old_erp_payable_amount DECIMAL(12,2) NULL DEFAULT NULL
        COMMENT 'Payable Amount read from the OLD ERP proof screenshot (OCR or manual)',
    ADD COLUMN old_erp_payable_source VARCHAR(10) NULL DEFAULT NULL
        COMMENT 'How the payable amount was captured: ocr | manual',
    ADD COLUMN old_erp_checked_at DATETIME NULL DEFAULT NULL
        COMMENT 'When the payable amount was last read / saved';
