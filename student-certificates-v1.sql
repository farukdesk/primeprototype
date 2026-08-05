-- ─────────────────────────────────────────────────────────────────────────────
-- Student Certificate Numbers (v1)
--
-- Maps a student to their printed certificate number so students can be
-- searched by certificate number on:
--   * the public certificate-verification page (certificate-verification.php)
--   * the admin student verification search (admin/student-verification/verify.php)
--   * the admin student list search (admin/students/index.php)
--
-- NOTE: admin/final-result-publish/certificate-upload.php creates this table
-- automatically on the first import; this file is kept for manual migrations.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS student_certificates (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_ref_id     INT          NOT NULL COMMENT 'students.id',
    student_id         VARCHAR(30)  NOT NULL COMMENT 'students.student_id (as matched)',
    certificate_number VARCHAR(60)  NOT NULL,
    uploaded_by        INT          NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificate_number (certificate_number),
    UNIQUE KEY uq_student_ref_id (student_ref_id),
    KEY idx_student_id (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
