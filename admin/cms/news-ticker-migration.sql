-- -------------------------------------------------------
-- Migration: add show_in_ticker flag to cms_news
-- Run once against the live database.
-- -------------------------------------------------------
ALTER TABLE `cms_news`
    ADD COLUMN `show_in_ticker` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'When 1 the article title scrolls in the homepage news ticker'
    AFTER `is_published`;
