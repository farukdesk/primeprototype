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

// ── Auto-posting on approval ──────────────────────────────────────────

/**
 * Build the ordered list of outstanding fee obligations for a student, oldest
 * obligation first, from acc_student_fee_summary(). Used to auto-allocate an
 * approved online payment against dues.
 *
 * Order: admission → form fee → ID card fee → per semester (registration,
 * then each monthly tuition installment) → bi-tri shift months → project fee.
 *
 * @return array<int,array<string,mixed>>
 */
function opm_build_outstanding_queue(array $summary): array
{
    $queue = [];
    $push = static function (
        string $fee_type, float $out,
        ?int $semester_fee_id = null, ?int $semester_number = null, ?int $month_number = null,
        string $semester_label = '', string $month_label = ''
    ) use (&$queue): void {
        if ($out > 0.009) {
            $queue[] = [
                'fee_type'        => $fee_type,
                'out'             => round($out, 2),
                'semester_fee_id' => $semester_fee_id,
                'semester_number' => $semester_number,
                'month_number'    => $month_number,
                'semester_label'  => $semester_label,
                'month_label'     => $month_label,
            ];
        }
    };

    $tot = $summary['totals'] ?? [];
    $push('admission',   (float)(($tot['admission']['out']   ?? 0)));
    $push('form_fee',    (float)(($tot['form_fee']['out']    ?? 0)));
    $push('id_card_fee', (float)(($tot['id_card_fee']['out'] ?? 0)));

    foreach (($summary['semesters'] ?? []) as $sf) {
        $sem_label = trim((string)($sf['semester_label'] ?? '')) !== ''
            ? (string)$sf['semester_label']
            : 'Semester ' . (int)$sf['semester_number'];
        $push('registration', (float)($sf['reg_out'] ?? 0), (int)$sf['id'], (int)$sf['semester_number'], null, $sem_label);
        foreach (($sf['monthly_rows'] ?? []) as $mr) {
            $push(
                'semester_tuition', (float)($mr['out'] ?? 0),
                (int)$sf['id'], (int)$sf['semester_number'], (int)$mr['month_number'],
                $sem_label, (string)($mr['month_label'] ?? ('Month ' . (int)$mr['month_number']))
            );
        }
    }

    foreach ((($summary['bi_tri_shift'] ?? [])['months'] ?? []) as $btm) {
        $push('bi_tri_shift_fee', (float)($btm['out'] ?? 0), null, null, (int)$btm['month_number'], '', (string)($btm['month_label'] ?? ''));
    }
    $push('project_fee', (float)(($tot['project_fee']['out'] ?? 0)));

    return $queue;
}

/**
 * Post an approved online payment to the books automatically:
 *   • allocates the paid amount to the student's outstanding dues (oldest
 *     first); any remainder is recorded as an advance under the "other" head;
 *   • posts ONE receipt voucher (debit the mapped received-into account,
 *     credit the mapped income accounts) plus matching sfp_payments rows so
 *     the dues update immediately;
 *   • links the voucher to the submission (voucher_id) and emails the
 *     auto-created invoice (student-copy PDF) to the student.
 *
 * @param array $p acc_online_payments row (optionally with m_operator = wallet operator name)
 * @return array{voucher_id:int, voucher_number:string, total:float, advance:float}
 * @throws RuntimeException when the payment cannot be posted (submission must stay pending)
 */
