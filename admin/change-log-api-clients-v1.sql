-- ---------------------------------------------------------------------------
-- Change Log: attribute entries to third-party API clients
--
-- Students and results created through admin/api/v1 (e.g. from the partner
-- portal in ss-portal-api/) are not made by a logged-in admin user.  Until
-- now such changes were only logged when the API client had a `created_by`
-- user, and the Change Log page hid every row without a matching user.
--
--   * change_log.user_id        becomes nullable (NULL = performed by an API client)
--   * change_log.api_client_id  new; references api_clients.id
--
-- Run once against the application database, AFTER admin/student-api-clients-v1.sql.
-- If your change_log.user_id column is not INT, adjust the MODIFY line to the
-- same type with NULL allowed.
-- ---------------------------------------------------------------------------

ALTER TABLE `change_log`
    MODIFY COLUMN `user_id` INT DEFAULT NULL COMMENT 'users.id; NULL when the change came from a third-party API client';

ALTER TABLE `change_log`
    ADD COLUMN `api_client_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'api_clients.id when the change was made through the third-party API'
        AFTER `user_id`,
    ADD INDEX `idx_change_log_api_client` (`api_client_id`);
