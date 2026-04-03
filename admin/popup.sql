-- ─────────────────────────────────────────────────────────────
--  Popup Module
--  Run once against the admin_primepnew2026 database.
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `popup_settings` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `setting_key`   VARCHAR(100)  NOT NULL UNIQUE,
    `setting_value` TEXT,
    `updated_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `popup_settings` (`setting_key`, `setting_value`) VALUES
    ('is_active',      '0'),
    ('popup_type',     'text'),
    ('title',          'Welcome to Prime University'),
    ('content',        '<p>Discover academic excellence, vibrant campus life, and a gateway to a brighter future. Applications are now open!</p>'),
    ('image',          NULL),
    ('image_alt',      ''),
    ('image_link',     ''),
    ('btn_text',       'Explore Programs'),
    ('btn_url',        'admission.php'),
    ('btn_target',     '_self'),
    ('delay_seconds',  '1'),
    ('expire_hours',   '12')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
