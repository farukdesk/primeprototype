-- Register the Transcript Maker module so it appears on the Module Access page
-- (admin/access/index.php) and can be granted to user groups / users.
--
-- The Module Access screen lists rows from the `modules` table where
-- is_active = 1. The Transcript Maker feature pages exist on disk and the
-- sidebar already gates them with can_access('transcript-maker'), but without a
-- matching `modules` row the module never shows up under Module Access, so
-- permissions can never be granted. This migration inserts that row idempotently.
--
-- Safe to run multiple times: the row is only inserted when the slug is absent.

INSERT INTO `modules`
    (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`,
     `is_active`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT
    'Transcript Maker',
    'transcript-maker',
    'Generate a student academic transcript from an uploaded tabulation sheet and export it as a Word (.doc) file.',
    'fas fa-file-word',
    NULL,
    97,
    1, 1, 1, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM `modules` WHERE `slug` = 'transcript-maker'
);
