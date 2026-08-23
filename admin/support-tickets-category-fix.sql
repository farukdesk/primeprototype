-- ============================================================================
-- IT Support Tickets – category column fix
-- ============================================================================
-- Tickets created from the Android app (and the web portal) can use the newer
-- categories "Student Finances" and "Other Student Issues". If the
-- support_tickets.category column is still the original ENUM (or too short a
-- VARCHAR), MySQL silently stores an empty string for those values, so the
-- category shows up blank in the admin panel.
--
-- Converting the column to VARCHAR(60) accepts every category the apps send.
-- Safe to run multiple times.
-- ============================================================================

ALTER TABLE `support_tickets`
    MODIFY `category` VARCHAR(60) NOT NULL DEFAULT 'Other';

-- Repair existing rows whose category was truncated to an empty string.
UPDATE `support_tickets`
   SET `category` = 'Other'
 WHERE `category` = '' OR `category` IS NULL;
