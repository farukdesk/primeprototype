-- ============================================================
-- Faculty Registration & Faculty Files Migration
-- Run this script once against admin_primepnew2026
-- ============================================================

-- ── faculty_registrations ─────────────────────────────────────────────────────
-- Stores public self-registration submissions before admin approval.
CREATE TABLE IF NOT EXISTS `faculty_registrations` (
  `id`               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `full_name`        VARCHAR(150)      NOT NULL,
  `email`            VARCHAR(191)      NOT NULL,
  `phone`            VARCHAR(30)       DEFAULT NULL,
  `dept_id`          INT UNSIGNED      DEFAULT NULL,
  `id_card_stored`   VARCHAR(255)      DEFAULT NULL COMMENT 'Generated filename in uploads/faculty-registrations/',
  `id_card_original` VARCHAR(255)      DEFAULT NULL COMMENT 'Original uploaded filename',
  `id_card_mime`     VARCHAR(100)      DEFAULT NULL,
  `id_card_size`     BIGINT UNSIGNED   DEFAULT 0,
  `status`           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `notes`            TEXT              DEFAULT NULL COMMENT 'Admin notes on approval/rejection',
  `reviewed_by`      INT UNSIGNED      DEFAULT NULL,
  `reviewed_at`      DATETIME          DEFAULT NULL,
  `created_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fr_status`  (`status`),
  KEY `idx_fr_email`   (`email`),
  KEY `idx_fr_dept`    (`dept_id`),
  KEY `idx_fr_reviewer`(`reviewed_by`),
  CONSTRAINT `fk_fr_dept`     FOREIGN KEY (`dept_id`)     REFERENCES `dept_departments`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fr_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── faculty_files ─────────────────────────────────────────────────────────────
-- Stores documents/files associated with a faculty user (similar to student_files).
-- Only Register Office can add/edit/delete; Faculty can only view.
CREATE TABLE IF NOT EXISTS `faculty_files` (
  `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED      NOT NULL,
  `file_name`     VARCHAR(255)      NOT NULL COMMENT 'Display / label name',
  `description`   TEXT              DEFAULT NULL,
  `stored_name`   VARCHAR(255)      NOT NULL COMMENT 'Filename on disk in uploads/faculty-profiles/files/',
  `original_name` VARCHAR(255)      NOT NULL,
  `mime_type`     VARCHAR(100)      DEFAULT NULL,
  `file_size`     BIGINT UNSIGNED   DEFAULT 0,
  `is_id_card`    TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1 = uploaded during registration',
  `uploaded_by`   INT UNSIGNED      DEFAULT NULL,
  `created_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ff_user`    (`user_id`),
  KEY `idx_ff_uploader`(`uploaded_by`),
  CONSTRAINT `fk_ff_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ff_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Register Office user group ────────────────────────────────────────────────
INSERT IGNORE INTO `user_groups` (`name`, `is_super`, `is_active`, `created_at`)
SELECT 'Register Office', 0, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM `user_groups` WHERE `name` = 'Register Office');

-- ── faculty-files module ──────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `is_active`, `created_at`)
SELECT 'Faculty Files', 'faculty-files', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `slug` = 'faculty-files');

-- ── faculty-pending module ────────────────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `is_active`, `created_at`)
SELECT 'Faculty Pending', 'faculty-pending', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `slug` = 'faculty-pending');

