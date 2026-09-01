-- ============================================================
-- ID Card Module – v1
-- Creates the cards table and registers the module in the
-- dynamic modules menu. Run once on the admin database.
-- ============================================================

CREATE TABLE IF NOT EXISTS idc_cards (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    card_type       ENUM('student','faculty','staff') NOT NULL DEFAULT 'student',
    student_ref_id  INT NULL COMMENT 'students.id when generated from a student record (NULL for manual/faculty/staff)',
    id_number       VARCHAR(50)  NOT NULL COMMENT 'Printed ID (Student ID / Employee ID)',
    full_name       VARCHAR(150) NOT NULL,
    program_name    VARCHAR(150) NULL,
    dept_name       VARCHAR(150) NULL,
    designation     VARCHAR(150) NULL COMMENT 'For faculty/staff cards',
    batch_name      VARCHAR(100) NULL,
    blood_group     VARCHAR(10)  NULL,
    phone           VARCHAR(30)  NULL,
    address         VARCHAR(255) NULL,
    photo           VARCHAR(255) NULL,
    issue_date      DATE NULL,
    expiry_date     DATE NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      INT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idc_type_number (card_type, id_number),
    KEY idx_idc_student (student_ref_id),
    KEY idx_idc_type (card_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Register the module in the dynamic admin menu (idempotent)
INSERT INTO modules (name, slug, description, icon, sort_order, is_active)
SELECT 'ID Card', 'id-card', 'Generate Student / Faculty / Staff ID cards', 'fas fa-id-card', 60, 1
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE slug = 'id-card');
