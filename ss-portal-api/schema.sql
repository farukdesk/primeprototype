-- SS Portal (partner web portal) – local database
-- ------------------------------------------------
-- This is the PARTNER's own database. It never contains university tables;
-- students are pushed to Prime University through the Student API v1.
--
-- Run once:  mysql -u ss_portal -p ss_portal < schema.sql

CREATE TABLE IF NOT EXISTS ssp_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(60)  NOT NULL,
  full_name     VARCHAR(150) NOT NULL,
  email         VARCHAR(190) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ssp_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ssp_students (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference_no       VARCHAR(40)  NOT NULL,                 -- local reference, also drives the idempotency key
  full_name          VARCHAR(255) NOT NULL,
  email              VARCHAR(190) DEFAULT NULL,
  contact_no         VARCHAR(30)  DEFAULT NULL,
  department_label   VARCHAR(150) DEFAULT NULL,
  program_label      VARCHAR(150) DEFAULT NULL,
  admitted_semester  VARCHAR(30)  DEFAULT NULL,
  payload_json       LONGTEXT     NOT NULL,                 -- exact JSON document sent to the university (photo excluded)
  photo_path         VARCHAR(255) DEFAULT NULL,             -- file name inside storage/photos/
  sync_status        ENUM('draft','pending','synced','failed','deleted') NOT NULL DEFAULT 'draft',
  sync_attempts      INT UNSIGNED NOT NULL DEFAULT 0,
  pending_update     TINYINT(1)   NOT NULL DEFAULT 0,             -- local edits not yet sent to the university
  pu_id              INT UNSIGNED DEFAULT NULL,             -- data.id returned by the university
  pu_student_id      VARCHAR(25)  DEFAULT NULL,             -- data.student_id (official Student ID)
  pu_status          VARCHAR(40)  DEFAULT NULL,
  pu_photo_url       VARCHAR(500) DEFAULT NULL,
  pu_result_id       INT UNSIGNED DEFAULT NULL,
  result_json        TEXT         DEFAULT NULL,             -- last published final result (data.result)
  last_error         TEXT         DEFAULT NULL,
  last_response_json LONGTEXT     DEFAULT NULL,
  synced_at          DATETIME     DEFAULT NULL,
  deleted_at         DATETIME     DEFAULT NULL,                   -- record deleted at the university, kept here
  deleted_by         INT UNSIGNED DEFAULT NULL,
  delete_reason      VARCHAR(500) DEFAULT NULL,
  created_by         INT UNSIGNED NOT NULL,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ssp_students_reference (reference_no),
  KEY idx_ssp_students_status (sync_status),
  KEY idx_ssp_students_pu_sid (pu_student_id),
  CONSTRAINT fk_ssp_students_user FOREIGN KEY (created_by) REFERENCES ssp_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ssp_api_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id      INT UNSIGNED DEFAULT NULL,
  user_id         INT UNSIGNED DEFAULT NULL,
  endpoint        VARCHAR(100) NOT NULL,
  idempotency_key VARCHAR(100) DEFAULT NULL,
  http_status     SMALLINT     DEFAULT NULL,
  response_code   VARCHAR(60)  DEFAULT NULL,
  ok              TINYINT(1)   NOT NULL DEFAULT 0,
  duration_ms     INT UNSIGNED DEFAULT NULL,
  request_json    LONGTEXT     DEFAULT NULL,
  response_json   LONGTEXT     DEFAULT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ssp_api_log_student (student_id),
  KEY idx_ssp_api_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Small key/value cache (reference data from GET /reference-data.php)
CREATE TABLE IF NOT EXISTS ssp_cache (
  k          VARCHAR(60) NOT NULL,
  v          LONGTEXT    NOT NULL,
  updated_at DATETIME    NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
