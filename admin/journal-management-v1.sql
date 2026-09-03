-- ============================================================
-- Journal Management Module - v1
-- Simple structure: Journal -> Volume -> Issue -> Article -> Authors -> PDF
-- Run against the admin database, then grant access to the
-- 'journal' module slug via Access Control (or use the INSERT below).
-- ============================================================

CREATE TABLE IF NOT EXISTS journal_journals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  short_title VARCHAR(100) DEFAULT NULL,
  issn_print VARCHAR(20) DEFAULT NULL,
  issn_online VARCHAR(20) DEFAULT NULL,
  publisher VARCHAR(255) DEFAULT NULL,
  contact_email VARCHAR(190) DEFAULT NULL,
  description TEXT,
  cover_image VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_volumes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  journal_id INT UNSIGNED NOT NULL,
  volume_number INT NOT NULL,
  year SMALLINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_volume (journal_id, volume_number),
  CONSTRAINT fk_jm_vol_journal FOREIGN KEY (journal_id)
    REFERENCES journal_journals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_issues (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  volume_id INT UNSIGNED NOT NULL,
  issue_number INT NOT NULL,
  title VARCHAR(255) DEFAULT NULL,
  published_date DATE DEFAULT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_issue (volume_id, issue_number),
  CONSTRAINT fk_jm_issue_volume FOREIGN KEY (volume_id)
    REFERENCES journal_volumes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_authors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(255) NOT NULL,
  email VARCHAR(190) DEFAULT NULL,
  affiliation VARCHAR(255) DEFAULT NULL,
  country VARCHAR(100) DEFAULT NULL,
  bio TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_jm_author_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_articles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_id INT UNSIGNED NOT NULL,
  title VARCHAR(500) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  abstract TEXT,
  keywords VARCHAR(500) DEFAULT NULL,
  page_from INT DEFAULT NULL,
  page_to INT DEFAULT NULL,
  doi VARCHAR(100) DEFAULT NULL,
  pdf_file VARCHAR(255) DEFAULT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  published_date DATE DEFAULT NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  downloads INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_article_slug (slug),
  KEY idx_jm_article_status (status),
  CONSTRAINT fk_jm_article_issue FOREIGN KEY (issue_id)
    REFERENCES journal_issues (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_article_authors (
  article_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NOT NULL,
  author_order INT NOT NULL DEFAULT 1,
  PRIMARY KEY (article_id, author_id),
  CONSTRAINT fk_jm_aa_article FOREIGN KEY (article_id)
    REFERENCES journal_articles (id) ON DELETE CASCADE,
  CONSTRAINT fk_jm_aa_author FOREIGN KEY (author_id)
    REFERENCES journal_authors (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_editorial_board (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  journal_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  role VARCHAR(100) NOT NULL DEFAULT 'Member',
  affiliation VARCHAR(255) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  photo VARCHAR(255) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_jm_board_journal FOREIGN KEY (journal_id)
    REFERENCES journal_journals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_val TEXT,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Register the module for access control.
-- If your modules table has extra required columns, register
-- 'Journal Management' with slug 'journal' from Admin -> Modules instead.
INSERT INTO modules (name, slug, is_active)
SELECT 'Journal Management', 'journal', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE slug = 'journal');
