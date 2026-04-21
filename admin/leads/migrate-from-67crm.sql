-- ═══════════════════════════════════════════════════════════════════════════════
-- Migration: admin_67crm → primeprototype (new CRM)
-- 
-- Instructions:
--   1. Back up your current database before running this script.
--   2. Run call-logs.sql FIRST to create lead_call_logs table.
--   3. Run this script against the NEW (primeprototype) database.
--   4. The migrate.php browser UI handles the staging import automatically:
--      it imports admin_67crm.sql into the same database using a crm_import_
--      table prefix, so no separate database or CREATE DATABASE privilege is
--      required.  All legacy table references below use that prefix.
--
-- What this script migrates:
--   • Users & user profiles  (matched by email to avoid duplicates)
--   • Leads                  (all fields mapped + status/source normalised)
--   • Lead notes
--   • Lead edit history      → lead_history
--   • Lead staff assignments → lead_assignments
--   • Call logs              → lead_call_logs
--   • Campus visit data      → lead_appointments
-- ═══════════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 1: Add GPA/education columns to leads if they don't exist yet
--         (from admin_67crm schema – missing from current system)
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `leads`
  ADD COLUMN IF NOT EXISTS `ssc_gpa`         DECIMAL(3,2) DEFAULT NULL COMMENT 'SSC / O-Level GPA' AFTER `preferred_semester`,
  ADD COLUMN IF NOT EXISTS `hsc_gpa`         DECIMAL(3,2) DEFAULT NULL COMMENT 'HSC / A-Level GPA' AFTER `ssc_gpa`,
  ADD COLUMN IF NOT EXISTS `bachelor_subject` VARCHAR(255) DEFAULT NULL COMMENT 'Bachelor degree subject (for Master applicants)' AFTER `hsc_gpa`,
  ADD COLUMN IF NOT EXISTS `bachelor_cgpa`   DECIMAL(3,2) DEFAULT NULL COMMENT 'Bachelor CGPA (for Master applicants)' AFTER `bachelor_subject`;

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 2: Import users (match by email to avoid duplicates)
--         The new users table uses group_id (FK → user_groups) instead of a
--         user_type column.  Ensure the five legacy role groups exist first,
--         then map user_type → group_id via a name lookup.
--         username is derived from the email address (unique in the legacy DB).
--         full_name is taken from crm_import_user_profile when available,
--         falling back to the email address.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `user_groups` (`name`, `description`, `is_super`, `is_active`)
SELECT 'Super Admin', 'Full system access – unrestricted.', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Super Admin');

INSERT INTO `user_groups` (`name`, `description`, `is_super`, `is_active`)
SELECT 'Admin', 'Administrator access.', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Admin');

INSERT INTO `user_groups` (`name`, `description`, `is_super`, `is_active`)
SELECT 'Manager', 'Manager access.', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Manager');

INSERT INTO `user_groups` (`name`, `description`, `is_super`, `is_active`)
SELECT 'Counselor', 'Counselor / admissions advisor access.', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Counselor');

INSERT INTO `user_groups` (`name`, `description`, `is_super`, `is_active`)
SELECT 'Agent', 'External agent access.', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Agent');

INSERT IGNORE INTO `users`
  (id, group_id, username, email, password, full_name, is_active, created_at)
SELECT
  u.id,
  (SELECT g.id FROM `user_groups` g WHERE g.name = u.user_type LIMIT 1),
  LEFT(u.email, 60),
  u.email,
  u.password,
  COALESCE(
    NULLIF(TRIM(CONCAT(COALESCE(up.first_name, ''), ' ', COALESCE(up.last_name, ''))), ''),
    u.email
  ),
  IF(u.account_status = 'Active', 1, 0),
  u.created_at
FROM `crm_import_users` u
LEFT JOIN `crm_import_user_profile` up ON up.user_id = u.id
ON DUPLICATE KEY UPDATE
  -- do not overwrite existing passwords/groups; only sync active status
  is_active = IF(u.account_status = 'Active', 1, 0);

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 3: Import user profiles
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `user_profiles`
  (user_id, display_name, phone, created_at)
SELECT
  up.user_id,
  CONCAT(up.first_name, ' ', up.last_name),
  up.mobile_number,
  NOW()
FROM `crm_import_user_profile` up
WHERE EXISTS (SELECT 1 FROM `users` WHERE id = up.user_id)
ON DUPLICATE KEY UPDATE
  display_name = CONCAT(up.first_name, ' ', up.last_name),
  phone        = up.mobile_number;

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 4: Import leads with field and value mapping
-- ─────────────────────────────────────────────────────────────────────────────
-- Status mapping (old → new):
--   Fresh                 → fresh
--   1st call              → 1st_call
--   2nd call              → 2nd_call
--   3rd call              → 3rd_call
--   Unable to reach once  → unable_to_reach
--   Unable to reach twice → unable_to_reach
--   Unable to reach trice → unable_to_reach
--   Dead                  → not_interested
--   Will visit office     → will_visit
--   Admitted              → converted
--
-- Source mapping (old → new):
--   Direct Online         → online
--   Direct Campus Visit   → campus_visit
--   Agent                 → agent
--   F2F Marketing         → f2f_marketing
--
-- Degree mapping (old → new):
--   Bachelor Degree       → bachelor
--   Master Degree         → master

