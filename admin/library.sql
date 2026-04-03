-- =============================================================================
-- Prime University Library Management System
-- Database Schema: library.sql
-- Description: Comprehensive schema for University Library Management System
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- =============================================================================
-- Depends on existing tables: modules, users, students, dept_departments, change_log
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- 1. library_settings
--    Key-value store for all library configuration parameters
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_settings` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100)     NOT NULL,
    `setting_val` TEXT             NOT NULL,
    `description` VARCHAR(255)     DEFAULT NULL,
    `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Key-value configuration store for library settings';

-- =============================================================================
-- 2. library_librarians
--    Staff/librarian profiles displayed on the library pages
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_librarians` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(150)     NOT NULL,
    `designation` VARCHAR(150)     NOT NULL,
    `photo`       VARCHAR(255)     DEFAULT NULL,
    `email`       VARCHAR(150)     DEFAULT NULL,
    `phone`       VARCHAR(30)      DEFAULT NULL,
    `room_number` VARCHAR(50)      DEFAULT NULL,
    `bio`         TEXT             DEFAULT NULL,
    `is_active`   TINYINT(1)       NOT NULL DEFAULT 1,
    `sort_order`  SMALLINT         NOT NULL DEFAULT 0,
    `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_librarians_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Library staff and librarian profiles';

-- =============================================================================
-- 3. library_categories
--    Hierarchical category/subject tree for books and digital resources
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_categories` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(120)     NOT NULL,
    `slug`        VARCHAR(130)     NOT NULL,
    `description` VARCHAR(255)     DEFAULT NULL,
    `parent_id`   INT UNSIGNED     DEFAULT NULL,
    `sort_order`  SMALLINT         NOT NULL DEFAULT 0,
    `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_category_slug` (`slug`),
    KEY `idx_category_parent` (`parent_id`),
    CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`)
        REFERENCES `library_categories` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hierarchical subject/category tree for the library collection';

-- =============================================================================
-- 4. library_books
--    Master catalogue of all physical and digital books
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_books` (
    `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `isbn`             VARCHAR(30)      DEFAULT NULL,
    `title`            VARCHAR(300)     NOT NULL,
    `subtitle`         VARCHAR(300)     DEFAULT NULL,
    `author`           TEXT             NOT NULL,
    `publisher`        VARCHAR(255)     DEFAULT NULL,
    `edition`          VARCHAR(50)      DEFAULT NULL,
    `pub_year`         YEAR             DEFAULT NULL,
    `category_id`      INT UNSIGNED     DEFAULT NULL,
    `language`         VARCHAR(60)      NOT NULL DEFAULT 'English',
    `description`      TEXT             DEFAULT NULL,
    `department_id`    INT UNSIGNED     DEFAULT NULL,
    `cover_image`      VARCHAR(255)     DEFAULT NULL,
    `shelf_rack`       VARCHAR(30)      DEFAULT NULL,
    `shelf_row`        VARCHAR(30)      DEFAULT NULL,
    `total_copies`     SMALLINT         NOT NULL DEFAULT 1,
    `available_copies` SMALLINT         NOT NULL DEFAULT 1,
    `is_digital`       TINYINT(1)       NOT NULL DEFAULT 0,
    `created_by`       INT UNSIGNED     DEFAULT NULL,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_book_isbn` (`isbn`),
    KEY `idx_book_category`   (`category_id`),
    KEY `idx_book_department` (`department_id`),
    KEY `idx_book_created_by` (`created_by`),
    KEY `idx_book_title`      (`title`(100)),
    CONSTRAINT `fk_book_category`   FOREIGN KEY (`category_id`)
        REFERENCES `library_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_book_department` FOREIGN KEY (`department_id`)
        REFERENCES `dept_departments` (`id`)     ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_book_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`)                ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master catalogue of library books (physical and digital)';

