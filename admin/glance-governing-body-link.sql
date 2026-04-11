-- Migration: Link governing_body_members to PU At a Glance page
-- Adds fields so Board of Trustees members can be selected for the
-- "Key Administrative Officers" and "Words from Our Leadership" sections
-- on pu-at-a-glance.php without duplicating data.

ALTER TABLE `governing_body_members`
    ADD COLUMN `glance_officer`   TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT 'Show in Key Administrative Officers on PU At a Glance' AFTER `is_featured`,
    ADD COLUMN `glance_msg_tab`   VARCHAR(120) DEFAULT NULL
        COMMENT 'Tab label for Words from Our Leadership section'       AFTER `glance_officer`,
    ADD COLUMN `glance_message`   TEXT         DEFAULT NULL
        COMMENT 'Message body for Words from Our Leadership section'    AFTER `glance_msg_tab`;

-- Index to speed up the glance page queries
ALTER TABLE `governing_body_members`
    ADD KEY `idx_glance_officer` (`page_type`, `glance_officer`),
    ADD KEY `idx_glance_message` (`page_type`, `glance_msg_tab`(20));
