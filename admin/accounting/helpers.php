<?php
/**
 * Accounting Module – Shared Helpers & Accounting Engine
 * ========================================================
 * Implements full double-entry accounting system.
 * Users interact only via collect-payment / add-expense / transfer-money.
 * All debit/credit logic is handled here automatically.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

const ACC_INVOICE_CUSTOM_LOGO_FILE = 'Prime_University_Invoice logo.png';

// ── Permission helpers ────────────────────────────────────────────────────────

function acc_can_view(): bool
{
    return is_super_admin() || can_access('accounting');
}

function acc_can_create(): bool
{
    return is_super_admin() || can_access('accounting', 'can_create');
}

function acc_can_manage_coa(): bool
{
    return is_super_admin() || can_access('accounting-coa', 'can_edit');
}

function acc_can_reports(): bool
{
    return is_super_admin() || can_access('accounting-reports');
}

// ── Settings helpers ──────────────────────────────────────────────────────────

function acc_setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $row = db()->prepare('SELECT setting_value FROM acc_settings WHERE setting_key = ?');
        $row->execute([$key]);
        $cache[$key] = $row->fetchColumn() ?: $default;
    }
    return $cache[$key];
}

function acc_save_setting(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO acc_settings (setting_key, setting_value) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

function acc_currency(): string
{
    return acc_setting('currency_symbol', '৳');
}

function acc_university_logo_url(): string
{
    $base = defined('SITE_URL') ? SITE_URL : APP_URL;
    $custom_logo_abs = dirname(__DIR__) . '/uploads/logos/' . ACC_INVOICE_CUSTOM_LOGO_FILE;
    if (is_file($custom_logo_abs) && is_readable($custom_logo_abs)) {
        return rtrim($base, '/') . '/admin/uploads/logos/' . rawurlencode(ACC_INVOICE_CUSTOM_LOGO_FILE);
    }
    return rtrim($base, '/') . '/assets/img/logo/logo-black-sm.png';
}

/**
 * Return logo as a base64 data URI for embedding in PDF (dompdf cannot fetch remote URLs).
 */
function acc_logo_data_uri(): string
{
    $custom_logo = dirname(__DIR__) . '/uploads/logos/' . ACC_INVOICE_CUSTOM_LOGO_FILE;
    if (is_file($custom_logo) && is_readable($custom_logo)) {
        $logo_bytes = file_get_contents($custom_logo);
        if ($logo_bytes !== false) {
            return 'data:image/png;base64,' . base64_encode($logo_bytes);
        }
    }
    $default_logo = dirname(dirname(__DIR__)) . '/assets/img/logo/logo-black-sm.png';
    if (is_file($default_logo) && is_readable($default_logo)) {
        $logo_bytes = file_get_contents($default_logo);
        if ($logo_bytes !== false) {
            return 'data:image/png;base64,' . base64_encode($logo_bytes);
        }
    }
    return '';
}

function acc_university_address(): string
{
    return '114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh';
}

function acc_university_website(): string
{
    return 'https://www.primeuniversity.ac.bd/';
}

function acc_fmt(float $amount): string
{
    return acc_currency() . ' ' . number_format($amount, 2);
}

// ── Voucher number generator ──────────────────────────────────────────────────

function acc_next_voucher_number(string $type): string
{
    $key_map = [
        'receipt' => 'next_receipt_number',
        'payment' => 'next_payment_number',
        'contra'  => 'next_contra_number',
        'journal' => 'next_journal_number',
    ];
    $prefix_map = [
        'receipt' => 'RV',
        'payment' => 'PV',
        'contra'  => 'CV',
        'journal' => 'JV',
    ];

    $key    = $key_map[$type]    ?? 'next_journal_number';
    $prefix = $prefix_map[$type] ?? 'JV';
    $year   = date('Y');

    $db = db();

    // Ensure the counter row exists so FOR UPDATE can lock it.
    $db->prepare(
        'INSERT IGNORE INTO acc_settings (setting_key, setting_value) VALUES (?, \'1\')'
    )->execute([$key]);

    // Atomically reserve the next number with a row-level lock so concurrent
    // requests queue up and each receives a distinct counter value.
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT setting_value FROM acc_settings WHERE setting_key = ? FOR UPDATE');
        $stmt->execute([$key]);
        $raw = $stmt->fetchColumn();

        if ($raw === false) {
            throw new \RuntimeException("Voucher counter row missing for key: {$key}");
        }

        $current = (int)$raw;

        $db->prepare('UPDATE acc_settings SET setting_value = ? WHERE setting_key = ?')
           ->execute([(string)($current + 1), $key]);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $prefix . '-' . $year . '-' . str_pad((string)$current, 5, '0', STR_PAD_LEFT);
}

// ── Account fetch helpers ─────────────────────────────────────────────────────

function acc_get_account(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM acc_accounts WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function acc_accounts_by_type(string ...$types): array
{
    if (empty($types)) return [];
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM acc_accounts WHERE type IN ($placeholders) AND is_active = 1 ORDER BY code ASC"
    );
    $stmt->execute($types);
    return $stmt->fetchAll();
}

function acc_cash_accounts(): array
{
    return db()->query(
        "SELECT * FROM acc_accounts
         WHERE type = 'asset' AND (sub_type = 'current_asset')
           AND code LIKE '1%' AND is_active = 1
         ORDER BY code ASC"
    )->fetchAll();
}

/**
 * Look up an active current-asset account by its COA code.
 * Returns account id or 0 if not found.
 */
function acc_asset_account_id_by_code(string $code): int
{
    static $cache = [];
    $code = trim($code);
    if ($code === '') {
        return 0;
    }
    if (isset($cache[$code])) {
        return $cache[$code];
    }

    $stmt = db()->prepare(
        "SELECT id FROM acc_accounts
         WHERE code = ? AND type = 'asset' AND sub_type = 'current_asset' AND is_active = 1
         LIMIT 1"
    );
    $stmt->execute([$code]);
    return $cache[$code] = (int)($stmt->fetchColumn() ?: 0);
}

/**
 * Read configured received-into account code for payment method.
 */
function acc_received_into_account_code_for_payment_method(string $method): string
{
    $method = strtolower(trim($method));
    if (!in_array($method, ['cash', 'bank', 'mobile_banking'], true)) {
        return '';
    }
    $fallback = ($method === 'bank' || $method === 'mobile_banking')
        ? acc_setting('default_bank_account', '1200')
        : acc_setting('default_cash_account', '1100');

    $setting_key = match ($method) {
        'cash' => 'received_into_cash_account',
        'bank' => 'received_into_bank_account',
        'mobile_banking' => 'received_into_mobile_banking_account',
    };

    $code = trim(acc_setting($setting_key, $fallback));
    return $code !== '' ? $code : $fallback;
}

/**
 * Resolve mapped received-into account id for payment method.
 */
function acc_received_into_account_id_for_payment_method(string $method): int
{
    static $cache = [];
    $method = strtolower(trim($method));
    if (isset($cache[$method])) {
        return $cache[$method];
    }

    if (!in_array($method, ['cash', 'bank', 'mobile_banking'], true)) {
        return $cache[$method] = 0;
    }

    $id = acc_asset_account_id_by_code(acc_received_into_account_code_for_payment_method($method));
    if ($id > 0) {
        return $cache[$method] = $id;
    }

    $fallback_code = ($method === 'bank' || $method === 'mobile_banking')
        ? acc_setting('default_bank_account', '1200')
        : acc_setting('default_cash_account', '1100');
    $fallback_id = acc_asset_account_id_by_code($fallback_code);
    if ($fallback_id > 0) {
        return $cache[$method] = $fallback_id;
    }

    $stmt = db()->prepare(
        "SELECT id FROM acc_accounts
         WHERE type = 'asset' AND sub_type = 'current_asset' AND code LIKE '1%' AND is_active = 1
         ORDER BY code ASC LIMIT 1"
    );
    $stmt->execute();
    $any = $stmt->fetchColumn();
    return $cache[$method] = (int)($any ?: 0);
}

/**
 * Build payment-method => received-into account id map.
 *
 * @param string[]|null $methods
 * @return array<string,int>
 */
function acc_received_into_account_map_for_payment_methods(?array $methods = null): array
{
    $methods = $methods ?: ['cash', 'bank', 'mobile_banking'];
    $map = [];
    foreach ($methods as $method) {
        $map[$method] = acc_received_into_account_id_for_payment_method($method);
    }
    return $map;
}

function acc_income_accounts(): array
{
    return acc_accounts_by_type('income');
}

function acc_expense_accounts(): array
{
    return acc_accounts_by_type('expense');
}

function acc_all_active_accounts(): array
{
    return db()->query(
        "SELECT * FROM acc_accounts WHERE is_active = 1 ORDER BY code ASC"
    )->fetchAll();
}

// ── Balance computation ───────────────────────────────────────────────────────

/**
 * Compute the running balance of an account.
 * Normal balance rules:
 *   asset   / expense : balance = opening + debits − credits  (debit-normal)
 *   liability/equity/income : balance = opening + credits − debits  (credit-normal)
 *
 * @param int         $account_id
 * @param string|null $date_from  Y-m-d or null
 * @param string|null $date_to    Y-m-d or null
 */