-- =============================================================================
-- 5. library_book_copies
--    Individual physical copies of each catalogued book
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_book_copies` (
    `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `book_id`          INT UNSIGNED     NOT NULL,
    `barcode`          VARCHAR(60)      DEFAULT NULL,
    `copy_number`      SMALLINT         NOT NULL DEFAULT 1,
    `condition_status` ENUM('Good','Fair','Poor','Lost','Damaged') NOT NULL DEFAULT 'Good',
    `notes`            VARCHAR(255)     DEFAULT NULL,
    `is_available`     TINYINT(1)       NOT NULL DEFAULT 1,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_copy_barcode` (`barcode`),
    KEY `idx_copy_book`      (`book_id`),
    KEY `idx_copy_available` (`is_available`),
    CONSTRAINT `fk_copy_book` FOREIGN KEY (`book_id`)
        REFERENCES `library_books` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual physical copies of catalogued books';

-- =============================================================================
-- 6. library_members
--    Library membership records for students, faculty, and staff
--    member_code format: LIB-YYYY-NNNN
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_members` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `member_type` ENUM('Student','Faculty','Staff') NOT NULL DEFAULT 'Student',
    `student_id`  INT UNSIGNED     DEFAULT NULL,
    `user_id`     INT UNSIGNED     DEFAULT NULL,
    `member_code` VARCHAR(20)      NOT NULL,
    `name`        VARCHAR(150)     NOT NULL,
    `email`       VARCHAR(150)     DEFAULT NULL,
    `phone`       VARCHAR(30)      DEFAULT NULL,
    `dept_id`     INT UNSIGNED     DEFAULT NULL,
    `is_active`   TINYINT(1)       NOT NULL DEFAULT 1,
    `joined_at`   DATE             NOT NULL,
    `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_member_code`  (`member_code`),
    KEY `idx_member_student`     (`student_id`),
    KEY `idx_member_user`        (`user_id`),
    KEY `idx_member_dept`        (`dept_id`),
    KEY `idx_member_active_type` (`is_active`, `member_type`),
    CONSTRAINT `fk_member_student` FOREIGN KEY (`student_id`)
        REFERENCES `students` (`id`)     ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_member_user`    FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_member_dept`    FOREIGN KEY (`dept_id`)
        REFERENCES `dept_departments` (`id`)  ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Library membership records (students, faculty, staff)';

