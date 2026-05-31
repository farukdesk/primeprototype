-- ============================================================
-- Admissions Module – Address Detail Upgrade
-- Adds structured address fields (area, district, thana, post code)
-- to admissions_applications for both present and permanent addresses.
-- Run after admissions.sql and admissions-v2.sql.
-- ============================================================

ALTER TABLE `admissions_applications`
    ADD COLUMN `present_area`          VARCHAR(255) NULL AFTER `present_address_2`,
    ADD COLUMN `present_district_id`   INT UNSIGNED NULL AFTER `present_area`,
    ADD COLUMN `present_thana_id`      INT UNSIGNED NULL AFTER `present_district_id`,
    ADD COLUMN `present_post_code`     VARCHAR(20)  NULL AFTER `present_thana_id`,
    ADD COLUMN `permanent_area`        VARCHAR(255) NULL AFTER `permanent_address_2`,
    ADD COLUMN `permanent_district_id` INT UNSIGNED NULL AFTER `permanent_area`,
    ADD COLUMN `permanent_thana_id`    INT UNSIGNED NULL AFTER `permanent_district_id`,
    ADD COLUMN `permanent_post_code`   VARCHAR(20)  NULL AFTER `permanent_thana_id`;

-- ── Updated field label seeds ─────────────────────────────────────────────────
-- Rename existing address line labels to reflect new meaning
UPDATE `admissions_fields` SET `field_label` = 'Present: House No./Building Name' WHERE `field_key` = 'present_address_1';
UPDATE `admissions_fields` SET `field_label` = 'Present: Road Name/Street'        WHERE `field_key` = 'present_address_2';
UPDATE `admissions_fields` SET `field_label` = 'Present: Mobile Number'           WHERE `field_key` = 'present_contact';
UPDATE `admissions_fields` SET `field_label` = 'Permanent: House No./Building Name' WHERE `field_key` = 'permanent_address_1';
UPDATE `admissions_fields` SET `field_label` = 'Permanent: Road Name/Street'       WHERE `field_key` = 'permanent_address_2';
UPDATE `admissions_fields` SET `field_label` = 'Permanent: Mobile Number'          WHERE `field_key` = 'permanent_contact';

-- ── New field seeds ───────────────────────────────────────────────────────────
INSERT IGNORE INTO `admissions_fields` (`field_key`, `field_label`, `sort_order`) VALUES
('present_area',          'Present: Area/Locality',      9),
('present_district',      'Present: District',           10),
('present_thana',         'Present: Thana/Upazila',      11),
('present_post_code',     'Present: Post Code',          12),
('permanent_area',        'Permanent: Area/Locality',    13),
('permanent_district',    'Permanent: District',         14),
('permanent_thana',       'Permanent: Thana/Upazila',    15),
('permanent_post_code',   'Permanent: Post Code',        16);
