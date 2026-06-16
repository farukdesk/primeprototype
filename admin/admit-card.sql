-- ============================================================
-- Admit Card Module
-- ============================================================

-- ── Main admit card batches (one per exam event) ──────────────────────────────
CREATE TABLE IF NOT EXISTS ac_admit_cards (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    exam_name       VARCHAR(200)     NOT NULL COMMENT 'e.g. Mid Term-1 Exam',
    semester        VARCHAR(100)     NOT NULL COMMENT 'e.g. Summer-2026',
    dept_id         INT UNSIGNED     NOT NULL,
    program_id      INT UNSIGNED     NOT NULL,
    batch_id        INT UNSIGNED     DEFAULT NULL,
    batch_label     VARCHAR(100)     DEFAULT NULL COMMENT 'e.g. 12/66',
    is_active       TINYINT(1)       NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED     NOT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dept    (dept_id),
    KEY idx_program (program_id),
    KEY idx_active  (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Courses listed on each admit card batch ───────────────────────────────────
CREATE TABLE IF NOT EXISTS ac_admit_card_courses (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    admit_card_id   INT UNSIGNED     NOT NULL,
    course_code     VARCHAR(50)      NOT NULL,
    course_title    VARCHAR(300)     NOT NULL,
    exam_date       DATE             DEFAULT NULL,
    time_slot       VARCHAR(100)     DEFAULT NULL,
    section         VARCHAR(100)     DEFAULT NULL,
    sort_order      INT              NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_admit_card (admit_card_id),
    CONSTRAINT fk_acc_admit_card FOREIGN KEY (admit_card_id)
        REFERENCES ac_admit_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Per-student override: allow download even if dues > 500 ──────────────────
CREATE TABLE IF NOT EXISTS ac_student_overrides (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    admit_card_id   INT UNSIGNED     NOT NULL,
    student_id      INT UNSIGNED     NOT NULL,
    allowed_by      INT UNSIGNED     NOT NULL,
    note            TEXT             DEFAULT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_card_student (admit_card_id, student_id),
    KEY idx_student (student_id),
    CONSTRAINT fk_aso_admit_card FOREIGN KEY (admit_card_id)
        REFERENCES ac_admit_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Per-student unique token for QR code verification ────────────────────────
CREATE TABLE IF NOT EXISTS ac_student_tokens (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    admit_card_id   INT UNSIGNED     NOT NULL,
    student_id      INT UNSIGNED     NOT NULL,
    token           CHAR(64)         NOT NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_card_student_token (admit_card_id, student_id),
    UNIQUE KEY uq_token              (token),
    KEY idx_student (student_id),
    CONSTRAINT fk_ast_admit_card FOREIGN KEY (admit_card_id)
        REFERENCES ac_admit_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Register module ───────────────────────────────────────────────────────────
INSERT IGNORE INTO modules (name, slug, description, is_active)
VALUES ('Admit Card', 'admit-card', 'Manage student admit cards with due-amount gating and QR authentication', 1);