function acc_account_balance(int $account_id, ?string $date_from = null, ?string $date_to = null): float
{
    $account = acc_get_account($account_id);
    if (!$account) return 0.0;

    $params  = [$account_id];
    $where   = 'vi.account_id = ? AND v.status = \'posted\' AND v.is_deleted = 0';

    if ($date_from) {
        $where   .= ' AND v.voucher_date >= ?';
        $params[] = $date_from;
    }
    if ($date_to) {
        $where   .= ' AND v.voucher_date <= ?';
        $params[] = $date_to;
    }

    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(vi.debit_amount),0) AS total_debit,
                COALESCE(SUM(vi.credit_amount),0) AS total_credit
         FROM acc_voucher_items vi
         JOIN acc_vouchers v ON v.id = vi.voucher_id
         WHERE $where"
    );
    $stmt->execute($params);
    $row = $stmt->fetch();

    $opening = (float)$account['opening_balance'];
    $debits  = (float)$row['total_debit'];
    $credits = (float)$row['total_credit'];

    // If date_from is null, include opening balance
    $use_opening = ($date_from === null);

    if (in_array($account['type'], ['asset', 'expense'], true)) {
        return ($use_opening ? $opening : 0.0) + $debits - $credits;
    } else {
        return ($use_opening ? $opening : 0.0) + $credits - $debits;
    }
}

// ── Core Accounting Engine: Post Voucher ──────────────────────────────────────

/**
 * Post a voucher with given line items.
 * Validates that total debits == total credits.
 *
 * @param string $type        receipt|payment|contra|journal
 * @param string $date        Y-m-d
 * @param array  $lines       [ ['account_id'=>int,'debit'=>float,'credit'=>float,'description'=>string], ... ]
 * @param string $narration
 * @param string $reference
 * @param int|null $reversal_of  Set when creating a reversal voucher
 *
 * @return int  The new voucher ID
 * @throws RuntimeException on validation failure
 */