INSERT IGNORE INTO `leads`
  (
    id,
    lead_number,
    first_name,
    last_name,
    email,
    phone,
    address,
    current_city,
    degree_type,
    preferred_semester,
    ssc_gpa,
    hsc_gpa,
    bachelor_subject,
    bachelor_cgpa,
    next_followup_date,
    status,
    source,
    created_by,
    updated_by,
    created_at,
    updated_at
  )
SELECT
  l.id,
  CONCAT('LD-', YEAR(l.created_at), '-', LPAD(l.id, 4, '0')),
  l.first_name,
  l.last_name,
  l.email,
  l.phone,
  l.address,
  l.current_city,
  -- degree_type mapping
  CASE l.applying_for
    WHEN 'Bachelor Degree' THEN 'bachelor'
    WHEN 'Master Degree'   THEN 'master'
    ELSE 'bachelor'
  END,
  l.semester,
  l.ssc_gpa,
  l.hsc_gpa,
  l.bachelor_subject,
  l.bachelor_cgpa,
  l.next_followup_date,
  -- status mapping
  CASE l.lead_status
    WHEN 'Fresh'                  THEN 'fresh'
    WHEN '1st call'               THEN '1st_call'
    WHEN '2nd call'               THEN '2nd_call'
    WHEN '3rd call'               THEN '3rd_call'
    WHEN 'Unable to reach once'   THEN 'unable_to_reach'
    WHEN 'Unable to reach twice'  THEN 'unable_to_reach'
    WHEN 'Unable to reach trice'  THEN 'unable_to_reach'
    WHEN 'Dead'                   THEN 'not_interested'
    WHEN 'Will visit office'      THEN 'will_visit'
    WHEN 'Admitted'               THEN 'converted'
    ELSE 'fresh'
  END,
  -- source mapping
  CASE l.lead_source
    WHEN 'Direct Online'         THEN 'online'
    WHEN 'Direct Campus Visit'   THEN 'campus_visit'
    WHEN 'Agent'                 THEN 'agent'
    WHEN 'F2F Marketing'         THEN 'f2f_marketing'
    ELSE 'online'
  END,
  -- created_by: use matching user id if exists, else NULL
  IF(EXISTS(SELECT 1 FROM `users` WHERE id = l.created_by), l.created_by, NULL),
  IF(EXISTS(SELECT 1 FROM `users` WHERE id = l.updated_by), l.updated_by, NULL),
  l.created_at,
  l.updated_at
FROM `crm_import_leads` l;

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 5: Import lead notes
--         old: note_text, created_by  → new: note, user_id
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `lead_notes`
  (lead_id, user_id, note, created_at)
SELECT
  n.lead_id,
  IF(EXISTS(SELECT 1 FROM `users` WHERE id = n.created_by), n.created_by, NULL),
  n.note_text,
  n.created_at
