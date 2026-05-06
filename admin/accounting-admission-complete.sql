-- ============================================================
-- Accounting – Admission Complete Status Migration
-- Run this file after admissions.sql and admissions-v2.sql
-- ============================================================

-- ── Extend the application status ENUM to include admission_complete ──────────
ALTER TABLE `admissions_applications`
    MODIFY `status` ENUM('draft','submitted','approved','rejected','ready_for_admission','cancelled','admission_complete')
        NOT NULL DEFAULT 'draft';
