-- Migration v2: Add glance_link to governing_body_members
-- Allows each Key Administrative Officer to link to their dedicated page
-- (e.g. office-of-pro-vc.php, office-of-registrar.php) from pu-at-a-glance.php

ALTER TABLE `governing_body_members`
    ADD COLUMN `glance_link` VARCHAR(255) DEFAULT NULL
        COMMENT 'Optional URL for the officer card on PU At a Glance (e.g. /office-of-pro-vc.php)'
        AFTER `glance_officer`;
