-- ============================================================
-- Thana / Upazila Data Fix – v2
-- Corrects bd_thanas to match the official district-wise list.
-- Safe to run on existing databases; FK ON DELETE SET NULL keeps
-- student records intact (thana_id becomes NULL for removed rows).
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Dhaka (district_id = 1) ───────────────────────────────────
-- Add city thanas that were missing
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(1, 'Kotwali'), (1, 'Mohammadpur'), (1, 'Lalbagh'), (1, 'Sutrapur'),
(1, 'Sabujbagh'), (1, 'Pallabi'), (1, 'Cantonment'),
(1, 'Dhanmondi'), (1, 'Tejgaon'), (1, 'Ramna');

-- ── Faridpur (district_id = 2) ───────────────────────────────
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(2, 'Bhanga');

-- ── Gazipur (district_id = 3) ────────────────────────────────
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(3, 'Tongi');

-- ── Chittagong (district_id = 17) ────────────────────────────
-- Add city thanas
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(17, 'Kotwali'), (17, 'Panchlaish'), (17, 'Chandgaon'), (17, 'Bandor'),
(17, 'Pahartali'), (17, 'Double Muring');
-- Remove Chittagong Sadar and Karnaphuli (not in the official list)
DELETE FROM `bd_thanas` WHERE `district_id` = 17 AND `name` IN ('Chittagong Sadar', 'Karnaphuli');

-- ── Cumilla / Comilla (district_id = 19) ─────────────────────
-- Split Sadar into Adarsha and South; remove Lalmai
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(19, 'Cumilla Sadar Adarsha'), (19, 'Cumilla Sadar South');
DELETE FROM `bd_thanas` WHERE `district_id` = 19 AND `name` IN ('Cumilla Sadar', 'Lalmai');

-- ── Natore (district_id = 28) ─────────────────────────────────
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(28, 'Naldanga');

-- ── Rajshahi (district_id = 31) ───────────────────────────────
-- Add city thanas; remove generic Rajshahi Sadar
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(31, 'Boalia'), (31, 'Rajpara');
DELETE FROM `bd_thanas` WHERE `district_id` = 31 AND `name` = 'Rajshahi Sadar';

-- ── Khulna (district_id = 37) ─────────────────────────────────
-- Add city thanas; remove Paikgachha (not in the official list)
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(37, 'Sonadanga'), (37, 'Daulatpur');
DELETE FROM `bd_thanas` WHERE `district_id` = 37 AND `name` = 'Paikgachha';

-- ── Habiganj (district_id = 49) ───────────────────────────────
-- Remove Shayestaganj (not in the official list)
DELETE FROM `bd_thanas` WHERE `district_id` = 49 AND `name` = 'Shayestaganj';

-- ── Sylhet (district_id = 52) ─────────────────────────────────
-- Remove Osmaninagar (not in the official list)
DELETE FROM `bd_thanas` WHERE `district_id` = 52 AND `name` = 'Osmaninagar';

-- ── Mymensingh (district_id = 62) ────────────────────────────
INSERT IGNORE INTO `bd_thanas` (`district_id`, `name`) VALUES
(62, 'Tarakanda');

SET FOREIGN_KEY_CHECKS = 1;