-- ── Grant Register Office: faculty-files (full CRUD) ─────────────────────────
INSERT INTO `group_module_access` (`group_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT ug.`id`, m.`id`, 1, 1, 1, 1
FROM `user_groups` ug
CROSS JOIN `modules` m
WHERE ug.`name` = 'Register Office' AND m.`slug` = 'faculty-files'
ON DUPLICATE KEY UPDATE `can_view`=1, `can_create`=1, `can_edit`=1, `can_delete`=1;

-- ── Grant Register Office: faculty-pending (view + approve/reject = can_edit) ─
INSERT INTO `group_module_access` (`group_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT ug.`id`, m.`id`, 1, 0, 1, 0
FROM `user_groups` ug
CROSS JOIN `modules` m
WHERE ug.`name` = 'Register Office' AND m.`slug` = 'faculty-pending'
ON DUPLICATE KEY UPDATE `can_view`=1, `can_create`=0, `can_edit`=1, `can_delete`=0;

-- ── Grant Faculty: faculty-files (view only) ──────────────────────────────────
INSERT INTO `group_module_access` (`group_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT ug.`id`, m.`id`, 1, 0, 0, 0
FROM `user_groups` ug
CROSS JOIN `modules` m
WHERE ug.`name` = 'Faculty' AND m.`slug` = 'faculty-files'
ON DUPLICATE KEY UPDATE `can_view`=1, `can_create`=0, `can_edit`=0, `can_delete`=0;

-- ── Grant Register Office: faculty-profile (view + edit) ─────────────────────
INSERT INTO `group_module_access` (`group_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT ug.`id`, m.`id`, 1, 0, 1, 0
FROM `user_groups` ug
CROSS JOIN `modules` m
WHERE ug.`name` = 'Register Office' AND m.`slug` = 'faculty-profile'
ON DUPLICATE KEY UPDATE `can_view`=1, `can_create`=0, `can_edit`=1, `can_delete`=0;

-- ── Email templates ───────────────────────────────────────────────────────────

-- 1. Sent to applicant right after submitting the registration form
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`)
VALUES (
  'Faculty Registration Received',
  'faculty_registration_received',
  'Your Faculty Registration – Pending Approval',
  '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#4f8ef7;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">Faculty Registration Received</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p>Thank you for submitting your faculty registration at <strong>{{app_name}}</strong>.</p>
    <p>Your application has been received and is currently <strong>pending approval</strong> from the administration. You will receive another email once your registration has been reviewed.</p>
    <p style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;font-size:.9rem;">
      <strong>Note:</strong> Please do not submit another registration. If you have any questions, contact the Register Office.
    </p>
    <p style="color:#888;font-size:.85rem;margin-top:24px;">This is an automated message. Please do not reply to this email.</p>
  </div>
</div>',
  '{{full_name}},{{app_name}}',
  1
);

-- 2. Sent to admin/STEM when a new registration is submitted
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`)
VALUES (
  'Faculty Registration Admin Notification',
  'faculty_registration_admin_notify',
  'New Faculty Registration Awaiting Approval – {{full_name}}',
  '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#e74c3c;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">New Faculty Registration Pending</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>A new faculty registration has been submitted and requires your approval.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:8px 12px;font-weight:600;background:#fef9f9;width:130px;border:1px solid #e0e7ef;">Full Name</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">{{full_name}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fef9f9;border:1px solid #e0e7ef;">Email</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">{{applicant_email}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fef9f9;border:1px solid #e0e7ef;">Phone</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">{{phone}}</td></tr>
      <tr><td style="padding:8px 12px;font-weight:600;background:#fef9f9;border:1px solid #e0e7ef;">Department</td>
          <td style="padding:8px 12px;border:1px solid #e0e7ef;">{{department}}</td></tr>
    </table>
    <p><a href="{{admin_url}}" style="background:#4f8ef7;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">Review Registration</a></p>
    <p style="color:#888;font-size:.85rem;margin-top:24px;">This is an automated notification from {{app_name}}.</p>
  </div>
</div>',
  '{{full_name}},{{applicant_email}},{{phone}},{{department}},{{admin_url}},{{app_name}}',
  1
);

-- 3. Sent to applicant when their registration is approved
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`)
VALUES (
  'Faculty Registration Approved',
  'faculty_approved',
  'Your Faculty Registration Has Been Approved – Login Credentials',
  '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#27ae60;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">&#10003; Registration Approved!</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p>Congratulations! Your faculty registration at <strong>{{app_name}}</strong> has been <strong style="color:#27ae60;">approved</strong>.</p>
    <p>Your account has been created with the following credentials. Please log in and change your password immediately.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:.9rem;">
      <tr><td style="padding:10px 14px;font-weight:600;background:#f0fff4;width:130px;border:1px solid #d4edda;">Username</td>
          <td style="padding:10px 14px;border:1px solid #d4edda;font-weight:700;color:#155724;font-size:1rem;">{{username}}</td></tr>
      <tr><td style="padding:10px 14px;font-weight:600;background:#f0fff4;border:1px solid #d4edda;">Password</td>
          <td style="padding:10px 14px;border:1px solid #d4edda;font-weight:700;color:#155724;font-size:1rem;">{{password}}</td></tr>
    </table>
    <p><a href="{{login_url}}" style="background:#27ae60;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;">Login to Your Account</a></p>
    <p style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;font-size:.9rem;color:#856404;">
      <strong>Important:</strong> Please change your password after your first login for security purposes.
    </p>
    <p style="color:#888;font-size:.85rem;margin-top:24px;">This is an automated message from {{app_name}}. Please do not reply.</p>
  </div>
</div>',
  '{{full_name}},{{username}},{{password}},{{login_url}},{{app_name}}',
  1
);

-- 4. Sent to applicant when their registration is rejected
INSERT IGNORE INTO `email_templates` (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`)
VALUES (
  'Faculty Registration Rejected',
  'faculty_rejected',
  'Your Faculty Registration – Update',
  '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6fb;padding:24px;border-radius:10px;">
  <div style="background:#c0392b;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;">
    <h2 style="margin:0;font-size:1.3rem;">Registration Not Approved</h2>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;border:1px solid #e0e7ef;border-top:none;">
    <p>Dear <strong>{{full_name}}</strong>,</p>
    <p>We regret to inform you that your faculty registration at <strong>{{app_name}}</strong> could not be approved at this time.</p>
    {{#notes}}<p style="background:#fff3f3;border:1px solid #f5c6cb;border-radius:8px;padding:12px 16px;font-size:.9rem;">
      <strong>Reason:</strong> {{notes}}
    </p>{{/notes}}
    <p>If you believe this is an error or would like more information, please contact the Register Office directly.</p>
    <p style="color:#888;font-size:.85rem;margin-top:24px;">This is an automated message from {{app_name}}. Please do not reply.</p>
  </div>
</div>',
  '{{full_name}},{{notes}},{{app_name}}',
  1
);
