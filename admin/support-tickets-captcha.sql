-- ============================================================================
-- Support Tickets CAPTCHA Settings Migration
-- ============================================================================
-- Adds Google reCAPTCHA settings to prevent spam in public ticket submission
-- Run this after support-tickets-v2.sql
-- ============================================================================

-- Add CAPTCHA settings to support_settings table
INSERT INTO support_settings (`key`, `value`)
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
-- 4. Update the settings via Admin > IT Support > Settings page
-- 5. Enable CAPTCHA protection
-- ============================================================================
