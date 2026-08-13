-- ============================================================================
-- Exam Invigilation: official (non-academic) departments  (v1)
-- ============================================================================
-- Adds a dept_type flag to dept_departments and seeds three university
-- offices selectable in the Exam Invigilation faculty pool.
--
-- Offices are inserted with is_active = 0 so they NEVER appear on the public
-- website or in any module that filters on is_active = 1. The exam
-- invigilation module selects them explicitly via dept_type = 'office'.
--
-- Run this BEFORE deploying the matching code changes.
-- ============================================================================

ALTER TABLE dept_departments
    ADD COLUMN dept_type VARCHAR(20) NOT NULL DEFAULT 'academic' AFTER name;

INSERT INTO dept_departments (name, slug, hero_title, hero_icon, cta_url, cta_text, dept_type, is_active)
SELECT 'Office of the Treasurer', 'ei-office-of-the-treasurer', 'Office of the Treasurer', 'fas fa-coins', 'apply-now.html', 'Apply Now', 'office', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dept_departments WHERE slug = 'ei-office-of-the-treasurer');

INSERT INTO dept_departments (name, slug, hero_title, hero_icon, cta_url, cta_text, dept_type, is_active)
SELECT 'Controller of Examinations', 'ei-controller-of-examinations', 'Controller of Examinations', 'fas fa-scroll', 'apply-now.html', 'Apply Now', 'office', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dept_departments WHERE slug = 'ei-controller-of-examinations');

INSERT INTO dept_departments (name, slug, hero_title, hero_icon, cta_url, cta_text, dept_type, is_active)
SELECT 'Office of Accounts & Audit', 'ei-office-of-accounts-audit', 'Office of Accounts & Audit', 'fas fa-file-invoice-dollar', 'apply-now.html', 'Apply Now', 'office', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM dept_departments WHERE slug = 'ei-office-of-accounts-audit');
