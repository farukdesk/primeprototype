-- Student Accounts Module
-- Registers the student-accounts slug in the modules table so it appears
-- in the Module Access control page (admin/access/index.php).
-- Run AFTER student-fee-package.sql

SET NAMES utf8mb4;

INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES ('Student Accounts', 'student-accounts',
        'View and manage per-student fee packages, semester fees and scholarship assignments',
        'fas fa-file-invoice-dollar', 56, 1);