-- =============================================================================
-- 7. library_circulation
--    Book issue / return / overdue tracking
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_circulation` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `copy_id`        INT UNSIGNED  NOT NULL,
    `book_id`        INT UNSIGNED  NOT NULL,
    `member_id`      INT UNSIGNED  NOT NULL,
    `issued_by`      INT UNSIGNED  NOT NULL,
    `returned_to`    INT UNSIGNED  DEFAULT NULL,
    `issue_date`     DATE          NOT NULL,
    `due_date`       DATE          NOT NULL,
    `return_date`    DATE          DEFAULT NULL,
    `status`         ENUM('Issued','Returned','Overdue','Lost') NOT NULL DEFAULT 'Issued',
    `renewal_count`  TINYINT       NOT NULL DEFAULT 0,
    `notes`          TEXT          DEFAULT NULL,
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_circ_copy`        (`copy_id`),
    KEY `idx_circ_book`        (`book_id`),
    KEY `idx_circ_member`      (`member_id`),
    KEY `idx_circ_issued_by`   (`issued_by`),
    KEY `idx_circ_returned_to` (`returned_to`),
    KEY `idx_circ_status`      (`status`),
    KEY `idx_circ_due_date`    (`due_date`),
    CONSTRAINT `fk_circ_copy`        FOREIGN KEY (`copy_id`)
        REFERENCES `library_book_copies` (`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT `fk_circ_book`        FOREIGN KEY (`book_id`)
        REFERENCES `library_books`       (`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT `fk_circ_member`      FOREIGN KEY (`member_id`)
        REFERENCES `library_members`     (`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT `fk_circ_issued_by`   FOREIGN KEY (`issued_by`)
        REFERENCES `users`               (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_circ_returned_to` FOREIGN KEY (`returned_to`)
        REFERENCES `users`               (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Book circulation records: issue, return, overdue, and lost tracking';

-- =============================================================================
-- 8. library_reservations
--    Book reservation / hold queue management
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_reservations` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `book_id`      INT UNSIGNED  NOT NULL,
    `member_id`    INT UNSIGNED  NOT NULL,
    `reserved_by`  INT UNSIGNED  NOT NULL,
    `status`       ENUM('Pending','Available','Fulfilled','Cancelled','Expired') NOT NULL DEFAULT 'Pending',
    `reserved_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`   DATETIME      NOT NULL COMMENT 'Auto-calculated: reserved_at + 48 hours',
    `notified_at`  DATETIME      DEFAULT NULL,
    `notes`        TEXT          DEFAULT NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_resv_book`        (`book_id`),
    KEY `idx_resv_member`      (`member_id`),
    KEY `idx_resv_reserved_by` (`reserved_by`),
    KEY `idx_resv_status`      (`status`),
    KEY `idx_resv_expires_at`  (`expires_at`),
    CONSTRAINT `fk_resv_book`       FOREIGN KEY (`book_id`)
        REFERENCES `library_books`    (`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT `fk_resv_member`     FOREIGN KEY (`member_id`)
        REFERENCES `library_members`  (`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT `fk_resv_reserved_by` FOREIGN KEY (`reserved_by`)
        REFERENCES `users`            (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Book reservation and hold queue management';

-- =============================================================================
-- 9. library_fines
--    Fine tracking for late returns, lost books, and damages
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_fines` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `circulation_id` INT UNSIGNED      DEFAULT NULL,
    `member_id`      INT UNSIGNED      NOT NULL,
    `fine_type`      ENUM('Late','Lost','Damaged','Other') NOT NULL DEFAULT 'Late',
    `amount`         DECIMAL(10,2)     NOT NULL,
    `days_overdue`   SMALLINT          DEFAULT NULL,
    `status`         ENUM('Unpaid','Paid','Waived') NOT NULL DEFAULT 'Unpaid',
    `paid_at`        DATETIME          DEFAULT NULL,
    `collected_by`   INT UNSIGNED      DEFAULT NULL,
    `receipt_number` VARCHAR(60)       DEFAULT NULL,
    `notes`          TEXT              DEFAULT NULL,
    `created_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_fine_receipt` (`receipt_number`),
    KEY `idx_fine_circulation` (`circulation_id`),
    KEY `idx_fine_member`      (`member_id`),
    KEY `idx_fine_collected_by`(`collected_by`),
    KEY `idx_fine_status`      (`status`),
    CONSTRAINT `fk_fine_circulation` FOREIGN KEY (`circulation_id`)
        REFERENCES `library_circulation` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_fine_member`       FOREIGN KEY (`member_id`)
        REFERENCES `library_members`    (`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT `fk_fine_collected_by` FOREIGN KEY (`collected_by`)
        REFERENCES `users`              (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fine records for late returns, lost books, and damages';

-- =============================================================================
-- 10. library_digital_resources
--     Uploaded e-books, journals, theses, and other digital content
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_digital_resources` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `title`          VARCHAR(300)  NOT NULL,
    `resource_type`  ENUM('E-Book','Journal','Research Paper','Thesis','Dissertation','Other') NOT NULL DEFAULT 'E-Book',
    `author`         VARCHAR(255)  DEFAULT NULL,
    `publisher`      VARCHAR(255)  DEFAULT NULL,
    `pub_year`       YEAR          DEFAULT NULL,
    `category_id`    INT UNSIGNED  DEFAULT NULL,
    `department_id`  INT UNSIGNED  DEFAULT NULL,
    `description`    TEXT          DEFAULT NULL,
    `file_name`      VARCHAR(255)  NOT NULL COMMENT 'Server-stored filename',
    `original_name`  VARCHAR(255)  NOT NULL COMMENT 'Original upload filename',
    `mime_type`      VARCHAR(100)  NOT NULL,
    `file_size`      BIGINT        NOT NULL DEFAULT 0 COMMENT 'File size in bytes',
    `access_level`   ENUM('Public','Students','Faculty','Staff','Admin') NOT NULL DEFAULT 'Students',
    `cover_image`    VARCHAR(255)  DEFAULT NULL,
    `download_count` INT           NOT NULL DEFAULT 0,
    `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
    `uploaded_by`    INT UNSIGNED  NOT NULL,
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_digres_category`   (`category_id`),
    KEY `idx_digres_department` (`department_id`),
    KEY `idx_digres_uploaded_by`(`uploaded_by`),
    KEY `idx_digres_type`       (`resource_type`),
    KEY `idx_digres_active`     (`is_active`),
    KEY `idx_digres_access`     (`access_level`),
    CONSTRAINT `fk_digres_category`    FOREIGN KEY (`category_id`)
        REFERENCES `library_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_digres_department`  FOREIGN KEY (`department_id`)
        REFERENCES `dept_departments`   (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_digres_uploaded_by` FOREIGN KEY (`uploaded_by`)
        REFERENCES `users`              (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Digital resources: e-books, journals, theses, dissertations';

-- =============================================================================
-- 11. library_audit_log
--     Immutable audit trail for all library module actions
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_audit_log` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED  DEFAULT NULL,
    `action`       VARCHAR(100)  NOT NULL,
    `module`       VARCHAR(50)   NOT NULL,
    `record_id`    INT UNSIGNED  DEFAULT NULL,
    `record_label` VARCHAR(255)  DEFAULT NULL,
    `details`      TEXT          DEFAULT NULL,
    `ip_address`   VARCHAR(45)   NOT NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_user`      (`user_id`),
    KEY `idx_audit_module`    (`module`),
    KEY `idx_audit_action`    (`action`),
    KEY `idx_audit_record`    (`record_id`),
    KEY `idx_audit_created`   (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable audit trail for library module actions';

-- =============================================================================
-- 12. library_notifications
--     Member notifications (due reminders, overdue alerts, etc.)
-- =============================================================================
CREATE TABLE IF NOT EXISTS `library_notifications` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `member_id`         INT UNSIGNED  NOT NULL,
    `notification_type` ENUM('DueReminder','OverdueAlert','ReservationAvailable','FineAlert','General') NOT NULL DEFAULT 'General',
    `title`             VARCHAR(255)  NOT NULL,
    `message`           TEXT          NOT NULL,
    `is_read`           TINYINT(1)    NOT NULL DEFAULT 0,
    `read_at`           DATETIME      DEFAULT NULL,
    `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notif_member`  (`member_id`),
    KEY `idx_notif_is_read` (`is_read`),
    KEY `idx_notif_type`    (`notification_type`),
    KEY `idx_notif_created` (`created_at`),
    CONSTRAINT `fk_notif_member` FOREIGN KEY (`member_id`)
        REFERENCES `library_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='In-system notifications for library members';

-- =============================================================================
-- SAMPLE DATA
-- =============================================================================

-- ---------------------------------------------------------------------------
-- library_settings — library configuration key-value pairs
-- ---------------------------------------------------------------------------
INSERT INTO `library_settings` (`setting_key`, `setting_val`, `description`) VALUES
('lib_name',                  'Prime University Central Library',                                                                         'Full official name of the library'),
('lib_address',               'House No. 28, Road 14/A, Dhanmondi R/A, Dhaka-1209, Bangladesh',                                          'Postal/mailing address of the library'),
('lib_room',                  'Block C, Room 101-105, Ground Floor',                                                                      'Room/block location within the campus building'),
('lib_location',              '1st Floor, Main Academic Building, Prime University Campus',                                               'Physical location description for visitors'),
('lib_phone',                 '+880-2-9671074',                                                                                           'Library contact phone number'),
('lib_email',                 'library@primeuniversity.ac.bd',                                                                            'Library contact email address'),
('lib_hours',                 'Sunday - Thursday: 8:00 AM - 9:00 PM | Friday: 2:00 PM - 9:00 PM | Saturday: Closed',                     'Library opening hours'),
('lib_website',               'https://primeuniversity.ac.bd/library',                                                                   'Library website URL'),
('lib_description',           'The Prime University Central Library is a modern academic library dedicated to supporting the intellectual growth and research needs of students, faculty, and staff. Housing over 20,000 volumes across diverse disciplines, the library provides a quiet, technology-enabled learning environment with access to both physical and digital resources, including e-books, journals, research papers, and theses.', 'Library description shown on the public page'),
('borrow_limit_student',      '3',                                                                                                        'Maximum number of books a student can borrow at once'),
('borrow_limit_faculty',      '10',                                                                                                       'Maximum number of books a faculty member can borrow at once'),
('borrow_days_student',       '14',                                                                                                       'Default loan period in days for students'),
('borrow_days_faculty',       '30',                                                                                                       'Default loan period in days for faculty'),
('fine_per_day',              '5.00',                                                                                                     'Late fine amount per day in BDT'),
('max_renewals',              '2',                                                                                                        'Maximum number of times a loan can be renewed'),
('max_reservations',          '3',                                                                                                        'Maximum active reservations allowed per member'),
('lost_book_fine_multiplier', '5',                                                                                                        'Multiplier applied to book value for lost book fine (flat 500 BDT if value unknown)'),
('reservation_expiry_hours',  '48',                                                                                                       'Hours before an available reservation expires');

-- ---------------------------------------------------------------------------
-- library_librarians — staff profiles
-- ---------------------------------------------------------------------------
INSERT INTO `library_librarians` (`name`, `designation`, `email`, `phone`, `room_number`, `bio`, `is_active`, `sort_order`) VALUES
(
    'Md. Rafiqul Islam',
    'Head Librarian',
    'rafiqul.islam@primeuniversity.ac.bd',
    '+880-1711-000101',
    'Room 101',
    'Md. Rafiqul Islam has over 18 years of experience in academic library management. He holds an MPhil in Library and Information Science from the University of Dhaka and has been instrumental in digitalising the Prime University library collection. He oversees all library operations, policy formulation, and staff development.',
    1, 1
),
(
    'Ms. Nasrin Akter',
    'Deputy Librarian',
    'nasrin.akter@primeuniversity.ac.bd',
    '+880-1711-000102',
    'Room 102',
    'Ms. Nasrin Akter holds a Master''s degree in Library and Information Science. With 11 years of professional experience, she supervises circulation services, manages the digital resource repository, and coordinates with academic departments to ensure the collection meets curriculum requirements.',
    1, 2
),
(
    'Md. Karim Hossain',
    'Assistant Librarian',
    'karim.hossain@primeuniversity.ac.bd',
    '+880-1711-000103',
    'Room 103',
    'Md. Karim Hossain is a dedicated library professional with 6 years of experience in cataloguing, classification, and reader services. He assists in maintaining the library catalogue, managing book acquisitions, and providing reference support to students and researchers.',
    1, 3
);

-- ---------------------------------------------------------------------------
-- library_categories — hierarchical subject categories
-- Top-level subjects first, then sub-categories with parent_id references
-- ---------------------------------------------------------------------------
INSERT INTO `library_categories` (`id`, `name`, `slug`, `description`, `parent_id`, `sort_order`) VALUES
-- Top-level categories
(1,  'Computer Science',         'computer-science',          'Books and resources covering all areas of computer science and information technology',           NULL, 1),
(2,  'Engineering',              'engineering',               'Textbooks and references for engineering disciplines including civil, electrical, and mechanical',  NULL, 2),
(3,  'Business Administration',  'business-administration',   'Resources covering management, accounting, finance, marketing, and entrepreneurship',              NULL, 3),
(4,  'Natural Sciences',         'natural-sciences',          'Foundational and advanced resources in natural and applied sciences',                              NULL, 4),
(5,  'Social Sciences',          'social-sciences',           'Books on sociology, anthropology, political science, psychology, and related disciplines',         NULL, 5),
(6,  'Mathematics',              'mathematics',               'Pure and applied mathematics including calculus, algebra, statistics, and discrete math',          NULL, 6),
(7,  'Physics',                  'physics',                   'Classical mechanics, thermodynamics, electromagnetism, quantum mechanics, and astrophysics',       NULL, 7),
(8,  'Chemistry',                'chemistry',                 'Organic, inorganic, physical, and analytical chemistry resources',                                 NULL, 8),
(9,  'Biology',                  'biology',                   'Molecular biology, genetics, ecology, microbiology, and life sciences',                           NULL, 9),
(10, 'History',                  'history',                   'World history, South Asian history, and historiography',                                           NULL, 10),
(11, 'Literature',               'literature',                'English literature, Bangla literature, world literature, and linguistics',                         NULL, 11),
(12, 'Medical Science',          'medical-science',           'Medical textbooks, clinical references, pharmacology, and public health resources',                NULL, 12),
(13, 'Law',                      'law',                       'Constitutional law, criminal law, commercial law, and international law resources',                NULL, 13),
(14, 'Economics',                'economics',                 'Micro and macro economics, development economics, and econometrics',                               NULL, 14),
(15, 'Architecture',             'architecture',              'Architectural design, urban planning, structural engineering, and building technology',            NULL, 15),
-- Sub-categories under Computer Science (parent_id = 1)
(16, 'Algorithms',               'computer-science-algorithms',   'Data structures, algorithm design, computational complexity, and problem-solving techniques', 1,   1),
(17, 'Programming',              'computer-science-programming',  'Programming languages, software engineering, design patterns, and development methodologies', 1,   2);

-- ---------------------------------------------------------------------------
-- modules — register library modules in the navigation system
-- ---------------------------------------------------------------------------
INSERT INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Library',              'library',             'Prime University Central Library — catalogue, circulation, members, and settings', 'fas fa-book-open',   55, 1),
('Library Circulation',  'library-circulation', 'Book issue, return, reservation, fine management, and circulation reports',        'fas fa-exchange-alt', 56, 1),
('Library Digital',      'library-digital',     'Digital resource repository: e-books, journals, research papers, and theses',     'fas fa-file-pdf',    57, 1);

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- End of library.sql
-- =============================================================================
