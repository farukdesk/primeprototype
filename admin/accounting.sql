-- ============================================================
-- Accounting Module – Database Schema
-- Prime University Admin Panel
-- ============================================================

-- ── Chart of Accounts ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `acc_accounts` (
    `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `code`            VARCHAR(20)       NOT NULL,
    `name`            VARCHAR(200)      NOT NULL,
    `type`            ENUM('asset','liability','equity','income','expense') NOT NULL,
    `sub_type`        VARCHAR(60)       NULL,
    `parent_id`       INT UNSIGNED      NULL,
    `is_system`       TINYINT(1)        NOT NULL DEFAULT 0 COMMENT 'System accounts cannot be deleted',
    `is_active`       TINYINT(1)        NOT NULL DEFAULT 1,
    `opening_balance` DECIMAL(15,2)     NOT NULL DEFAULT 0.00,
    `description`     TEXT              NULL,
    `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_acc_code` (`code`),
    KEY `idx_acc_type` (`type`),
    KEY `idx_acc_parent` (`parent_id`),
    CONSTRAINT `fk_acc_parent` FOREIGN KEY (`parent_id`) REFERENCES `acc_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Vouchers ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `acc_vouchers` (
    `id`             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `voucher_number` VARCHAR(30)       NOT NULL,
    `voucher_type`   ENUM('receipt','payment','contra','journal') NOT NULL,
    `voucher_date`   DATE              NOT NULL,
    `reference`      VARCHAR(150)      NULL,
    `narration`      TEXT              NULL,
    `total_amount`   DECIMAL(15,2)     NOT NULL DEFAULT 0.00,
    `status`         ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
    `is_deleted`     TINYINT(1)        NOT NULL DEFAULT 0 COMMENT 'Soft-delete only',
    `created_by`     INT UNSIGNED      NOT NULL,
    `reversed_by`    INT UNSIGNED      NULL,
    `reversed_at`    DATETIME          NULL,
    `reversal_of`    INT UNSIGNED      NULL COMMENT 'Points to original voucher if this is a reversal',
    `created_at`     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_voucher_number` (`voucher_number`),
    KEY `idx_voucher_type` (`voucher_type`),
    KEY `idx_voucher_date` (`voucher_date`),
    KEY `idx_voucher_status` (`status`),
    CONSTRAINT `fk_voucher_creator` FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`),
    CONSTRAINT `fk_voucher_reverser` FOREIGN KEY (`reversed_by`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_voucher_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `acc_vouchers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Voucher Items (Double-Entry Lines) ──────────────────────
CREATE TABLE IF NOT EXISTS `acc_voucher_items` (
    `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `voucher_id`    INT UNSIGNED      NOT NULL,
    `account_id`    INT UNSIGNED      NOT NULL,
    `description`   VARCHAR(255)      NULL,
    `debit_amount`  DECIMAL(15,2)     NOT NULL DEFAULT 0.00,
    `credit_amount` DECIMAL(15,2)     NOT NULL DEFAULT 0.00,
    `created_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vi_voucher` (`voucher_id`),
    KEY `idx_vi_account` (`account_id`),
    CONSTRAINT `fk_vi_voucher`  FOREIGN KEY (`voucher_id`)  REFERENCES `acc_vouchers`(`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_vi_account`  FOREIGN KEY (`account_id`)  REFERENCES `acc_accounts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Settings ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `acc_settings` (
    `setting_key`   VARCHAR(100)      NOT NULL,
    `setting_value` TEXT              NOT NULL,
    `updated_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Default Chart of Accounts (University)
-- ============================================================
INSERT INTO `acc_accounts` (`code`, `name`, `type`, `sub_type`, `parent_id`, `is_system`, `opening_balance`) VALUES
-- ── Assets ──
('1000', 'Cash & Bank',             'asset', 'current_asset',  NULL, 1, 0.00),
('1100', 'Petty Cash',              'asset', 'current_asset',  NULL, 1, 0.00),
('1200', 'Main Bank Account',       'asset', 'current_asset',  NULL, 1, 0.00),
('1300', 'Other Bank Accounts',     'asset', 'current_asset',  NULL, 0, 0.00),
('1400', 'Accounts Receivable',     'asset', 'current_asset',  NULL, 1, 0.00),
('1500', 'Prepaid Expenses',        'asset', 'current_asset',  NULL, 0, 0.00),
('1600', 'Fixed Assets',            'asset', 'fixed_asset',    NULL, 0, 0.00),
-- ── Liabilities ──
('2100', 'Accounts Payable',        'liability', 'current_liability', NULL, 1, 0.00),
('2200', 'Accrued Liabilities',     'liability', 'current_liability', NULL, 0, 0.00),
('2300', 'Deferred Revenue',        'liability', 'current_liability', NULL, 0, 0.00),
('2400', 'Long-Term Liabilities',   'liability', 'long_term_liability', NULL, 0, 0.00),
-- ── Equity ──
('3100', 'University Fund',         'equity', 'equity', NULL, 1, 0.00),
('3200', 'Retained Surplus',        'equity', 'equity', NULL, 1, 0.00),
-- ── Income ──
('4100', 'Tuition Fees',            'income', 'revenue', NULL, 1, 0.00),
('4200', 'Admission Fees',          'income', 'revenue', NULL, 0, 0.00),
('4300', 'Library Fees',            'income', 'revenue', NULL, 0, 0.00),
('4400', 'Examination Fees',        'income', 'revenue', NULL, 0, 0.00),
('4500', 'Lab Fees',                'income', 'revenue', NULL, 0, 0.00),
('4600', 'Form Sale Revenue',       'income', 'revenue', NULL, 0, 0.00),
('4700', 'Miscellaneous Income',    'income', 'revenue', NULL, 1, 0.00),
-- ── Expenses ──
('5100', 'Faculty Salaries',        'expense', 'operating_expense', NULL, 0, 0.00),
('5200', 'Administrative Salaries', 'expense', 'operating_expense', NULL, 0, 0.00),
('5300', 'Utilities',               'expense', 'operating_expense', NULL, 0, 0.00),
('5400', 'Office Supplies',         'expense', 'operating_expense', NULL, 0, 0.00),
('5500', 'Maintenance & Repairs',   'expense', 'operating_expense', NULL, 0, 0.00),
('5600', 'Marketing & Advertising', 'expense', 'operating_expense', NULL, 0, 0.00),
('5700', 'IT & Technology',         'expense', 'operating_expense', NULL, 0, 0.00),
('5800', 'Library Expenses',        'expense', 'operating_expense', NULL, 0, 0.00),
('5900', 'Miscellaneous Expenses',  'expense', 'operating_expense', NULL, 1, 0.00);

-- ── Default Settings ────────────────────────────────────────
INSERT INTO `acc_settings` (`setting_key`, `setting_value`) VALUES
('next_receipt_number',  '1'),
('next_payment_number',  '1'),
('next_contra_number',   '1'),
('next_journal_number',  '1'),
('fiscal_year_start',    '07-01'),
('default_cash_account', '1100'),
('default_bank_account', '1200'),
('currency_symbol',      '৳'),
('currency_code',        'BDT'),
('income_account_admission',        '4200'),
('income_account_registration',     '4100'),
('income_account_semester_tuition', '4100'),
('income_account_fixed_fee',        '4100'),
('income_account_english_fee',      '4100'),
('income_account_other',            '4700');

-- ── Register modules ────────────────────────────────────────
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`) VALUES
('Accounting',         'accounting',         'Full double-entry accounting system', 'fas fa-coins',      80, 1),
('Chart of Accounts',  'accounting-coa',     'Manage chart of accounts',            'fas fa-sitemap',    81, 1),
('Accounting Reports', 'accounting-reports', 'Financial reports and statements',    'fas fa-chart-line', 82, 1);
