-- ============================================================
-- Student Fee Payment Tracking
-- Run AFTER accounting.sql AND student-fee-package.sql
-- ============================================================
--
-- Purpose
-- -------
-- sfp_payments records every actual cash receipt that is tied to
-- a specific student fee obligation.  Each row is always backed by
-- an acc_vouchers receipt voucher so the money flows through the
-- double-entry ledger automatically.
--
-- Fee types
-- ---------
--   admission          – one-time admission-day payment
--   registration       – per-semester registration fee
--   semester_tuition   – semester tuition (may be partial)
--   fixed_fee          – share of fixed institutional fee
--   english_fee        – share of English course fee
--   other              – any miscellaneous student charge
--
-- Outstanding balance = obligation − SUM(sfp_payments.amount)
-- The obligation amounts come from sfp_packages / sfp_semester_fees.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `sfp_payments` (
    `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `student_id`      INT UNSIGNED      NOT NULL
                      COMMENT 'FK students.id',
    `package_id`      INT UNSIGNED      NOT NULL
                      COMMENT 'FK sfp_packages.id',
    `semester_fee_id` INT UNSIGNED      DEFAULT NULL
                      COMMENT 'FK sfp_semester_fees.id – NULL for admission/registration/other',
    `fee_type`        ENUM(
                          'admission',
                          'registration',
                          'semester_tuition',
                          'fixed_fee',
                          'english_fee',
                          'other'
                      ) NOT NULL,
    `semester_number` TINYINT UNSIGNED  DEFAULT NULL
                      COMMENT 'Semester number (1-based) – mirrors semester_fee_id.semester_number for easy filtering',
    `month_number`    TINYINT UNSIGNED  DEFAULT NULL
                      COMMENT 'Month within semester for monthly fee tracking',
    `payment_method`  ENUM('cash','bank','mobile_banking') NOT NULL DEFAULT 'cash'
                      COMMENT 'How payment was received',
    `mobile_banking_provider` ENUM('bkash','nagad','rocket') DEFAULT NULL
                      COMMENT 'Provider when payment_method=mobile_banking',
    `transaction_number` VARCHAR(100) DEFAULT NULL
                      COMMENT 'External transaction/challan/reference number for non-cash payments',
    `amount`          DECIMAL(10,2)     NOT NULL
                      COMMENT 'Amount actually received in this payment',
    `voucher_id`      INT UNSIGNED      NOT NULL
                      COMMENT 'FK acc_vouchers.id – the receipt voucher that recorded the money',
    `note`            TEXT              DEFAULT NULL,
    `collected_by`    INT UNSIGNED      DEFAULT NULL,
    `collected_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sfpp_student`  (`student_id`),
    KEY `idx_sfpp_package`  (`package_id`),
    KEY `idx_sfpp_sem_fee`  (`semester_fee_id`),
    KEY `idx_sfpp_type`     (`fee_type`),
    KEY `idx_sfpp_voucher`  (`voucher_id`),
    CONSTRAINT `fk_sfpp_student`   FOREIGN KEY (`student_id`)      REFERENCES `students`(`id`)           ON DELETE CASCADE,
    CONSTRAINT `fk_sfpp_package`   FOREIGN KEY (`package_id`)      REFERENCES `sfp_packages`(`id`)       ON DELETE CASCADE,
    CONSTRAINT `fk_sfpp_sem_fee`   FOREIGN KEY (`semester_fee_id`) REFERENCES `sfp_semester_fees`(`id`)  ON DELETE SET NULL,
    CONSTRAINT `fk_sfpp_voucher`   FOREIGN KEY (`voucher_id`)      REFERENCES `acc_vouchers`(`id`),
    CONSTRAINT `fk_sfpp_collector` FOREIGN KEY (`collected_by`)    REFERENCES `users`(`id`)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Actual student fee payments; each row is backed by an acc_vouchers receipt';

SET FOREIGN_KEY_CHECKS = 1;

-- ── Accounting notification settings ────────────────────────────────────────
-- Stored in acc_settings (key/value).
-- SMS uses the same FastSMS BD API as the admissions module.
INSERT IGNORE INTO `acc_settings` (`setting_key`, `setting_value`) VALUES
('sms_enabled',        '0'),
('sms_api_key',        ''),
('sms_sender_id',      ''),
('sms_template',       'Dear {{student_name}}, your payment of {{currency}}{{amount}} has been received for {{fee_type}}. Voucher: {{voucher_number}}. Thank you. - {{app_name}}'),
('email_invoice',      '1');

-- ── Email template: fee payment invoice ─────────────────────────────────────
INSERT IGNORE INTO `email_templates`
    (`name`, `action`, `subject`, `body_html`, `variables`, `is_active`)
VALUES (
'Fee Payment Invoice',
'fee_payment_invoice',
'Payment Receipt – {{voucher_number}} | {{app_name}}',
'<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment Receipt</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:30px 0;">
<tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.10);">
  <!-- Header -->
  <tr>
    <td style="background:#16a34a;padding:28px 36px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td><img src="{{logo_url}}" alt="{{app_name}}" style="height:44px;"></td>
          <td align="right" style="color:#d1fae5;font-size:13px;line-height:1.6;">
            <strong style="color:#fff;font-size:18px;display:block;">Payment Receipt</strong>
            Voucher: {{voucher_number}}<br>Date: {{payment_date}}
          </td>
        </tr>
      </table>
    </td>
  </tr>
  <!-- Student info -->
  <tr>
    <td style="padding:28px 36px 0;">
      <p style="margin:0 0 4px;color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Received From</p>
      <p style="margin:0 0 2px;font-size:18px;font-weight:700;color:#111827;">{{student_name}}</p>
      <p style="margin:0;font-size:13px;color:#6b7280;">Student ID: {{student_sid}} &nbsp;|&nbsp; {{department}}</p>
    </td>
  </tr>
  <!-- Fee detail box -->
  <tr>
    <td style="padding:20px 36px 0;">
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
        <tr style="background:#f9fafb;">
          <th style="padding:10px 16px;text-align:left;font-size:12px;color:#6b7280;text-transform:uppercase;border-bottom:1px solid #e5e7eb;">Description</th>
          <th style="padding:10px 16px;text-align:right;font-size:12px;color:#6b7280;text-transform:uppercase;border-bottom:1px solid #e5e7eb;">Amount</th>
        </tr>
        <tr>
          <td style="padding:14px 16px;font-size:14px;color:#374151;">{{fee_type_label}}{{semester_label}}</td>
          <td style="padding:14px 16px;text-align:right;font-size:14px;font-weight:600;color:#374151;">{{currency}}{{amount}}</td>
        </tr>
        <tr style="background:#f0fdf4;">
          <td style="padding:12px 16px;font-size:14px;font-weight:700;color:#15803d;border-top:2px solid #16a34a;">Total Received</td>
          <td style="padding:12px 16px;text-align:right;font-size:16px;font-weight:700;color:#15803d;border-top:2px solid #16a34a;">{{currency}}{{amount}}</td>
        </tr>
      </table>
    </td>
  </tr>
  <!-- Remaining balance -->
  <tr>
    <td style="padding:16px 36px 0;">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;">
        <tr>
          <td style="font-size:13px;color:#92400e;">Total Outstanding Balance after this payment: <strong>{{currency}}{{outstanding_total}}</strong></td>
        </tr>
      </table>
    </td>
  </tr>
  <!-- Narration / reference -->
  <tr>
    <td style="padding:16px 36px 0;">
      <p style="margin:0;font-size:13px;color:#6b7280;">Reference: <strong>{{reference}}</strong></p>
      {{narration_row}}
    </td>
  </tr>
  <!-- Footer -->
  <tr>
    <td style="padding:28px 36px;border-top:1px solid #e5e7eb;margin-top:24px;">
      <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">This is a system-generated receipt. No signature required.</p>
      <p style="margin:0;font-size:12px;color:#9ca3af;">{{app_name}} &bull; Accounts Office &bull; {{payment_date}}</p>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>',
'{{student_name}},{{student_sid}},{{department}},{{voucher_number}},{{payment_date}},{{fee_type_label}},{{semester_label}},{{currency}},{{amount}},{{outstanding_total}},{{reference}},{{narration_row}},{{app_name}},{{logo_url}}',
1
);
