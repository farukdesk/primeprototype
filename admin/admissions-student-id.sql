-- ============================================================
-- Admissions – Student ID Settings & Notifications
-- Run this file after admissions-form-sale.sql
-- ============================================================

-- ── Student ID settings per academic program ──────────────────────────────────
CREATE TABLE IF NOT EXISTS `adm_student_id_settings` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `program_id`       INT UNSIGNED  NOT NULL,
    `university_code`  VARCHAR(10)   NOT NULL DEFAULT '028',
    `year_code`        VARCHAR(4)    NOT NULL DEFAULT '26',
    `semester_code`    VARCHAR(4)    NOT NULL DEFAULT '2',
    `faculty_code`     VARCHAR(4)    NOT NULL DEFAULT '05',
    `subject_code`     VARCHAR(4)    NOT NULL DEFAULT '10',
    `type_of_program`  VARCHAR(4)    NOT NULL DEFAULT '1',
    `next_serial`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `serial_digits`    TINYINT UNSIGNED  NOT NULL DEFAULT 3,
    `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sid_program` (`program_id`),
    CONSTRAINT `fk_sid_program` FOREIGN KEY (`program_id`)
        REFERENCES `dept_academic_programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SMS / notification settings ───────────────────────────────────────────────
INSERT IGNORE INTO `admissions_settings` (`setting_key`, `setting_value`) VALUES
('sms_enabled',            '0'),
('sms_api_key',            'ddeeb0c50cf4ff3e74b5d93ba3a5604a'),
('sms_sender_id',          '8809640910958'),
('sms_template_form_sale', 'Dear {{buyer_name}}, your admission form has been issued. Form No: {{form_number}}. Amount paid: Tk {{form_price}}. - {{app_name}}'),
('email_enabled',          '1');

-- ── Email template for form-sale notification ──────────────────────────────────
INSERT IGNORE INTO `email_templates`
    (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`)
VALUES (
    'Admission Form Sale – Buyer Notification',
    'form_sale_notification',
    'Your Admission Form – No. {{form_number}} | {{app_name}}',
    '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Admission Form Issued</title></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:30px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
        <tr>
          <td style="background:#0d6efd;padding:24px 32px;text-align:center;">
            <img src="{{logo_url}}" alt="{{app_name}}" style="max-height:52px;margin-bottom:8px;"><br>
            <span style="color:#fff;font-size:20px;font-weight:bold;">{{app_name}}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            <h2 style="margin:0 0 16px;color:#0d6efd;font-size:20px;">Admission Form Issued</h2>
            <p style="color:#333;font-size:15px;">Dear <strong>{{buyer_name}}</strong>,</p>
            <p style="color:#555;font-size:14px;line-height:1.6;">
              Thank you for purchasing an admission form. Please keep this receipt safe and use your
              form number for all future correspondence.
            </p>
            <table width="100%" cellpadding="8" cellspacing="0" style="margin:20px 0;border:1px solid #e9ecef;border-radius:6px;font-size:14px;">
              <tr style="background:#f8f9fa;">
                <td style="color:#666;width:45%;padding:10px 14px;">Form Number</td>
                <td style="font-weight:bold;color:#0d6efd;font-size:18px;padding:10px 14px;">{{form_number}}</td>
              </tr>
              <tr>
                <td style="color:#666;padding:10px 14px;">Buyer Name</td>
                <td style="padding:10px 14px;">{{buyer_name}}</td>
              </tr>
              <tr style="background:#f8f9fa;">
                <td style="color:#666;padding:10px 14px;">Mobile</td>
                <td style="padding:10px 14px;">{{buyer_mobile}}</td>
              </tr>
              <tr>
                <td style="color:#666;padding:10px 14px;">Amount Paid</td>
                <td style="padding:10px 14px;font-weight:bold;">Tk {{form_price}}</td>
              </tr>
              <tr style="background:#f8f9fa;">
                <td style="color:#666;padding:10px 14px;">Date of Issue</td>
                <td style="padding:10px 14px;">{{sold_date}}</td>
              </tr>
            </table>
            <p style="color:#555;font-size:13px;line-height:1.6;">
              Please fill in the application form carefully and submit it along with all required documents
              to the admission office.
            </p>
            <hr style="border:none;border-top:1px solid #e9ecef;margin:24px 0;">
            <p style="color:#999;font-size:12px;text-align:center;">
              This is an automated message from <strong>{{app_name}}</strong>.<br>
              Please do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>',
    '{{form_number}},{{buyer_name}},{{buyer_mobile}},{{form_price}},{{sold_date}},{{app_name}},{{logo_url}}',
    1
);
