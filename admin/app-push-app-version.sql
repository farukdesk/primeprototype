-- ============================================================================
-- App version tracking for registered push devices
-- ============================================================================
-- Adds an `app_version` column to the push token tables so the Registered App
-- Devices page (admin/app-notifications/devices.php) can show which version of
-- the mobile app every student / employee device is running. The Android app
-- reports its version when registering the FCM token.
--
-- Safe to run multiple times (uses IF NOT EXISTS – MariaDB 10.x).
-- ============================================================================

ALTER TABLE `student_push_tokens`
    ADD COLUMN IF NOT EXISTS `app_version` varchar(30) DEFAULT NULL COMMENT 'App version reported by the device';

ALTER TABLE `api_push_tokens`
    ADD COLUMN IF NOT EXISTS `app_version` varchar(30) DEFAULT NULL COMMENT 'App version reported by the device';
