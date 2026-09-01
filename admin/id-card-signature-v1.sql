-- ============================================================================
-- ID Card – Registrar Signature (v1)
-- Run once after deploying the ID card signature feature.
--
-- • idc_settings: generic key/value settings store for the ID card module
--   (current signature path + signature area position/size).
-- • idc_cards.signature_path: per-card SNAPSHOT of the signature that was
--   current when the card was created — updated signatures therefore apply
--   to newly created cards only; existing cards keep what they were issued
--   with (NULL = original design artwork, untouched).
-- ============================================================================

CREATE TABLE IF NOT EXISTS idc_settings (
    setting_key   VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE idc_cards
    ADD COLUMN signature_path VARCHAR(255) NULL DEFAULT NULL AFTER photo;
