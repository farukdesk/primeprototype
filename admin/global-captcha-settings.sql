-- ============================================================================
-- Global CAPTCHA Settings Migration
-- ============================================================================
-- Adds Google reCAPTCHA settings for all public forms (apply-now, contact,
-- certificate-verification, student-enrollment-status, faculty-register, jobs)
-- ============================================================================

-- Create global_settings table if it doesn't exist
CREATE TABLE IF NOT EXISTS global_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add CAPTCHA settings to global_settings table
INSERT INTO global_settings (`key`, `value`)
VALUES
    ('captcha_enabled', '0'),
    ('captcha_site_key', ''),
    ('captcha_secret_key', '')
ON DUPLICATE KEY UPDATE
    `key` = VALUES(`key`);

-- ============================================================================
-- Instructions:
-- ============================================================================
-- 1. Get your reCAPTCHA keys from https://www.google.com/recaptcha/admin
-- 2. Choose reCAPTCHA v2 "I'm not a robot" checkbox
-- 3. Add your domain: primeuniversity.ac.bd
-- 4. Update the settings via Admin > Settings > CAPTCHA Settings page
-- 5. Enable CAPTCHA protection for all public forms
-- ============================================================================
