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
--
-- Notes
--   * change_log.user_id carries the foreign key `fk_cl_user`; MySQL refuses to
--     MODIFY a column used in a foreign key (#1832), so the constraint is dropped
--     first and re-created afterwards with ON DELETE SET NULL.
--   * A foreign key needs identical column types (errno 150 otherwise), so the
--     new definition of user_id is built from the actual type of users.id.
-- ---------------------------------------------------------------------------

ALTER TABLE `change_log` DROP FOREIGN KEY `fk_cl_user`;

-- user_id: same type as users.id, now nullable
SET @user_type := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id');
SET @sql := CONCAT('ALTER TABLE `change_log` MODIFY COLUMN `user_id` ', @user_type,
                   ' DEFAULT NULL COMMENT ''users.id; NULL when the change came from a third-party API client''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- api_client_id: same type as api_clients.id (INT UNSIGNED in student-api-clients-v1.sql)
SET @client_type := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_clients' AND COLUMN_NAME = 'id');
SET @sql := CONCAT('ALTER TABLE `change_log` ADD COLUMN `api_client_id` ', @client_type,
                   ' DEFAULT NULL COMMENT ''api_clients.id when the change was made through the third-party API'' AFTER `user_id`,',
                   ' ADD INDEX `idx_change_log_api_client` (`api_client_id`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `change_log`
    ADD CONSTRAINT `fk_cl_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `change_log`
    ADD CONSTRAINT `fk_cl_api_client`
        FOREIGN KEY (`api_client_id`) REFERENCES `api_clients` (`id`) ON DELETE SET NULL;
