-- Governing Body Module SQL

CREATE TABLE IF NOT EXISTS `governing_body_pages` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_type`        ENUM('board-of-trustees','pu-syndicates','deans','head-of-departments') NOT NULL,
  `title`            VARCHAR(255) NOT NULL DEFAULT '',
  `subtitle`         VARCHAR(255) NOT NULL DEFAULT '',
  `hero_intro`       TEXT,
  `meta_description` TEXT,
  `is_published`     TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at`       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_page_type` (`page_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `governing_body_members` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_type`   ENUM('board-of-trustees','pu-syndicates','deans','head-of-departments') NOT NULL,
  `section`     VARCHAR(100) NOT NULL DEFAULT 'member',
  `full_name`   VARCHAR(150) NOT NULL,
  `designation` VARCHAR(150) DEFAULT NULL,
  `department`  VARCHAR(150) DEFAULT NULL,
  `bio`         TEXT,
  `photo`       VARCHAR(500) DEFAULT NULL,
  `email`       VARCHAR(191) DEFAULT NULL,
  `phone`       VARCHAR(30)  DEFAULT NULL,
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_page_section` (`page_type`,`section`),
  KEY `idx_page_sort`    (`page_type`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module registration
INSERT IGNORE INTO `modules` (`name`,`slug`,`description`,`icon`,`sort_order`,`is_active`) VALUES
('Governing Body','governing-body','Manage Board of Trustees, Syndicates, Deans & HoDs','fas fa-university',25,1);

-- Default page settings
INSERT IGNORE INTO `governing_body_pages` (`page_type`,`title`,`subtitle`,`hero_intro`,`meta_description`,`is_published`) VALUES
('board-of-trustees','Board of Trustees','Governance & Leadership','The Board of Trustees is the supreme governing body of Prime University, responsible for policy-making and strategic oversight.','Board of Trustees – Prime University',1),
('pu-syndicates','PU Syndicates','Academic Governance','The Syndicate is the executive body of Prime University responsible for overall management and administration.','PU Syndicates – Prime University',1),
('deans','Deans','Faculty Leadership','The Deans provide academic and administrative leadership for each faculty of Prime University.','Deans – Prime University',1),
('head-of-departments','Head of Departments','Department Leadership','Heads of Departments lead the academic and administrative functions of each department at Prime University.','Head of Departments – Prime University',1);