FROM `crm_import_lead_notes` n
WHERE EXISTS (SELECT 1 FROM `leads` WHERE id = n.lead_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 6: Import lead edit history → lead_history
--         old: field_name, old_value, new_value, edited_by, edited_at
--         new: action, field_name, old_value, new_value, user_id, description, created_at
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `lead_history`
  (lead_id, user_id, action, field_name, old_value, new_value, description, created_at)
SELECT
  h.lead_id,
  IF(EXISTS(SELECT 1 FROM `users` WHERE id = h.edited_by), h.edited_by, NULL),
  CASE
    WHEN h.field_name = 'lead_status'      THEN 'status_changed'
    WHEN h.field_name = 'campus_visit_date' THEN 'appointment_set'
    ELSE 'updated'
  END,
  CASE h.field_name
    WHEN 'lead_status'      THEN 'status'
    WHEN 'lead_source'      THEN 'source'
    WHEN 'applying_for'     THEN 'degree_type'
    ELSE h.field_name
  END,
  h.old_value,
  h.new_value,
  CONCAT('Imported from legacy CRM: ', h.field_name, ' changed'),
  h.edited_at
FROM `crm_import_lead_edit_history` h
WHERE EXISTS (SELECT 1 FROM `leads` WHERE id = h.lead_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 7: Import lead staff assignments
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `lead_assignments`
  (lead_id, user_id, assigned_by, assigned_at)
SELECT
  a.lead_id,
  a.staff_id,
  IF(EXISTS(SELECT 1 FROM `users` WHERE id = a.assigned_by), a.assigned_by, NULL),
  a.assigned_at
FROM `crm_import_lead_staff_assignments` a
WHERE EXISTS (SELECT 1 FROM `leads`   WHERE id = a.lead_id)
  AND EXISTS (SELECT 1 FROM `users`   WHERE id = a.staff_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 8: Import call logs → lead_call_logs
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `lead_call_logs`
  (lead_id, user_id, call_type, previous_status, new_status, call_date, call_duration, call_notes, call_outcome, call_converted, created_at)
SELECT
  cl.lead_id,
  IF(EXISTS(SELECT 1 FROM `users` WHERE id = cl.staff_id), cl.staff_id, NULL),
  -- call_type mapping (old ENUM → new ENUM)
  CASE cl.call_type
    WHEN '1st call'                   THEN '1st_call'
    WHEN '2nd call'                   THEN '2nd_call'
    WHEN '3rd call'                   THEN '3rd_call'
    WHEN 'Follow-up call'             THEN 'followup_call'
    WHEN 'Office visit confirmation'  THEN 'visit_confirmation'
    WHEN 'Final call'                 THEN 'final_call'
    ELSE 'followup_call'
  END,
  -- previous/new status normalisation
  CASE cl.previous_status
    WHEN 'Fresh'                 THEN 'fresh'
    WHEN '1st call'              THEN '1st_call'
    WHEN '2nd call'              THEN '2nd_call'
    WHEN '3rd call'              THEN '3rd_call'
    WHEN 'Unable to reach once'  THEN 'unable_to_reach'
    WHEN 'Unable to reach twice' THEN 'unable_to_reach'
    WHEN 'Unable to reach trice' THEN 'unable_to_reach'
    WHEN 'Dead'                  THEN 'not_interested'
    WHEN 'Will visit office'     THEN 'will_visit'
    WHEN 'Admitted'              THEN 'converted'
    ELSE cl.previous_status
  END,
  CASE cl.new_status
    WHEN 'Fresh'                 THEN 'fresh'
    WHEN '1st call'              THEN '1st_call'
    WHEN '2nd call'              THEN '2nd_call'
    WHEN '3rd call'              THEN '3rd_call'
    WHEN 'Unable to reach once'  THEN 'unable_to_reach'
    WHEN 'Unable to reach twice' THEN 'unable_to_reach'
    WHEN 'Unable to reach trice' THEN 'unable_to_reach'
    WHEN 'Dead'                  THEN 'not_interested'
    WHEN 'Will visit office'     THEN 'will_visit'
    WHEN 'Admitted'              THEN 'converted'
    ELSE cl.new_status
  END,
  cl.call_date,
  -- call_duration: original is in seconds, convert to minutes (rounded up)
  IF(cl.call_duration IS NOT NULL, CEIL(cl.call_duration / 60), NULL),
  cl.call_notes,
  cl.call_outcome,
  cl.call_converted,
  cl.created_at
FROM `crm_import_call_logs` cl
WHERE EXISTS (SELECT 1 FROM `leads` WHERE id = cl.lead_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 9: Import campus visit dates → lead_appointments
--         The old system stores campus_visit_date on the leads row.
--         We create a lead_appointment entry for each lead that has one.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `lead_appointments`
  (lead_id, appointment_date, appointment_time, purpose, status, created_by, created_at)
SELECT
  l.id,
  DATE(l.campus_visit_date),
  TIME(l.campus_visit_date),
  'Campus Visit',
  IF(l.campus_visit_attended_at IS NOT NULL OR l.attended_at IS NOT NULL, 'completed', 'scheduled'),
  IF(EXISTS(SELECT 1 FROM `users` WHERE id = l.created_by), l.created_by, NULL),
  COALESCE(l.campus_visit_date, l.created_at)
FROM `crm_import_leads` l
WHERE l.campus_visit_date IS NOT NULL
  AND EXISTS (SELECT 1 FROM `leads` WHERE id = l.id);

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
-- Done! Verify counts:
-- ─────────────────────────────────────────────────────────────────────────────
SELECT 'Leads imported'       AS entity, COUNT(*) AS total FROM `leads`             UNION ALL
SELECT 'Lead notes'           AS entity, COUNT(*) AS total FROM `lead_notes`        UNION ALL
SELECT 'Lead history'         AS entity, COUNT(*) AS total FROM `lead_history`      UNION ALL
SELECT 'Lead assignments'     AS entity, COUNT(*) AS total FROM `lead_assignments`  UNION ALL
SELECT 'Call logs'            AS entity, COUNT(*) AS total FROM `lead_call_logs`    UNION ALL
SELECT 'Campus appointments'  AS entity, COUNT(*) AS total FROM `lead_appointments`;
