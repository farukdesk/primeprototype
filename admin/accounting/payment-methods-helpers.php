<?php
/**
 * Payment Method module – shared helpers
 * =======================================
 * Powers online fee payments:
 *   • acc_payment_methods  – bank accounts & mobile-banking wallets students
 *     can pay into. Managed from Accounting → Payment Methods; each method can
 *     be activated / deactivated at any time.
 *   • acc_online_payments  – student-submitted online payment claims awaiting
 *     review (Pay Online on the Student Accounts Portal).
 *
 * Students pay through a bank deposit / transfer or mobile banking, then
 * submit the payment details and a receipt/screenshot. Accounts staff review
 * the claim from Accounting → Online Payments and approve or reject it.
 * Approval confirms the money was received — the payment is then recorded in
 * the books through Collect Payment as usual (which enforces the unique
 * transaction-number rule and the fee-head allocation).
 *
 * Both tables are created automatically on first use, and the payment methods
 * are seeded once with the university's current accounts.
 */

require_once __DIR__ . '/helpers.php';

const OPM_MAX_UPLOAD_BYTES   = 5242880; // 5 MB
const OPM_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
const OPM_MOBILE_OPERATORS   = ['Bkash', 'Nagad', 'Rocket'];

/**
 * Create the module tables when missing and seed the default methods once.
 */
function opm_ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS acc_payment_methods (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            method_type ENUM('bank','mobile_banking') NOT NULL,
            bank_name VARCHAR(190) NULL DEFAULT NULL,
            branch_name VARCHAR(190) NULL DEFAULT NULL,
            account_name VARCHAR(190) NULL DEFAULT NULL,
            account_number VARCHAR(64) NULL DEFAULT NULL,
            operator_name VARCHAR(64) NULL DEFAULT NULL,
            wallet_number VARCHAR(32) NULL DEFAULT NULL,
            charge_note VARCHAR(190) NULL DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS acc_online_payments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            method_id INT UNSIGNED NOT NULL,
            method_type ENUM('bank','mobile_banking') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            paid_from VARCHAR(190) NOT NULL,
            paid_date DATE NOT NULL,
            paid_time VARCHAR(20) NULL DEFAULT NULL,
            transaction_number VARCHAR(190) NOT NULL,
            receipt_file VARCHAR(255) NULL DEFAULT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            admin_note TEXT NULL DEFAULT NULL,
            reviewed_by INT UNSIGNED NULL DEFAULT NULL,
            reviewed_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_opm_student (student_id),
            KEY idx_opm_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    opm_seed_default_methods();
    opm_upgrade_schema();
    $done = true;
}

/**
 * Add columns introduced after the first release:
 *   • payer_bank_name / payer_account_name / payer_account_number — the
 *     student's own bank details for bank transfers ("Paid From");
 *   • voucher_id — the receipt voucher created when an approved payment is
 *     auto-posted to the books.
 * Only ALTERs when a column is actually missing, so it is safe on every request.
 */
function opm_upgrade_schema(): void
{
    $existing = db()->query('SHOW COLUMNS FROM acc_online_payments')->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'payer_bank_name'      => 'VARCHAR(190) NULL DEFAULT NULL AFTER paid_from',
        'payer_account_name'   => 'VARCHAR(190) NULL DEFAULT NULL AFTER payer_bank_name',
        'payer_account_number' => 'VARCHAR(64) NULL DEFAULT NULL AFTER payer_account_name',
        'voucher_id'           => 'INT UNSIGNED NULL DEFAULT NULL AFTER reviewed_at',
    ];
    foreach ($add as $col => $definition) {
        if (!in_array($col, $existing, true)) {
            db()->exec('ALTER TABLE acc_online_payments ADD COLUMN ' . $col . ' ' . $definition);
        }
    }
}

/**
 * Seed the university's current accounts — only when the table is empty, so
 * anything added / edited / deleted later is never overwritten.
 */
function opm_seed_default_methods(): void
{
    $count = (int)db()->query('SELECT COUNT(*) FROM acc_payment_methods')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $ins = db()->prepare(
        'INSERT INTO acc_payment_methods
            (method_type, bank_name, branch_name, account_name, account_number,
             operator_name, wallet_number, charge_note, is_active, sort_order)
         VALUES (?,?,?,?,?,?,?,?,1,?)'
    );
    $banks = [
        ['Shahjalal Islami Bank PLC', 'Darus Salam Road Branch', 'Prime University Fee Collection', '407611100000337'],
        ['United Commercial Bank PLC', 'Mirpur Branch', 'PRIME UNIVERSITY STUDENT FEES COLLECTION ACCOUNT', '0561301000000236'],
        ['Brac Bank PLC', 'Mazar Road Sub Branch', 'Prime University', '2079666320001'],
        ['Jamuna Bank PLC', 'Mazar Road Sub Branch', 'Prime University', '1001001800976'],
    ];
    foreach ($banks as $i => $b) {
        $ins->execute(['bank', $b[0], $b[1], $b[2], $b[3], null, null, null, $i + 1]);
    }
    $wallets = [
        ['Bkash', '01319311974', '1.5% Charge Applicable'],
        ['Rocket', '3101', 'Charge Free'],
    ];
    foreach ($wallets as $i => $w) {
        $ins->execute(['mobile_banking', null, null, null, null, $w[0], $w[1], $w[2], $i + 1]);
    }
}

