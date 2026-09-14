-- ---------------------------------------------------------------------------
-- Third-party Student API (v1) – update / delete scopes
--
-- Adds the two new scopes used by admin/api/v1/students/update.php and
-- admin/api/v1/students/delete.php to the default scope list for NEW clients.
--
--   students:update        edit students created by the same API client
--   students:delete        permanently delete students created by the same client
--   students:update:any /  ...:delete:any  also allow students created elsewhere
--                          (grant only to trusted integrations)
--
-- Existing clients keep their current scopes. Grant explicitly, e.g.:
--   UPDATE api_clients
--      SET scopes = CONCAT(scopes, ',students:update,students:delete')
--    WHERE id = <client_id>
--      AND scopes NOT LIKE '%students:update%';
-- ---------------------------------------------------------------------------

ALTER TABLE `api_clients`
    ALTER COLUMN `scopes` SET DEFAULT 'students:create,students:update,students:delete,results:create,reference:read';