function opm_post_approved_payment(array $p): array
{
    $submission_id = (int)($p['id'] ?? 0);
    $student_pk    = (int)($p['student_id'] ?? 0);
    $amount        = round((float)($p['amount'] ?? 0), 2);
    $txn           = trim((string)($p['transaction_number'] ?? ''));
    $method        = (string)($p['method_type'] ?? '');

    if ($submission_id <= 0 || $student_pk <= 0) {
        throw new RuntimeException('Invalid online payment submission.');
    }
    if ($amount <= 0) {
        throw new RuntimeException('The submitted amount is invalid.');
    }
    if (!in_array($method, ['bank', 'mobile_banking'], true)) {
        throw new RuntimeException('Invalid payment method type.');
    }
    if ($txn === '') {
        throw new RuntimeException('The submission has no transaction number.');
    }
    if (function_exists('acc_transaction_number_exists') && acc_transaction_number_exists($txn)) {
        throw new RuntimeException('Transaction number "' . $txn . '" is already recorded in the books.');
    }

    $stu_stmt = db()->prepare(
        'SELECT s.*, d.name AS dept_name, pr.program_name
         FROM students s
         LEFT JOIN dept_departments d        ON d.id = s.dept_id
         LEFT JOIN dept_academic_programs pr ON pr.id = s.program_id
         WHERE s.id = ? LIMIT 1'
    );
    $stu_stmt->execute([$student_pk]);
    $stu = $stu_stmt->fetch();
    if (!$stu) {
        throw new RuntimeException('Student record not found.');
    }

    $pkg_stmt = db()->prepare('SELECT id FROM sfp_packages WHERE student_id = ? LIMIT 1');
    $pkg_stmt->execute([$student_pk]);
    $package_id = (int)($pkg_stmt->fetchColumn() ?: 0);
    if (!$package_id) {
        throw new RuntimeException('This student has no fee package assigned yet. Assign one from Student Accounts, then approve.');
    }

    $summary = acc_student_fee_summary($student_pk);
    if (!$summary) {
        throw new RuntimeException('Could not load the student\'s fee summary.');
    }

    $received_into_account_id = acc_received_into_account_id_for_payment_method($method);
    if ($received_into_account_id <= 0) {
        throw new RuntimeException('Received-into account mapping is missing for "' . opm_type_label($method) . '". Configure it in Accounting Settings.');
    }

    $provider = null;
    if ($method === 'mobile_banking') {
        $op = strtolower(trim((string)($p['m_operator'] ?? '')));
        if (in_array($op, ['bkash', 'nagad', 'rocket'], true)) {
            $provider = $op;
        }
    }

    // ── Allocate the paid amount to outstanding dues, oldest first ──
    $income_map = acc_income_account_map_for_fee_types();
    $items      = [];
    $remaining  = $amount;
    foreach (opm_build_outstanding_queue($summary) as $q) {
        if ($remaining <= 0.009) {
            break;
        }
        $take = round(min((float)$q['out'], $remaining), 2);
        if ($take <= 0) {
            continue;
        }
        $remaining   = round($remaining - $take, 2);
        $q['amount'] = $take;
        $items[]     = $q;
    }
    $advance = 0.0;
    if ($remaining > 0.009) {
        // Overpayment: record the remainder as an advance under "other".
        $advance = $remaining;
        $items[] = [
            'fee_type' => 'other', 'amount' => $advance,
            'semester_fee_id' => null, 'semester_number' => null, 'month_number' => null,
            'semester_label' => '', 'month_label' => '',
        ];
    }
    if (!$items) {
        throw new RuntimeException('Nothing to allocate — no outstanding dues found and the amount is zero.');
    }

    // ── Post ONE receipt voucher (same shape as Collect Payment multi mode) ──
    $narration = 'Online payment #' . $submission_id . ' (' . opm_type_label($method) . ') — auto-posted on approval';
    $reference = 'Online payment #' . $submission_id;

    $credit_by_income = [];
    foreach ($items as $it) {
        $income_id = (int)($income_map[$it['fee_type']] ?? 0);
        if ($income_id <= 0) {
            throw new RuntimeException('Income account mapping is missing for fee type "' . acc_fee_type_label((string)$it['fee_type']) . '". Configure it in Accounting Settings.');
        }
        $credit_by_income[$income_id] = round(($credit_by_income[$income_id] ?? 0.0) + (float)$it['amount'], 2);
    }
    $voucher_lines = [[
        'account_id'  => $received_into_account_id,
        'debit'       => $amount,
        'credit'      => 0,
        'description' => $narration,
    ]];
    foreach ($credit_by_income as $income_id => $credit_total) {
        $voucher_lines[] = [
            'account_id'  => (int)$income_id,
            'debit'       => 0,
            'credit'      => $credit_total,
            'description' => $narration,
        ];
    }

    $voucher_date   = (string)($p['paid_date'] ?? '') !== '' ? (string)$p['paid_date'] : date('Y-m-d');
    $voucher_id     = acc_post_voucher('receipt', $voucher_date, $voucher_lines, $narration, $reference);
    $voucher        = acc_get_voucher($voucher_id);
    $voucher_number = (string)($voucher['voucher_number'] ?? '—');

    // ── Record sfp_payments rows so dues, statements and history update ──
    $pay_stmt = db()->prepare(
        'INSERT INTO sfp_payments
            (student_id, package_id, semester_fee_id, fee_type, semester_number, month_number, payment_method, mobile_banking_provider, transaction_number, amount, voucher_id, note, collected_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $user = auth_user();
    $txn_used_for_fee_type = [];
    $invoice_items = [];
    foreach ($items as $it) {
        $item_txn = null;
        if (!isset($txn_used_for_fee_type[$it['fee_type']])) {
            // Legacy unique index is (transaction_number, fee_type): one receipt
            // may repeat across fee types but not within the same fee type.
            $item_txn = $txn;
            $txn_used_for_fee_type[$it['fee_type']] = true;
        }
        $item_note = $it['fee_type'] === 'other'
            ? 'Advance from online payment #' . $submission_id . ' (amount above current dues)'
            : $narration;
        $pay_stmt->execute([
            $student_pk,
            $package_id,
            $it['semester_fee_id'],
            $it['fee_type'],
            $it['semester_number'],
            $it['month_number'],
            $method,
            $provider,
            $item_txn,
            round((float)$it['amount'], 2),
            $voucher_id,
            $item_note,
            $user['id'] ?? null,
        ]);
        $invoice_items[] = [
            'voucher_id'     => $voucher_id,
            'voucher_number' => $voucher_number,
            'fee_type_label' => $it['fee_type'] === 'other' ? 'Advance / On Account' : acc_fee_type_label((string)$it['fee_type']),
            'semester_label' => (string)$it['semester_label'],
            'month_label'    => (string)$it['month_label'],
            'amount'         => (float)$it['amount'],
            'narration'      => $item_note,
        ];
    }

    // Link the voucher to the submission for the review queue / audit trail.
    db()->prepare('UPDATE acc_online_payments SET voucher_id = ? WHERE id = ?')
        ->execute([$voucher_id, $submission_id]);

    // ── Email the auto-created invoice (student-copy PDF) ──
    $multi = count($invoice_items) > 1;
    acc_send_fee_invoice_email($stu, [
        'voucher_id'     => $voucher_id,
        'voucher_number' => $voucher_number,
        'payment_date'   => $voucher_date,
        'fee_type_label' => $multi ? 'Multiple Fee Payment' : $invoice_items[0]['fee_type_label'],
        'semester_label' => $multi ? '' : $invoice_items[0]['semester_label'],
        'amount'         => $amount,
        'reference'      => $reference,
        'narration'      => $narration,
    ], $invoice_items);

    return [
        'voucher_id'     => $voucher_id,
        'voucher_number' => $voucher_number,
        'total'          => $amount,
        'advance'        => $advance,
    ];
}