function acc_post_voucher(
    string $type,
    string $date,
    array  $lines,
    string $narration  = '',
    string $reference  = '',
    ?int   $reversal_of = null
): int {
    // Validate debits == credits
    $total_debit  = 0.0;
    $total_credit = 0.0;
    foreach ($lines as $line) {
        $total_debit  += (float)($line['debit']  ?? 0);
        $total_credit += (float)($line['credit'] ?? 0);
    }

    if (round($total_debit, 2) !== round($total_credit, 2)) {
        throw new RuntimeException(
            'Accounting imbalance: total debit (' . $total_debit .
            ') ≠ total credit (' . $total_credit . '). Voucher not posted.'
        );
    }

    if ($total_debit <= 0) {
        throw new RuntimeException('Voucher amount must be greater than zero.');
    }

    $user          = auth_user();
    $voucher_num   = acc_next_voucher_number($type);

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO acc_vouchers
                (voucher_number, voucher_type, voucher_date, reference, narration, total_amount, status, created_by, reversal_of)
             VALUES (?,?,?,?,?,?,\'posted\',?,?)'
        )->execute([
            $voucher_num,
            $type,
            $date,
            $reference ?: null,
            $narration ?: null,
            $total_debit,
            $user['id'],
            $reversal_of,
        ]);

        $voucher_id = (int)$db->lastInsertId();

        $item_stmt = $db->prepare(
            'INSERT INTO acc_voucher_items (voucher_id, account_id, description, debit_amount, credit_amount)
             VALUES (?,?,?,?,?)'
        );
        foreach ($lines as $line) {
            $item_stmt->execute([
                $voucher_id,
                (int)$line['account_id'],
                $line['description'] ?? null,
                round((float)($line['debit']  ?? 0), 2),
                round((float)($line['credit'] ?? 0), 2),
            ]);
        }

        $db->commit();

        log_change(
            'accounting',
            'CREATE',
            $voucher_id,
            $voucher_num,
            null,
            null,
            null,
            ucfirst($type) . ' voucher posted: ' . $narration
        );

        return $voucher_id;

    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ── Collect Payment (Receipt Voucher) ────────────────────────────────────────
/**
 * UI abstraction: "Collect Payment"
 * Debit: cash/bank account, Credit: income account
 */
function acc_collect_payment(
    float  $amount,
    int    $cash_account_id,
    int    $income_account_id,
    string $date,
    string $reference  = '',
    string $narration  = ''
): int {
    return acc_post_voucher('receipt', $date, [
        ['account_id' => $cash_account_id,   'debit' => $amount, 'credit' => 0,       'description' => $narration],
        ['account_id' => $income_account_id, 'debit' => 0,       'credit' => $amount, 'description' => $narration],
    ], $narration, $reference);
}

// ── Add Expense (Payment Voucher) ─────────────────────────────────────────────
/**
 * UI abstraction: "Add Expense"
 * Debit: expense account, Credit: cash/bank account
 */
function acc_add_expense(
    float  $amount,
    int    $expense_account_id,
    int    $cash_account_id,
    string $date,
    string $reference  = '',
    string $narration  = ''
): int {
    return acc_post_voucher('payment', $date, [
        ['account_id' => $expense_account_id, 'debit' => $amount, 'credit' => 0,       'description' => $narration],
        ['account_id' => $cash_account_id,    'debit' => 0,       'credit' => $amount, 'description' => $narration],
    ], $narration, $reference);
}

// ── Transfer Money (Contra Voucher) ──────────────────────────────────────────
/**
 * UI abstraction: "Transfer Money"
 * Debit: destination account, Credit: source account
 */
function acc_transfer_money(
    float  $amount,
    int    $from_account_id,
    int    $to_account_id,
    string $date,
    string $reference  = '',
    string $narration  = ''
): int {
    return acc_post_voucher('contra', $date, [
        ['account_id' => $to_account_id,   'debit' => $amount, 'credit' => 0,       'description' => $narration],
        ['account_id' => $from_account_id, 'debit' => 0,       'credit' => $amount, 'description' => $narration],
    ], $narration, $reference);
}

// ── Reverse a Posted Voucher ──────────────────────────────────────────────────
/**
 * Creates a mirror-image reversal voucher.
 * The original voucher is marked as 'reversed'.
 */
function acc_reverse_voucher(int $voucher_id, string $reason = ''): int
{
    $db = db();

    $stmt = $db->prepare('SELECT * FROM acc_vouchers WHERE id = ? AND status = \'posted\' AND is_deleted = 0');
    $stmt->execute([$voucher_id]);
    $voucher = $stmt->fetch();
    if (!$voucher) {
        throw new RuntimeException('Voucher not found or already reversed.');
    }

    $items_stmt = $db->prepare('SELECT * FROM acc_voucher_items WHERE voucher_id = ?');
    $items_stmt->execute([$voucher_id]);
    $items = $items_stmt->fetchAll();

    // Swap debits and credits
    $reversed_lines = [];
    foreach ($items as $item) {
        $reversed_lines[] = [
            'account_id'  => $item['account_id'],
            'debit'       => (float)$item['credit_amount'],
            'credit'      => (float)$item['debit_amount'],
            'description' => 'REVERSAL: ' . ($item['description'] ?? ''),
        ];
    }

    $reversal_narration = 'Reversal of ' . $voucher['voucher_number'] . ($reason ? ' – ' . $reason : '');

    $reversal_id = acc_post_voucher(
        $voucher['voucher_type'],
        date('Y-m-d'),
        $reversed_lines,
        $reversal_narration,
        $voucher['reference'] ?? '',
        $voucher_id
    );

    // Mark original as reversed
    $user = auth_user();
    $db->prepare(
        "UPDATE acc_vouchers SET status = 'reversed', reversed_by = ?, reversed_at = NOW() WHERE id = ?"
    )->execute([$user['id'], $voucher_id]);

    log_change(
        'accounting',
        'UPDATE',
        $voucher_id,
        $voucher['voucher_number'],
        'status',
        'posted',
        'reversed',
        'Voucher reversed. Reversal reason: ' . $reason
    );

    return $reversal_id;
}

// ── Voucher fetch helpers ─────────────────────────────────────────────────────

function acc_get_voucher(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT v.*, u.full_name AS created_by_name, r.full_name AS reversed_by_name,
                rv.voucher_number AS reversal_voucher_number
         FROM acc_vouchers v
         LEFT JOIN users u ON u.id = v.created_by
         LEFT JOIN users r ON r.id = v.reversed_by
         LEFT JOIN acc_vouchers rv ON rv.reversal_of = v.id
         WHERE v.id = ? AND v.is_deleted = 0'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function acc_get_voucher_items(int $voucher_id): array
{
    $stmt = db()->prepare(
        'SELECT vi.*, a.code, a.name AS account_name, a.type AS account_type
         FROM acc_voucher_items vi
         JOIN acc_accounts a ON a.id = vi.account_id
         WHERE vi.voucher_id = ?
         ORDER BY vi.id ASC'
    );
    $stmt->execute([$voucher_id]);
    return $stmt->fetchAll();
}

// ── Voucher type label/badge ──────────────────────────────────────────────────

function acc_voucher_type_badge(string $type): string
{
    $map = [
        'receipt' => ['bg-success',         'Receipt'],
        'payment' => ['bg-danger',           'Payment'],
        'contra'  => ['bg-info text-dark',   'Transfer'],
        'journal' => ['bg-secondary',        'Journal'],
    ];
    [$cls, $label] = $map[$type] ?? ['bg-secondary', ucfirst($type)];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}

function acc_voucher_status_badge(string $status): string
{
    return match ($status) {
        'posted'   => '<span class="badge bg-success">Posted</span>',
        'reversed' => '<span class="badge bg-warning text-dark">Reversed</span>',
        default    => '<span class="badge bg-secondary">' . h(ucfirst($status)) . '</span>',
    };
}

// ── Report Helpers ────────────────────────────────────────────────────────────

/**
 * Trial Balance: returns all accounts with their net debit/credit totals.
 */
function acc_trial_balance(?string $date_from = null, ?string $date_to = null): array
{
    $params = [];
    $where  = "v.status = 'posted' AND v.is_deleted = 0";

    if ($date_from) { $where .= ' AND v.voucher_date >= ?'; $params[] = $date_from; }
    if ($date_to)   { $where .= ' AND v.voucher_date <= ?'; $params[] = $date_to;   }

    $stmt = db()->prepare(
        "SELECT a.id, a.code, a.name, a.type, a.sub_type, a.opening_balance,
                COALESCE(SUM(vi.debit_amount),0)  AS period_debit,
                COALESCE(SUM(vi.credit_amount),0) AS period_credit
         FROM acc_accounts a
         LEFT JOIN acc_voucher_items vi ON vi.account_id = a.id
         LEFT JOIN acc_vouchers v ON v.id = vi.voucher_id AND $where
         WHERE a.is_active = 1
         GROUP BY a.id, a.code, a.name, a.type, a.sub_type, a.opening_balance
         ORDER BY a.code ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $opening = (float)$row['opening_balance'];
        $debits  = (float)$row['period_debit'];
        $credits = (float)$row['period_credit'];

        if (in_array($row['type'], ['asset', 'expense'], true)) {
            $balance = $opening + $debits - $credits;
            $row['balance_debit']  = max(0, $balance);
            $row['balance_credit'] = max(0, -$balance);
        } else {
            $balance = $opening + $credits - $debits;
            $row['balance_debit']  = max(0, -$balance);
            $row['balance_credit'] = max(0, $balance);
        }
    }
    unset($row);

    return $rows;
}

/**
 * Income Statement: revenue vs expense for a period.
 */
function acc_income_statement(?string $date_from = null, ?string $date_to = null): array
{
    $params_base = [];
    $where  = "v.status = 'posted' AND v.is_deleted = 0";
    if ($date_from) { $where .= ' AND v.voucher_date >= ?'; $params_base[] = $date_from; }
    if ($date_to)   { $where .= ' AND v.voucher_date <= ?'; $params_base[] = $date_to;   }

    $stmt = db()->prepare(
        "SELECT a.id, a.code, a.name, a.type,
                COALESCE(SUM(vi.debit_amount),0)  AS total_debit,
                COALESCE(SUM(vi.credit_amount),0) AS total_credit
         FROM acc_accounts a
         LEFT JOIN acc_voucher_items vi ON vi.account_id = a.id
         LEFT JOIN acc_vouchers v ON v.id = vi.voucher_id AND $where
         WHERE a.type IN ('income','expense') AND a.is_active = 1
         GROUP BY a.id, a.code, a.name, a.type
         ORDER BY a.type DESC, a.code ASC"
    );
    $stmt->execute($params_base);
    $rows = $stmt->fetchAll();

    $revenue  = [];
    $expenses = [];

    foreach ($rows as $row) {
        if ($row['type'] === 'income') {
            $row['net'] = (float)$row['total_credit'] - (float)$row['total_debit'];
            $revenue[]  = $row;
        } else {
            $row['net']  = (float)$row['total_debit'] - (float)$row['total_credit'];
            $expenses[]  = $row;
        }
    }

    return [
        'revenue'       => $revenue,
        'expenses'      => $expenses,
        'total_revenue' => array_sum(array_column($revenue,  'net')),
        'total_expenses'=> array_sum(array_column($expenses, 'net')),
    ];
}

/**
 * Balance Sheet: assets vs liabilities + equity.
 */
function acc_balance_sheet(?string $as_of = null): array
{
    $params = [];
    $where  = "v.status = 'posted' AND v.is_deleted = 0";
    if ($as_of) { $where .= ' AND v.voucher_date <= ?'; $params[] = $as_of; }

    $stmt = db()->prepare(
        "SELECT a.id, a.code, a.name, a.type, a.opening_balance,
                COALESCE(SUM(vi.debit_amount),0)  AS total_debit,
                COALESCE(SUM(vi.credit_amount),0) AS total_credit
         FROM acc_accounts a
         LEFT JOIN acc_voucher_items vi ON vi.account_id = a.id
         LEFT JOIN acc_vouchers v ON v.id = vi.voucher_id AND $where
         WHERE a.type IN ('asset','liability','equity') AND a.is_active = 1
         GROUP BY a.id, a.code, a.name, a.type, a.opening_balance
         ORDER BY a.type, a.code ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $assets      = [];
    $liabilities = [];
    $equity      = [];

    foreach ($rows as $row) {
        $opening = (float)$row['opening_balance'];
        $d = (float)$row['total_debit'];
        $c = (float)$row['total_credit'];

        if ($row['type'] === 'asset') {
            $row['balance'] = $opening + $d - $c;
            $assets[]       = $row;
        } elseif ($row['type'] === 'liability') {
            $row['balance'] = $opening + $c - $d;
            $liabilities[]  = $row;
        } else {
            $row['balance'] = $opening + $c - $d;
            $equity[]       = $row;
        }
    }

    return [
        'assets'            => $assets,
        'liabilities'       => $liabilities,
        'equity'            => $equity,
        'total_assets'      => array_sum(array_column($assets,      'balance')),
        'total_liabilities' => array_sum(array_column($liabilities, 'balance')),
        'total_equity'      => array_sum(array_column($equity,      'balance')),
    ];
}

/**
 * Ledger: transaction history for a specific account with running balance.
 */
function acc_ledger_entries(int $account_id, ?string $date_from = null, ?string $date_to = null): array
{
    $account = acc_get_account($account_id);
    if (!$account) return [];

    $params = [$account_id];
    $where  = "vi.account_id = ? AND v.status = 'posted' AND v.is_deleted = 0";
    if ($date_from) { $where .= ' AND v.voucher_date >= ?'; $params[] = $date_from; }
    if ($date_to)   { $where .= ' AND v.voucher_date <= ?'; $params[] = $date_to;   }

    $stmt = db()->prepare(
        "SELECT v.id AS voucher_id, v.voucher_date, v.voucher_number, v.voucher_type, v.narration,
                vi.description, vi.debit_amount, vi.credit_amount
         FROM acc_voucher_items vi
         JOIN acc_vouchers v ON v.id = vi.voucher_id
         WHERE $where
         ORDER BY v.voucher_date ASC, v.id ASC, vi.id ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $is_debit_normal = in_array($account['type'], ['asset', 'expense'], true);
    $opening = (float)$account['opening_balance'];
    $balance = $opening;

    $entries = [];
    foreach ($rows as $row) {
        $d = (float)$row['debit_amount'];
        $c = (float)$row['credit_amount'];
        if ($is_debit_normal) {
            $balance += $d - $c;
        } else {
            $balance += $c - $d;
        }
        $row['balance'] = $balance;
        $entries[]      = $row;
    }

    return $entries;
}

/**
 * Cash Flow Statement (simplified operating-focused).
 */
function acc_cash_flow(?string $date_from = null, ?string $date_to = null): array
{
    // Get all cash/bank accounts (type=asset, codes starting with 1)
    $cash_accounts = db()->query(
        "SELECT id, code, name FROM acc_accounts
         WHERE type = 'asset' AND code REGEXP '^1[0-9]'  AND is_active = 1
         ORDER BY code ASC"
    )->fetchAll();

    $params = [];
    $where  = "v.status = 'posted' AND v.is_deleted = 0";
    if ($date_from) { $where .= ' AND v.voucher_date >= ?'; $params[] = $date_from; }
    if ($date_to)   { $where .= ' AND v.voucher_date <= ?'; $params[] = $date_to;   }

    $inflows  = [];
    $outflows = [];
    $total_in = 0.0;
    $total_out = 0.0;

    foreach ($cash_accounts as $ca) {
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(vi.debit_amount),0) AS total_in,
                    COALESCE(SUM(vi.credit_amount),0) AS total_out
             FROM acc_voucher_items vi
             JOIN acc_vouchers v ON v.id = vi.voucher_id
             WHERE vi.account_id = ? AND $where"
        );
        $stmt->execute(array_merge([$ca['id']], $params));
        $row = $stmt->fetch();

        $in  = (float)$row['total_in'];
        $out = (float)$row['total_out'];

        if ($in > 0) {
            $inflows[]  = ['account' => $ca['name'], 'amount' => $in];
            $total_in  += $in;
        }
        if ($out > 0) {
            $outflows[] = ['account' => $ca['name'], 'amount' => $out];
            $total_out += $out;
        }
    }

    return [
        'inflows'    => $inflows,
        'outflows'   => $outflows,
        'total_in'   => $total_in,
        'total_out'  => $total_out,
        'net_flow'   => $total_in - $total_out,
    ];
}

// ── Fiscal year helpers ───────────────────────────────────────────────────────

function acc_fiscal_year_start(): string
{
    $md   = acc_setting('fiscal_year_start', '07-01');
    $year = date('Y');
    $date = date('Y-m-d', strtotime($year . '-' . $md));
    if ($date > date('Y-m-d')) {
        $date = date('Y-m-d', strtotime(($year - 1) . '-' . $md));
    }
    return $date;
}

function acc_fiscal_year_end(): string
{
    $start = acc_fiscal_year_start();
    return date('Y-m-d', strtotime($start . ' +1 year -1 day'));
}

// ── Student Fee Payment Helpers ───────────────────────────────────────────────

/**
 * Fetch a student record by their alphanumeric student_id (e.g. "20210101001").
 * Returns the students row joined with their fee package (if any),
 * or null if the student does not exist.
 */
function acc_get_student_by_sid(string $student_sid): ?array
{
    $stmt = db()->prepare(
        'SELECT s.id, s.student_id, s.full_name, s.dept_id, s.status,
                p.id AS package_id
         FROM students s
         LEFT JOIN sfp_packages p ON p.student_id = s.id
         WHERE s.student_id = ?
         LIMIT 1'
    );
    $stmt->execute([trim($student_sid)]);
    return $stmt->fetch() ?: null;
}

/**
 * Build a full fee-obligation summary for a student's fee package.
 *
 * Returns an array with keys:
 *   package       – sfp_packages row
 *   cf_settings   – cf_settings row (reg_fee_per_semester, form_id_fee)
 *   semesters     – array of semester rows enriched with paid/outstanding
 *   totals        – grand-total obligation, paid, outstanding per fee type
 *
 * Outstanding = obligation − paid (floor at 0).
 */
function acc_student_fee_summary(int $student_id): ?array
{
    $db = db();

    // Load package
    $pkg_stmt = $db->prepare(
        'SELECT p.*, s.full_name AS student_name, s.student_id AS student_sid, s.admitted_semester,
                cp.bi_semester_start_month, cp.tri_semester_start_month
         FROM sfp_packages p
         JOIN students s ON s.id = p.student_id
         LEFT JOIN cf_programs cp ON cp.id = p.cf_program_id
         WHERE p.student_id = ?'
    );
    $pkg_stmt->execute([$student_id]);
    $pkg = $pkg_stmt->fetch();
    if (!$pkg) return null;

    $package_id  = (int)$pkg['id'];
    $start_month = acc_package_start_month($pkg);

    // Use snapshotted registration and form fees from the package (not global cf_settings)
    // This ensures each student retains their originally assigned fees
    $reg_fee     = (float)($pkg['reg_fee_per_semester'] ?? 0.0);
    $form_id_fee = (float)($pkg['form_id_fee'] ?? 0.0);

    // Semester fee rows
    $sf_stmt = $db->prepare(
        'SELECT * FROM sfp_semester_fees WHERE package_id = ? ORDER BY semester_number ASC'
    );
    $sf_stmt->execute([$package_id]);
    $semester_fees = $sf_stmt->fetchAll();
    $num_semesters = count($semester_fees);
    $start_year = acc_package_start_year($pkg, $semester_fees);

    // Paid amounts per fee_type and per semester_fee_id
    $paid_stmt = $db->prepare(
        'SELECT fee_type, COALESCE(semester_fee_id, 0) AS sfid, COALESCE(SUM(amount),0) AS paid
         FROM sfp_payments
         WHERE package_id = ?
         GROUP BY fee_type, semester_fee_id'
    );
    $paid_stmt->execute([$package_id]);
    $paid_rows = $paid_stmt->fetchAll();

    // Build lookup: [fee_type][coalesced_semester_fee_id] => paid_amount
    // Key 0 represents payments with NULL semester_fee_id (package-level / legacy payments)
    $paid_map = [];
    foreach ($paid_rows as $row) {
        $paid_map[$row['fee_type']][(int)$row['sfid']] = (float)$row['paid'];
    }

    // Helper: total paid for a fee_type (all semester_fee_ids combined)
    $total_paid_for = function (string $type) use ($paid_map): float {
        if (!isset($paid_map[$type])) return 0.0;
        return array_sum($paid_map[$type]);
    };

    // ── Obligations ────────────────────────────────────────────────────────────

    // Admission (one-time)
    $admission_due  = (float)$pkg['admission_fees'];
    $admission_paid = $total_paid_for('admission');

    // Registration totals (per-semester distribution handled in the loop below)
    $reg_due  = $reg_fee * $num_semesters;
    $reg_paid = $total_paid_for('registration');

    // Per-semester tuition + monthly breakdown
    $months     = (float)($pkg['total_months'] ?? 0);
    $mps        = (float)($pkg['months_per_semester'] ?? 0);
    $months_int = max(1, (int)round($mps)); // months per semester as integer

    // Distribute total registration paid sequentially across semesters
    $reg_credit_remaining = $reg_paid;

    // Legacy fixed/english payments (sfid=0, no semester link) → distribute evenly
    $legacy_fixed_english = (float)($paid_map['fixed_fee'][0]   ?? 0)
                          + (float)($paid_map['english_fee'][0] ?? 0);
    $legacy_credit_per_sem = $num_semesters > 0
        ? round($legacy_fixed_english / $num_semesters, 2) : 0.0;

    $semesters_enriched = [];
    foreach ($semester_fees as $sf) {
        $sf_id = (int)$sf['id'];

        // Per-semester registration (sequential distribution)
        $reg_paid_sem = min($reg_fee, max(0.0, $reg_credit_remaining));
        $reg_credit_remaining -= $reg_paid_sem;
        $reg_out_sem  = max(0.0, $reg_fee - $reg_paid_sem);

        // Per-semester portions of fixed and English fees
        $fixed_per_sem   = ($months > 0 && $mps > 0)
            ? round((float)$pkg['fixed_institutional_fees'] / $months * $mps, 2) : 0.0;
        $english_per_sem = ($months > 0 && $mps > 0)
            ? round((float)$pkg['english_course_fee']        / $months * $mps, 2) : 0.0;

        // Apply any per-semester fixed/English discounts stored in sfp_semester_fees
        $fixed_per_sem   = max(0.0, $fixed_per_sem   - (float)($sf['fixed_discount_amount']   ?? 0));
        $english_per_sem = max(0.0, $english_per_sem - (float)($sf['english_discount_amount'] ?? 0));

        // Total semester "overall" amount = tuition + fixed portion + English portion
        $tuition_payable_sem = (float)$sf['tuition_payable'];
        $sem_total_due       = $tuition_payable_sem + $fixed_per_sem + $english_per_sem;

        // Monthly fee (distribute evenly; last month absorbs any rounding remainder)
        $monthly_fee = $months_int > 1
            ? round($sem_total_due / $months_int, 2)
            : $sem_total_due;

        // Total paid for this semester: semester_tuition + any per-sem fixed/english + legacy share
        $tuition_paid_sem = (float)($paid_map['semester_tuition'][$sf_id] ?? 0)
                          + (float)($paid_map['fixed_fee'][$sf_id]        ?? 0)
                          + (float)($paid_map['english_fee'][$sf_id]      ?? 0)
                          + $legacy_credit_per_sem;

        // Build per-month rows by sequential credit distribution
        $monthly_rows = [];
        $month_credit = $tuition_paid_sem;
        for ($m = 1; $m <= $months_int; $m++) {
            // Last month absorbs any rounding remainder so totals balance exactly
            $m_due  = ($m < $months_int)
                ? $monthly_fee
                : max(0.0, $sem_total_due - $monthly_fee * ($months_int - 1));
            $m_paid = min($m_due, max(0.0, $month_credit));
            $month_credit -= $m_paid;
            $month_offset = ((int)$sf['semester_number'] - 1) * $months_int + ($m - 1);
            $month_info = acc_month_year_for_slot($start_month, $start_year, $month_offset);
            $monthly_rows[] = [
                'month_number' => $m,
                'month_label'  => $month_info['label'],
                'due'          => round($m_due, 2),
                'paid'         => round($m_paid, 2),
                'out'          => round(max(0.0, $m_due - $m_paid), 2),
            ];
        }

        $semesters_enriched[] = array_merge($sf, [
            'tuition_due'     => round($sem_total_due, 2),
            'tuition_paid'    => round($tuition_paid_sem, 2),
            'tuition_out'     => round(max(0.0, $sem_total_due - $tuition_paid_sem), 2),
            'fixed_per_sem'   => $fixed_per_sem,
            'english_per_sem' => $english_per_sem,
            'reg_fee'         => $reg_fee,
            'reg_paid'        => round($reg_paid_sem, 2),
            'reg_out'         => round($reg_out_sem, 2),
            'monthly_fee'     => $monthly_fee,
            'monthly_rows'    => $monthly_rows,
            'months_per_sem'  => $months_int,
        ]);
    }

    $total_tuition_due  = array_sum(array_column($semesters_enriched, 'tuition_due'));
    $total_tuition_paid = $total_paid_for('semester_tuition')
                        + $total_paid_for('fixed_fee')
                        + $total_paid_for('english_fee');

    return [
        'package'     => $pkg,
        'cf_settings' => ['reg_fee_per_semester' => $reg_fee, 'form_id_fee' => $form_id_fee],
        'semesters'   => $semesters_enriched,
        'totals'      => [
            'admission'    => ['due' => $admission_due,     'paid' => $admission_paid,     'out' => max(0.0, $admission_due - $admission_paid)],
            'registration' => ['due' => $reg_due,           'paid' => $reg_paid,           'out' => max(0.0, $reg_due - $reg_paid)],
            'tuition'      => ['due' => $total_tuition_due, 'paid' => $total_tuition_paid, 'out' => max(0.0, $total_tuition_due - $total_tuition_paid)],
            'fixed'        => ['due' => 0, 'paid' => 0, 'out' => 0], // included in monthly fee
            'english'      => ['due' => 0, 'paid' => 0, 'out' => 0], // included in monthly fee
        ],
    ];
}

/**
 * Collect a student fee payment.
 *
 * Posts a receipt voucher (debit cash/bank, credit income account) and
 * records a row in sfp_payments for payment-history tracking.
 *
 * @param  int    $student_id        students.id (PK)
 * @param  int    $package_id        sfp_packages.id
 * @param  string $fee_type          One of the sfp_payments.fee_type ENUM values
 * @param  int|null $semester_fee_id sfp_semester_fees.id (for semester_tuition / fixed_fee / english_fee)
 * @param  int|null $semester_number Semester number (mirrors semester_fee_id's semester_number)
 * @param  int|null $month_number    Month number (for monthly installment tracking)
 * @param  string   $payment_method  cash|bank|mobile_banking
 * @param  string|null $mobile_banking_provider bkash|nagad|rocket when payment_method=mobile_banking
 * @param  string|null $transaction_number Required for non-cash methods
 * @param  float  $amount            Amount received
 * @param  int    $cash_account_id   acc_accounts.id  (debit – cash or bank)
 * @param  int    $income_account_id acc_accounts.id  (credit – income type)
 * @param  string $date              Y-m-d
 * @param  string $reference         Free-text reference
 * @param  string $narration         Voucher narration
 *
 * @return int  New acc_vouchers.id
 * @throws RuntimeException on over-payment or accounting failure
 */
function acc_collect_student_fee(
    int    $student_id,
    int    $package_id,
    string $fee_type,
    ?int   $semester_fee_id,
    ?int   $semester_number,
    ?int   $month_number,
    string $payment_method,
    ?string $mobile_banking_provider,
    ?string $transaction_number,
    float  $amount,
    int    $cash_account_id,
    int    $income_account_id,
    string $date,
    string $reference  = '',
    string $narration  = ''
): int {
    if ($amount <= 0) {
        throw new RuntimeException('Payment amount must be greater than zero.');
    }

    $db = db();
    [$payment_method, $mobile_banking_provider, $transaction_number] = acc_normalize_payment_method_fields(
        $payment_method,
        $mobile_banking_provider,
        $transaction_number
    );

    // Post the receipt voucher
    $voucher_id = acc_post_voucher('receipt', $date, [
        ['account_id' => $cash_account_id,   'debit' => $amount, 'credit' => 0,       'description' => $narration],
        ['account_id' => $income_account_id, 'debit' => 0,       'credit' => $amount, 'description' => $narration],
    ], $narration, $reference);

    // Record the payment in sfp_payments
    $user = auth_user();
    $db->prepare(
        'INSERT INTO sfp_payments
            (student_id, package_id, semester_fee_id, fee_type, semester_number, month_number, payment_method, mobile_banking_provider, transaction_number, amount, voucher_id, note, collected_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $student_id,
        $package_id,
        $semester_fee_id,
        $fee_type,
        $semester_number,
        $month_number,
        $payment_method,
        $mobile_banking_provider,
        $transaction_number,
        round($amount, 2),
        $voucher_id,
        $narration ?: null,
        $user['id'] ?? null,
    ]);

    return $voucher_id;
}

/**
 * Fetch payment history for a student's package (most recent first).
 */
function acc_get_student_payments(int $package_id): array
{
    $stmt = db()->prepare(
        'SELECT sp.*,
                v.voucher_number, v.voucher_date, v.status AS voucher_status,
                u.full_name AS collected_by_name
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         LEFT JOIN users u   ON u.id = sp.collected_by
         WHERE sp.package_id = ?
         ORDER BY sp.collected_at DESC, sp.id DESC'
    );
    $stmt->execute([$package_id]);
    return $stmt->fetchAll();
}

/**
 * Look up an income account by its COA code.
 * Returns the account id or 0 if not found.
 */
function acc_income_account_id_by_code(string $code): int
{
    $stmt = db()->prepare(
        "SELECT id FROM acc_accounts WHERE code = ? AND type = 'income' AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([$code]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * Valid student fee types handled by accounting.
 *
 * @return string[]
 */
function acc_student_fee_types(): array
{
    return ['admission', 'registration', 'semester_tuition', 'fixed_fee', 'english_fee', 'other'];
}

/**
 * Default COA income code for each fee type.
 *
 * @return string COA account code (e.g. 4100)
 */
function acc_default_income_code_for_fee_type(string $fee_type): string
{
    return match ($fee_type) {
        'admission'        => '4200', // Admission Fees
        'registration'     => '4100', // Tuition Fees (reg)
        'semester_tuition' => '4100', // Tuition Fees
        'fixed_fee'        => '4100', // Tuition Fees
        'english_fee'      => '4100', // Tuition Fees
        'other'            => '4700', // Miscellaneous Income
        default            => '4700',
    };
}

/**
 * Read mapped income-account code for a fee type from settings.
 * Falls back to the default mapped code if setting is missing.
 */
function acc_income_account_code_for_fee_type(string $fee_type): string
{
    $default_code = acc_default_income_code_for_fee_type($fee_type);
    $setting_key  = 'income_account_' . $fee_type;
    $code         = trim(acc_setting($setting_key, $default_code));
    return $code !== '' ? $code : $default_code;
}

/**
 * Read mapped income-account ID for a fee type from settings.
 * Falls back to the default mapped account when needed.
 */
function acc_income_account_id_for_fee_type(string $fee_type): int
{
    static $cache = [];
    if (isset($cache[$fee_type])) {
        return $cache[$fee_type];
    }

    $code = acc_income_account_code_for_fee_type($fee_type);
    $id = acc_income_account_id_by_code($code);
    if ($id > 0) {
        return $cache[$fee_type] = $id;
    }

    $fallback_id = acc_income_account_id_by_code(acc_default_income_code_for_fee_type($fee_type));
    if ($fallback_id > 0) {
        return $cache[$fee_type] = $fallback_id;
    }

    $any_income = db()->query("SELECT id FROM acc_accounts WHERE type = 'income' AND is_active = 1 ORDER BY code ASC LIMIT 1")->fetchColumn();
    return $cache[$fee_type] = (int)($any_income ?: 0);
}

/**
 * Build fee-type => income account id map.
 *
 * @param string[]|null $fee_types
 * @return array<string,int>
 */
function acc_income_account_map_for_fee_types(?array $fee_types = null): array
{
    $map = [];
    $fee_types = $fee_types ?: acc_student_fee_types();
    foreach ($fee_types as $type) {
        $map[$type] = acc_income_account_id_for_fee_type($type);
    }
    return $map;
}

// ── SMS & Email notification helpers ─────────────────────────────────────────

/**
 * Send a fee-payment SMS via FastSMS BD.
 * Reads sms_enabled / sms_api_key / sms_sender_id / sms_template from acc_settings.
 */
function acc_send_fee_sms(string $mobile, array $vars): bool
{
    if (acc_setting('sms_enabled', '0') !== '1') {
        return false;
    }
    $api_key   = acc_setting('sms_api_key', '');
    $sender_id = acc_setting('sms_sender_id', '');
    if ($api_key === '' || $sender_id === '' || $mobile === '') {
        return false;
    }

    $template = acc_setting('sms_template', 'Dear {{student_name}}, your payment of {{currency}}{{amount}} has been received. Voucher: {{voucher_number}}. Thank you.');

    // Replace {{placeholders}}
    $search  = [];
    $replace = [];
    foreach ($vars as $key => $val) {
        $search[]  = '{{' . $key . '}}';
        $replace[] = (string)$val;
    }
    $message = str_replace($search, $replace, $template);

    // Normalize to 880… format
    $mobile = preg_replace('/\D/', '', $mobile);
    if (str_starts_with($mobile, '0')) {
        $mobile = '880' . substr($mobile, 1);
    }

    $url = 'https://smsapi.fastsmsbd.com/smsapiv3?' . http_build_query([
        'apikey'  => $api_key,
        'sender'  => $sender_id,
        'msisdn'  => $mobile,
        'smstext' => $message,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    curl_close($ch);

    return ($errno === 0 && $response !== false);
}

/**
 * Generate student-copy invoice HTML (suitable for Dompdf).
 *
 * @param array $student       Row from students (full_name, student_id, dept_name, program_name, phone, email)
 * @param array $voucher       Row from acc_vouchers (voucher_number, voucher_date, total_amount, narration, created_by_name)
 * @param array $invoice_items Array of fee rows. Each: ['fee_type_label','semester_label','month_label','amount','narration']
 * @return string              HTML string
 */
function acc_render_invoice_html(array $student, array $voucher, array $invoice_items): string
{
    $currency    = acc_currency();
    $logo_uri    = acc_logo_data_uri();
    $logo_html   = $logo_uri
        ? '<img src="' . $logo_uri . '" style="height:44px;width:44px;border-radius:50%;object-fit:contain;background:#fff;padding:3px;">'
        : '';
    $address     = htmlspecialchars(acc_university_address(), ENT_QUOTES, 'UTF-8');
    $website     = htmlspecialchars(acc_university_website(), ENT_QUOTES, 'UTF-8');
    $voucher_no  = htmlspecialchars($voucher['voucher_number'] ?? '—', ENT_QUOTES, 'UTF-8');
    $voucher_dt  = htmlspecialchars(date('d F Y', strtotime($voucher['voucher_date'] ?? 'now')), ENT_QUOTES, 'UTF-8');
    $collected   = htmlspecialchars($voucher['created_by_name'] ?? '—', ENT_QUOTES, 'UTF-8');
    $narration   = htmlspecialchars($voucher['narration'] ?? '', ENT_QUOTES, 'UTF-8');
    $s_name      = htmlspecialchars($student['full_name']   ?? '—', ENT_QUOTES, 'UTF-8');
    $s_id        = htmlspecialchars($student['student_id']  ?? '', ENT_QUOTES, 'UTF-8');
    $s_dept      = htmlspecialchars($student['dept_name']   ?? '', ENT_QUOTES, 'UTF-8');
    $s_prog      = htmlspecialchars($student['program_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $s_phone     = htmlspecialchars($student['phone']  ?? '', ENT_QUOTES, 'UTF-8');
    $s_email     = htmlspecialchars($student['email']  ?? '', ENT_QUOTES, 'UTF-8');

    $rows_html = '';
    $grand_total = 0.0;
    $i = 1;
    foreach ($invoice_items as $it) {
        $desc   = htmlspecialchars($it['fee_type_label'] ?? '', ENT_QUOTES, 'UTF-8');
        $sem    = htmlspecialchars($it['semester_label'] ?? '', ENT_QUOTES, 'UTF-8');
        $mon    = htmlspecialchars($it['month_label']    ?? '', ENT_QUOTES, 'UTF-8');
        $amt    = (float)($it['amount'] ?? 0);
        $note   = htmlspecialchars($it['narration'] ?? '', ENT_QUOTES, 'UTF-8');
        $desc_cell = $desc;
        if ($sem)  { $desc_cell .= ' <span style="font-size:8pt;color:#555;">(' . $sem . ($mon ? ', ' . $mon : '') . ')</span>'; }
        elseif ($mon) { $desc_cell .= ' <span style="font-size:8pt;color:#555;">(' . $mon . ')</span>'; }
        if ($note) { $desc_cell .= '<br><span style="font-size:7.5pt;color:#888;">' . $note . '</span>'; }
        $grand_total += $amt;
        $rows_html .= '<tr style="border-bottom:1px solid #e9ecef;">'
            . '<td style="padding:5px 8px;font-size:9pt;">' . $i . '</td>'
            . '<td style="padding:5px 8px;font-size:9pt;">' . $desc_cell . '</td>'
            . '<td style="padding:5px 8px;font-size:9pt;text-align:right;">' . htmlspecialchars($currency . ' ' . number_format($amt, 2), ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
        $i++;
    }

    $total_html = htmlspecialchars($currency . ' ' . number_format($grand_total, 2), ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<title>Fee Receipt - Student Copy</title></head>'
        . '<body style="font-family:Arial,Helvetica,sans-serif;font-size:10pt;color:#222;margin:0;padding:0;">'
        . '<div style="max-width:700px;margin:0 auto;">'
        // Header
        . '<table style="width:100%;border-collapse:collapse;background:#1a3c5e;color:#fff;padding:12px 20px;" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="padding:12px 16px;vertical-align:middle;width:56px;">' . $logo_html . '</td>'
        . '<td style="padding:12px 8px;vertical-align:middle;">'
        . '<div style="font-size:14pt;font-weight:700;color:#fff;">Prime University</div>'
        . '<div style="font-size:8pt;color:rgba(255,255,255,.8);margin-top:2px;">' . $address . '<br>' . $website . '</div>'
        . '</td>'
        . '<td style="padding:12px 16px;text-align:right;vertical-align:middle;white-space:nowrap;">'
        . '<span style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:3px 12px;font-size:8.5pt;font-weight:700;letter-spacing:.5px;text-transform:uppercase;">Student Copy</span>'
        . '</td></tr></table>'
        // Title ribbon
        . '<div style="text-align:center;background:#f0f4f8;border-bottom:2px solid #1a3c5e;padding:6px;font-size:11pt;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#1a3c5e;">Fee Collection Receipt</div>'
        // Meta row
        . '<table style="width:100%;border-collapse:collapse;margin-top:10px;" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="width:50%;padding:0 8px 0 0;vertical-align:top;">'
        . '<table style="width:100%;border:1px solid #dee2e6;border-radius:4px;border-collapse:collapse;background:#f8fafc;" cellpadding="6"><tr><td style="font-size:8.5pt;color:#6b7280;width:110px;">Receipt No.</td><td style="font-size:9pt;font-weight:600;">' . $voucher_no . '</td></tr>'
        . '<tr><td style="font-size:8.5pt;color:#6b7280;">Date</td><td style="font-size:9pt;font-weight:600;">' . $voucher_dt . '</td></tr></table>'
        . '</td>'
        . '<td style="width:50%;padding:0 0 0 8px;vertical-align:top;">'
        . '<table style="width:100%;border:1px solid #dee2e6;border-radius:4px;border-collapse:collapse;background:#f8fafc;" cellpadding="6"><tr><td style="font-size:8.5pt;color:#6b7280;width:110px;">Collected By</td><td style="font-size:9pt;font-weight:600;">' . $collected . '</td></tr></table>'
        . '</td></tr></table>'
        // Payer box
        . '<div style="border:1px solid #1a3c5e;border-radius:4px;padding:8px 12px;margin:8px 0;background:#f0f6ff;">'
        . '<div style="font-size:12pt;font-weight:700;color:#1a3c5e;">' . $s_name . '</div>'
        . '<div style="font-size:9pt;color:#555;margin-top:2px;">'
        . ($s_id    ? 'Student ID: <strong>' . $s_id . '</strong>' : '')
        . ($s_dept  ? ' &nbsp;|&nbsp; Dept: <strong>' . $s_dept . '</strong>' : '')
        . ($s_prog  ? '<br>Program: <strong>' . $s_prog . '</strong>' : '')
        . ($s_phone ? ' &nbsp;|&nbsp; Mobile: ' . $s_phone : '')
        . '</div>'
        . '</div>'
        // Fee table
        . '<table style="width:100%;border-collapse:collapse;font-size:10pt;" cellpadding="0" cellspacing="0">'
        . '<thead><tr style="background:#1a3c5e;color:#fff;">'
        . '<th style="padding:5px 8px;font-size:9pt;text-align:left;width:30px;">#</th>'
        . '<th style="padding:5px 8px;font-size:9pt;text-align:left;">Fee Description</th>'
        . '<th style="padding:5px 8px;font-size:9pt;text-align:right;">Amount (' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ')</th>'
        . '</tr></thead><tbody>'
        . $rows_html
        . '</tbody><tfoot><tr style="background:#f8fafc;border-top:2px solid #1a3c5e;">'
        . '<td colspan="2" style="padding:6px 8px;font-size:10pt;font-weight:700;">Total Amount Received</td>'
        . '<td style="padding:6px 8px;font-size:12pt;font-weight:700;color:#1a6e3c;text-align:right;">' . $total_html . '</td>'
        . '</tr></tfoot></table>'
        . ($narration ? '<div style="font-size:8.5pt;color:#555;margin-top:6px;padding:4px 8px;border-left:3px solid #1a3c5e;">Note: ' . $narration . '</div>' : '')
        // Footer
        . '<div style="text-align:center;font-size:8pt;color:#888;margin-top:14px;padding-top:8px;border-top:1px solid #e9ecef;">'
        . 'This is a computer-generated receipt. Please retain it for your records. &nbsp;|&nbsp; Prime University, ' . $address
        . '</div>'
        . '</div></body></html>';
}

/**
 * Send a fee payment invoice email with a formal email body and PDF student-copy attachment.
 *
 * @param array $student       Row from students table (full_name, email, student_id, dept_name etc.)
 * @param array $payment_info  Primary payment details (voucher_id, voucher_number, payment_date, fee_type_label, semester_label, amount, reference, narration)
 * @param array $all_items     All fee line items (for multi-payment). Defaults to wrapping $payment_info as single item.
 *                             Each item: ['fee_type_label','semester_label','month_label','amount','narration']
 */
function acc_send_fee_invoice_email(array $student, array $payment_info, array $all_items = []): bool
{
    if (acc_setting('email_invoice', '1') !== '1') {
        return false;
    }
    if (empty($student['email'])) {
        return false;
    }

    if (empty($all_items)) {
        $all_items = [[
            'fee_type_label' => $payment_info['fee_type_label'] ?? '',
            'semester_label' => $payment_info['semester_label'] ?? '',
            'month_label'    => '',
            'amount'         => $payment_info['amount'] ?? 0,
            'narration'      => $payment_info['narration'] ?? '',
        ]];
    }

    $currency    = acc_currency();
    $student_name = $student['full_name'] ?? '';
    $voucher_no  = $payment_info['voucher_number'] ?? '—';
    $pay_date    = date('d M Y', strtotime($payment_info['payment_date'] ?? 'now'));
    $total_amt   = 0.0;
    foreach ($all_items as $it) {
        $total_amt += (float)($it['amount'] ?? 0);
    }
    $formatted_total = $currency . ' ' . number_format($total_amt, 2);
    $fee_lbl = count($all_items) > 1 ? 'Multiple Fee Payment' : ($payment_info['fee_type_label'] ?? 'Fee Payment');

    // ── Formal email body ─────────────────────────────────────────────────────
    $items_table_rows = '';
    foreach ($all_items as $it) {
        $desc = htmlspecialchars($it['fee_type_label'] ?? '', ENT_QUOTES, 'UTF-8');
        if (!empty($it['semester_label'])) {
            $desc .= ' (' . htmlspecialchars($it['semester_label'], ENT_QUOTES, 'UTF-8');
            if (!empty($it['month_label'])) {
                $desc .= ', ' . htmlspecialchars($it['month_label'], ENT_QUOTES, 'UTF-8');
            }
            $desc .= ')';
        } elseif (!empty($it['month_label'])) {
            $desc .= ' (' . htmlspecialchars($it['month_label'], ENT_QUOTES, 'UTF-8') . ')';
        }
        $items_table_rows .= '<tr>'
            . '<td style="padding:6px 12px;border-bottom:1px solid #e9ecef;">' . $desc . '</td>'
            . '<td style="padding:6px 12px;border-bottom:1px solid #e9ecef;text-align:right;white-space:nowrap;">'
            . htmlspecialchars($currency . ' ' . number_format((float)($it['amount'] ?? 0), 2), ENT_QUOTES, 'UTF-8')
            . '</td>'
            . '</tr>';
    }

    $address = htmlspecialchars(acc_university_address(), ENT_QUOTES, 'UTF-8');
    $website = htmlspecialchars(acc_university_website(), ENT_QUOTES, 'UTF-8');
    $logo_url = acc_university_logo_url();

    $body_html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;background:#f4f4f4;margin:0;padding:20px;">'
        . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">'
        // Email header
        . '<div style="background:#1a3c5e;padding:20px 28px;display:flex;align-items:center;">'
        . '<img src="' . htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') . '" alt="Prime University" style="height:40px;width:40px;border-radius:50%;object-fit:contain;background:#fff;padding:2px;margin-right:12px;">'
        . '<div style="color:#fff;">'
        . '<div style="font-size:17px;font-weight:700;">Prime University</div>'
        . '<div style="font-size:11px;opacity:.8;margin-top:2px;">Accounts Section &nbsp;|&nbsp; Fee Payment Confirmation</div>'
        . '</div></div>'
        // Body
        . '<div style="padding:28px 32px;">'
        . '<p style="margin:0 0 16px;">Dear <strong>' . htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p style="margin:0 0 16px;">We are pleased to confirm that your fee payment has been received and processed successfully. Please find the details below:</p>'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px;border:1px solid #e2e8f0;border-radius:6px;">'
        . '<tr style="background:#f0f4f8;"><td style="padding:8px 12px;font-weight:600;width:45%;">Receipt No.</td><td style="padding:8px 12px;">' . htmlspecialchars($voucher_no, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:8px 12px;font-weight:600;border-top:1px solid #e9ecef;">Payment Date</td><td style="padding:8px 12px;border-top:1px solid #e9ecef;">' . $pay_date . '</td></tr>'
        . '<tr style="background:#f0f4f8;"><td style="padding:8px 12px;font-weight:600;border-top:1px solid #e9ecef;">Student ID</td><td style="padding:8px 12px;border-top:1px solid #e9ecef;">' . htmlspecialchars($student['student_id'] ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td style="padding:8px 12px;font-weight:600;border-top:1px solid #e9ecef;">Fee Type</td><td style="padding:8px 12px;border-top:1px solid #e9ecef;">' . htmlspecialchars($fee_lbl, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px;border:1px solid #e2e8f0;">'
        . '<thead><tr style="background:#1a3c5e;color:#fff;"><th style="padding:7px 12px;text-align:left;font-weight:600;">Fee Description</th><th style="padding:7px 12px;text-align:right;font-weight:600;">Amount</th></tr></thead>'
        . '<tbody>' . $items_table_rows . '</tbody>'
        . '<tfoot><tr style="background:#f0f4f8;font-weight:700;"><td style="padding:8px 12px;border-top:2px solid #1a3c5e;">Total Amount Received</td><td style="padding:8px 12px;border-top:2px solid #1a3c5e;text-align:right;color:#1a6e3c;">' . htmlspecialchars($formatted_total, ENT_QUOTES, 'UTF-8') . '</td></tr></tfoot>'
        . '</table>'
        . '<p style="margin:0 0 16px;">Your official fee receipt (Student Copy) is attached to this email as a PDF. Please retain it for your records.</p>'
        . '<p style="margin:0 0 16px;">If you have any queries regarding this payment, please contact the Accounts Section at the university.</p>'
        . '<p style="margin:0;">Yours sincerely,<br><strong>Accounts Section</strong><br>Prime University<br>'
        . $address . '<br><a href="' . $website . '" style="color:#1a3c5e;">' . $website . '</a></p>'
        . '</div>'
        // Footer
        . '<div style="background:#f8fafc;border-top:1px solid #e9ecef;padding:12px 28px;font-size:11px;color:#888;text-align:center;">'
        . 'This is an automated email from the Prime University fee management system. Please do not reply to this email.'
        . '</div>'
        . '</div></body></html>';

    // ── Generate PDF student copy ─────────────────────────────────────────────
    $pdf_data = '';
    try {
        $vendor_autoload = dirname(dirname(__DIR__)) . '/vendor/autoload.php';
        if (is_file($vendor_autoload)) {
            require_once $vendor_autoload;
            // Build voucher stub for the PDF renderer
            $vd_stmt = db()->prepare(
                'SELECT voucher_number, voucher_date, narration, created_by_name FROM acc_vouchers WHERE id = ? LIMIT 1'
            );
            $vd_stmt->execute([(int)($payment_info['voucher_id'] ?? 0)]);
            $vd_row = $vd_stmt->fetch() ?: [];
            if (!$vd_row && !empty($payment_info['voucher_number'])) {
                $vd_row = [
                    'voucher_number'   => $payment_info['voucher_number'],
                    'voucher_date'     => $payment_info['payment_date'] ?? date('Y-m-d'),
                    'narration'        => $payment_info['narration'] ?? '',
                    'created_by_name'  => '',
                ];
            }
            $invoice_html = acc_render_invoice_html($student, $vd_row, $all_items);
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $dompdf->loadHtml($invoice_html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdf_data = $dompdf->output();
        }
    } catch (\Throwable $e) {
        // PDF generation failed; send email without attachment
        error_log('acc_send_fee_invoice_email: PDF generation failed – ' . $e->getMessage());
        $pdf_data = '';
    }

    // ── Build and send the email ──────────────────────────────────────────────
    $from_name  = APP_NAME;
    $from_email = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $encoded_from = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
    $subject = 'Fee Payment Confirmation – Receipt ' . $voucher_no . ' – ' . $student_name;
    $sid_slug = preg_replace('/[^A-Za-z0-9_\-]/', '', $student['student_id'] ?? 'student');

    if ($pdf_data !== '') {
        $boundary = '----=_Part_' . md5(uniqid('', true));
        $attach_name = 'fee-receipt-' . $sid_slug . '-' . preg_replace('/[^A-Za-z0-9_\-]/', '', $voucher_no) . '.pdf';

        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
        $headers .= 'From: ' . $encoded_from . ' <' . $from_email . '>' . "\r\n";
        $headers .= 'Reply-To: ' . $from_email . "\r\n";
        $headers .= 'X-Mailer: PHP/' . PHP_VERSION;

        $message  = '--' . $boundary . "\r\n";
        $message .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $message .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
        $message .= quoted_printable_encode($body_html) . "\r\n";

        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: application/pdf; name="' . $attach_name . '"' . "\r\n";
        $message .= 'Content-Transfer-Encoding: base64' . "\r\n";
        $message .= 'Content-Disposition: attachment; filename="' . $attach_name . '"' . "\r\n\r\n";
        $message .= chunk_split(base64_encode($pdf_data)) . "\r\n";
        $message .= '--' . $boundary . '--';

        return mail($student['email'], $subject, $message, $headers, '-f' . $from_email);
    }

    // Fallback: send without attachment (plain HTML email)
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . $encoded_from . ' <' . $from_email . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $from_email . "\r\n";
    $headers .= 'X-Mailer: PHP/' . PHP_VERSION;

    return mail($student['email'], $subject, $body_html, $headers, '-f' . $from_email);
}


// ── Admission Applicant Helpers ───────────────────────────────────────────────

/**
 * Find an admission applicant by their application / form number.
 * Returns a row from admissions_applications (joined with dept + program),
 * or null if not found.
 */
function acc_get_applicant_by_appnumber(string $app_number): ?array
{
    $stmt = db()->prepare(
        'SELECT a.id, a.app_number, a.student_name, a.present_contact, a.present_email,
                a.dept_id, a.program_id, a.status, a.office_student_id,
                d.name AS dept_name, p.program_name
         FROM admissions_applications a
         LEFT JOIN dept_departments d        ON d.id = a.dept_id
         LEFT JOIN dept_academic_programs p  ON p.id = a.program_id
         WHERE a.app_number = ?
         LIMIT 1'
    );
    $stmt->execute([trim($app_number)]);
    return $stmt->fetch() ?: null;
}

/**
 * Total admission fee already collected for a given application.
 */
function acc_get_applicant_admission_paid(int $app_id): float
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM adm_admission_fee_payments WHERE application_id = ?'
    );
    $stmt->execute([$app_id]);
    return (float)$stmt->fetchColumn();
}

/**
 * Collect an admission fee for a pre-enrollment applicant.
 *
 * Posts a receipt voucher (debit cash/bank, credit income account) and
 * records a row in adm_admission_fee_payments.
 * Supports payment method and transaction reference tracking.
 *
 * @return int  New acc_vouchers.id
 * @throws RuntimeException on accounting failure
 */
function acc_collect_applicant_admission_fee(
    int    $app_id,
    float  $amount,
    int    $cash_account_id,
    int    $income_account_id,
    string $payment_method,
    ?string $mobile_banking_provider,
    ?string $transaction_number,
    string $date,
    string $reference = '',
    string $narration  = ''
): int {
    $amount = round($amount, 2);
    [$payment_method, $mobile_banking_provider, $transaction_number] = acc_normalize_payment_method_fields(
        $payment_method,
        $mobile_banking_provider,
        $transaction_number
    );

    $voucher_id = acc_post_voucher('receipt', $date, [
        ['account_id' => $cash_account_id,   'debit' => $amount, 'credit' => 0,       'description' => $narration],
        ['account_id' => $income_account_id, 'debit' => 0,       'credit' => $amount, 'description' => $narration],
    ], $narration, $reference);

    $user = auth_user();
    db()->prepare(
        'INSERT INTO adm_admission_fee_payments
            (application_id, voucher_id, amount, payment_method, mobile_banking_provider, transaction_number, collected_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$app_id, $voucher_id, $amount, $payment_method, $mobile_banking_provider, $transaction_number, $user['id'] ?? null]);

    return $voucher_id;
}

// ── Admission complete helpers ────────────────────────────────────────────────

/**
 * Create a student record in the students table from an admissions_applications row.
 * Skips creation if a student with the given student_id already exists.
 * Returns the new (or existing) students.id PK.
 */
function acc_create_student_from_applicant(array $applicant, string $student_id): int
{
    $db = db();

    // Check for existing student with this student_id
    $existing = $db->prepare('SELECT id FROM students WHERE student_id = ? LIMIT 1');
    $existing->execute([$student_id]);
    $existing_id = (int)($existing->fetchColumn() ?: 0);
    if ($existing_id) {
        return $existing_id;
    }

    $user = auth_user();

    // Map applicant semester to admitted_semester (take the first value if CSV)
    $admitted_semester = '';
    if (!empty($applicant['semester'])) {
        $parts = explode(',', $applicant['semester']);
        $admitted_semester = trim($parts[0]);
    }

    $db->prepare(
        'INSERT INTO students
             (student_id, dept_id, program_id, admitted_semester,
              full_name, email, phone, sex, dob,
              status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $student_id,
        $applicant['dept_id'] ?: null,
        $applicant['program_id'] ?: null,
        $admitted_semester ?: null,
        $applicant['student_name'],
        $applicant['present_email'] ?: null,
        $applicant['present_contact'] ?: null,
        $applicant['sex'] ?? null,
        !empty($applicant['date_of_birth']) ? $applicant['date_of_birth'] : null,
        'Active',
        $user['id'] ?? null,
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Send an admission-complete SMS notification to the applicant.
 * Uses the accounting SMS settings (same gateway as fee SMS).
 */
function acc_send_admission_complete_sms(array $applicant, string $student_id, string $voucher_number): bool
{
    if (acc_setting('sms_enabled', '0') !== '1') {
        return false;
    }

    $mobile = $applicant['present_contact'] ?? '';
    if ($mobile === '') {
        return false;
    }

    $api_key   = acc_setting('sms_api_key', '');
    $sender_id = acc_setting('sms_sender_id', '');
    if ($api_key === '' || $sender_id === '') {
        return false;
    }

    $currency = acc_currency();
    $cf = db()->query('SELECT admission_fee_base, form_id_fee FROM cf_settings WHERE id = 1')->fetch();
    $total = $cf ? ((float)$cf['admission_fee_base'] + (float)$cf['form_id_fee']) : 0.0;

    $template = acc_setting(
        'sms_admission_template',
        'Dear {{student_name}}, your admission is complete. Student ID: {{student_id}}. Voucher: {{voucher_number}}. Amount paid: {{currency}}{{amount}}. Welcome to {{app_name}}!'
    );

    $vars = [
        'student_name'   => $applicant['student_name'],
        'student_id'     => $student_id,
        'voucher_number' => $voucher_number,
        'currency'       => $currency,
        'amount'         => number_format($total, 2),
        'app_name'       => APP_NAME,
    ];

    $search  = [];
    $replace = [];
    foreach ($vars as $key => $val) {
        $search[]  = '{{' . $key . '}}';
        $replace[] = (string)$val;
    }
    $message = str_replace($search, $replace, $template);

    $mobile = preg_replace('/\D/', '', $mobile);
    if (str_starts_with($mobile, '0')) {
        $mobile = '880' . substr($mobile, 1);
    }

    $url = 'https://smsapi.fastsmsbd.com/smsapiv3?' . http_build_query([
        'apikey'  => $api_key,
        'sender'  => $sender_id,
        'msisdn'  => $mobile,
        'smstext' => $message,
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    curl_close($ch);

    return ($errno === 0 && $response !== false);
}

/**
 * Send an admission-complete invoice email to the applicant.
 * Reuses the fee_payment_invoice email template, building a pseudo-student array.
 */
function acc_send_admission_complete_email(array $applicant, string $student_id, array $payment_info): bool
{
    if (acc_setting('email_invoice', '1') !== '1') {
        return false;
    }

    $email = $applicant['present_email'] ?? '';
    if ($email === '') {
        return false;
    }

    // Build a student-like array so we can reuse acc_send_fee_invoice_email()
    $pseudo_student = [
        'full_name'  => $applicant['student_name'],
        'email'      => $email,
        'student_id' => $student_id,
        'dept_name'  => $applicant['dept_name'] ?? '',
    ];

    return acc_send_fee_invoice_email($pseudo_student, $payment_info);
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Human-readable label for each sfp_payments fee_type.
 */
function acc_fee_type_label(string $fee_type): string
{
    return match ($fee_type) {
        'admission'        => 'Admission Fee',
        'registration'     => 'Registration Fee',
        'semester_tuition' => 'Semester Tuition Fee',
        'fixed_fee'        => 'Fixed Institutional Fee',
        'english_fee'      => 'English Course Fee',
        'other'            => 'Other Fee',
        default            => ucfirst(str_replace('_', ' ', $fee_type)),
    };
}

/**
 * Human-readable label for payment method display.
 */
function acc_payment_method_label(string $method, ?string $provider = null): string
{
    $method = strtolower(trim($method));
    return match ($method) {
        'bank' => 'Bank',
        'mobile_banking' => 'Mobile Banking' . ($provider ? ' (' . ucfirst(strtolower($provider)) . ')' : ''),
        default => 'Cash',
    };
}

/**
 * Normalize and validate payment method fields.
 *
 * @return array{0:string,1:?string,2:?string}
 */
function acc_normalize_payment_method_fields(string $method, ?string $provider, ?string $txn): array
{
    $method = strtolower(trim($method));
    if (!in_array($method, ['cash', 'bank', 'mobile_banking'], true)) {
        throw new RuntimeException('Invalid payment method selected.');
    }

    $provider = $provider !== null ? strtolower(trim($provider)) : null;
    $txn = $txn !== null ? trim($txn) : null;

    if ($method === 'mobile_banking') {
        if (!in_array($provider, ['bkash', 'nagad', 'rocket'], true)) {
            throw new RuntimeException('Please select a mobile banking provider.');
        }
    } else {
        $provider = null;
    }

    if ($method === 'cash') {
        $txn = null;
    } else {
        if ($txn === null || $txn === '') {
            throw new RuntimeException('Transaction number is required for non-cash payments.');
        }
    }

    return [$method, $provider, $txn];
}

/**
 * Determine package start month from snapshotted/linked program settings.
 */
function acc_package_start_month(array $pkg): int
{
    $total_semesters = (int)($pkg['total_semesters'] ?? 0);
    $is_bi = $total_semesters > 0 && $total_semesters <= 8;
    $start = $is_bi
        ? (int)($pkg['bi_semester_start_month'] ?? 0)
        : (int)($pkg['tri_semester_start_month'] ?? 0);
    return ($start >= 1 && $start <= 12) ? $start : 1;
}

/**
 * Determine package start year from semester labels/admitted semester.
 */
function acc_package_start_year(array $pkg, array $semester_fees): int
{
    $candidates = [];
    if (!empty($pkg['admitted_semester'])) {
        $candidates[] = (string)$pkg['admitted_semester'];
    }
    foreach ($semester_fees as $sf) {
        if (!empty($sf['semester_label'])) {
            $candidates[] = (string)$sf['semester_label'];
        }
    }
    foreach ($candidates as $txt) {
        if (preg_match('/\b(2\d{3})\b/', $txt, $m)) {
            return (int)$m[1];
        }
    }
    return (int)date('Y');
}

/**
 * Get month/year metadata for a month slot offset from the package start.
 *
 * @return array{month:int,year:int,label:string}
 */
function acc_month_year_for_slot(int $start_month, int $start_year, int $offset): array
{
    static $month_short_names = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];
    $serial = ($start_month - 1) + $offset;
    $month_index = (($serial % 12) + 12) % 12;
    $month = $month_index + 1;
    $year = $start_year + (int)floor(($serial - $month_index) / 12);
    return [
        'month' => $month,
        'year'  => $year,
        'label' => ($month_short_names[$month] ?? '') . ' ' . $year,
    ];
}

/**
 * Compute total outstanding balance across ALL fee types for a student's package.
 * Used by the invoice email so the student can see remaining balance.
 */
function acc_total_outstanding(int $package_id): float
{
    $db = db();

    $pkg_stmt = $db->prepare('SELECT * FROM sfp_packages WHERE id = ?');
    $pkg_stmt->execute([$package_id]);
    $pkg = $pkg_stmt->fetch();
    if (!$pkg) return 0.0;

    // Use snapshotted registration fee from the package (not global cf_settings)
    $reg_fee = (float)($pkg['reg_fee_per_semester'] ?? 0.0);

    $sem_stmt = $db->prepare('SELECT COUNT(*), COALESCE(SUM(tuition_payable),0) FROM sfp_semester_fees WHERE package_id = ?');
    $sem_stmt->execute([$package_id]);
    $sem_row = $sem_stmt->fetch(PDO::FETCH_NUM);
    $num_sems      = (int)($sem_row[0] ?? 0);
    $tuition_total = (float)($sem_row[1] ?? 0);

    $total_due = (float)$pkg['admission_fees']
               + ($reg_fee * $num_sems)
               + (float)$pkg['fixed_institutional_fees']
               + (float)$pkg['english_course_fee']
               + $tuition_total;

    $paid_stmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM sfp_payments WHERE package_id = ?');
    $paid_stmt->execute([$package_id]);
    $total_paid = (float)$paid_stmt->fetchColumn();

    return max(0.0, $total_due - $total_paid);
}
