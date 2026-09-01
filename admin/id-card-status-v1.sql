-- ============================================================================
-- ID Card – Print / Distribution Status workflow (v1)
-- Run once after deploying the ID card status feature.
--
-- Statuses: in_printing_queue (default) → printed → distributed → collected
-- ============================================================================

ALTER TABLE idc_cards
    ADD COLUMN print_status VARCHAR(30) NOT NULL DEFAULT 'in_printing_queue' AFTER is_active,
    ADD COLUMN print_status_updated_at DATETIME NULL DEFAULT NULL AFTER print_status,
    ADD COLUMN print_status_updated_by INT NULL DEFAULT NULL AFTER print_status_updated_at,
    ADD INDEX idx_idc_print_status (print_status);
