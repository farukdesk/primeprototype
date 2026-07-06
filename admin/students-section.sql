-- ---------------------------------------------------------------------------
-- Student Section
--
-- Adds an academic `section` classification (A–G) to each student record.
-- Used on the student profile and as a filter on the student list.
--
-- Run this once against the application database.
-- ---------------------------------------------------------------------------

ALTER TABLE `students`
    ADD COLUMN `section` varchar(5) DEFAULT NULL
        COMMENT 'Class section, e.g. A, B, C, D, E, F, G'
        AFTER `shift`;
