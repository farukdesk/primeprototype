-- ============================================================================
-- Admission Fair lead source
-- Run ONCE against the application database BEFORE (or together with) deploying
-- this change. admission-fair.php saves leads.source = 'admission_fair', which
-- is rejected while `source` is an ENUM without that value.
-- Safe to re-run: MODIFY with the same definition is a no-op.
-- ============================================================================

ALTER TABLE `leads`
    MODIFY COLUMN `source`
        ENUM('online','campus_visit','agent','f2f_marketing','facebook','admission_fair')
        NOT NULL DEFAULT 'online';
