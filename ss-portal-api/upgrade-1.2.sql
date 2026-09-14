-- SS Portal 1.1 -> 1.2
-- Adds the portal-only "Internal" section: yes/no flags, reference, notes and document uploads.
-- Nothing here is ever sent to the university.
-- Fresh installs: schema.sql already contains this; do NOT run this file.

ALTER TABLE ssp_students
  ADD COLUMN internal_json TEXT DEFAULT NULL AFTER photo_path;   -- apostille, online_only, work_done, reference, notes

CREATE TABLE IF NOT EXISTS ssp_student_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    INT UNSIGNED NOT NULL,
  kind          ENUM('admission_form','ssc','hsc','certificate','transcript','tabulation','other') NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(255) NOT NULL,                 -- file name inside storage/files/
  mime          VARCHAR(100) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by   INT UNSIGNED DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ssp_student_files_student (student_id, kind),
  CONSTRAINT fk_ssp_student_files_student FOREIGN KEY (student_id) REFERENCES ssp_students (id) ON DELETE CASCADE,
  CONSTRAINT fk_ssp_student_files_user    FOREIGN KEY (uploaded_by) REFERENCES ssp_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