/**
 * All payment methods, banks first then wallets, in their configured order.
 *
 * @return array<int,array<string,mixed>>
 */
function opm_all_methods(bool $only_active = false): array
{
    opm_ensure_tables();
    $sql = 'SELECT * FROM acc_payment_methods'
         . ($only_active ? ' WHERE is_active = 1' : '')
         . " ORDER BY FIELD(method_type, 'bank', 'mobile_banking'), sort_order ASC, id ASC";
    return db()->query($sql)->fetchAll();
}

function opm_get_method(int $id): ?array
{
    opm_ensure_tables();
    $stmt = db()->prepare('SELECT * FROM acc_payment_methods WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function opm_type_label(string $type): string
{
    return $type === 'bank' ? 'Bank' : 'Mobile Banking';
}

/**
 * Short human title for a method (dropdowns, review lists).
 */
function opm_method_title(array $m): string
{
    if ((string)($m['method_type'] ?? '') === 'bank') {
        $t = trim((string)($m['bank_name'] ?? ''));
        $branch = trim((string)($m['branch_name'] ?? ''));
        return $branch !== '' ? $t . ' — ' . $branch : $t;
    }
    $t = trim((string)($m['operator_name'] ?? ''));
    $num = trim((string)($m['wallet_number'] ?? ''));
    return $num !== '' ? $t . ' (' . $num . ')' : $t;
}

/**
 * Payment guideline shown to students for a method type. Editable from
 * Accounting → Payment Methods (stored in acc_settings); sensible defaults
 * are used until one is saved.
 */
function opm_guideline(string $type): string
{
    $key = $type === 'bank' ? 'online_pay_guideline_bank' : 'online_pay_guideline_mobile';
    $default = $type === 'bank'
        ? "1. Deposit or transfer the exact amount to the selected bank account.\n"
        . "2. Keep the deposit slip / transfer receipt — the transaction or reference number on it is required.\n"
        . "3. Fill in this form with the account name you paid from, the amount, date, time and transaction number.\n"
        . "4. Upload a clear photo or screenshot of the receipt.\n"
        . "5. Verification is normally completed within 24 hours, but occasionally may take up to 48 hours."
        : "1. Send the exact amount to the selected wallet number.\n"
        . "2. If the operator applies a charge (see the note next to the operator), add it on top so the university receives the full fee amount.\n"
        . "3. Note the Transaction ID (TrxID) from the confirmation SMS.\n"
        . "4. Fill in this form with the wallet number you paid from, the amount, date, time and TrxID.\n"
        . "5. Upload a screenshot of the payment confirmation.\n"
        . "6. Verification is normally completed within 24 hours, but occasionally may take up to 48 hours.";
    $v = acc_setting($key, '');
    return $v !== '' ? $v : $default;
}

function opm_save_guideline(string $type, string $text): void
{
    $key = $type === 'bank' ? 'online_pay_guideline_bank' : 'online_pay_guideline_mobile';
    acc_save_setting($key, trim($text));
}

/**
 * How many submissions are waiting for review.
 */
function opm_pending_count(): int
{
    opm_ensure_tables();
    return (int)db()->query("SELECT COUNT(*) FROM acc_online_payments WHERE status = 'pending'")->fetchColumn();
}

/**
 * A student's own submissions, newest first, with a resolved method title.
 *
 * @return array<int,array<string,mixed>>
 */
function opm_student_submissions(int $student_pk, int $limit = 50): array
{
    opm_ensure_tables();
    $stmt = db()->prepare(
        'SELECT p.*, m.method_type AS m_type, m.bank_name, m.branch_name,
                m.operator_name, m.wallet_number
         FROM acc_online_payments p
         LEFT JOIN acc_payment_methods m ON m.id = p.method_id
         WHERE p.student_id = ?
         ORDER BY p.id DESC
         LIMIT ' . max(1, $limit)
    );
    $stmt->execute([$student_pk]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['method_title'] = $r['m_type'] !== null
            ? opm_method_title([
                'method_type'   => (string)$r['m_type'],
                'bank_name'     => $r['bank_name'],
                'branch_name'   => $r['branch_name'],
                'operator_name' => $r['operator_name'],
                'wallet_number' => $r['wallet_number'],
            ])
            : opm_type_label((string)$r['method_type']);
    }
    unset($r);
    return $rows;
}

/**
 * Filesystem directory for uploaded receipts (created on demand).
 */
function opm_upload_dir(): string
{
    $dir = dirname(__DIR__) . '/uploads/online-payments';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function opm_receipt_url(string $file): string
{
    return APP_URL . '/uploads/online-payments/' . rawurlencode($file);
}

/**
 * Bootstrap badge for a submission status.
 */
function opm_status_badge(string $status): string
{
    return match ($status) {
        'approved' => '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Approved</span>',
        'rejected' => '<span class="badge bg-danger"><i class="fas fa-times me-1"></i>Rejected</span>',
        default    => '<span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i>Pending Review</span>',
    };
}
