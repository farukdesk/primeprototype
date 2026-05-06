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

    $current = (int)acc_setting($key, '1');
    $number  = $prefix . '-' . $year . '-' . str_pad((string)$current, 5, '0', STR_PAD_LEFT);

    acc_save_setting($key, (string)($current + 1));

    return $number;
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
        'SELECT p.*, s.full_name AS student_name, s.student_id AS student_sid
         FROM sfp_packages p
         JOIN students s ON s.id = p.student_id
         WHERE p.student_id = ?'
    );
    $pkg_stmt->execute([$student_id]);
    $pkg = $pkg_stmt->fetch();
    if (!$pkg) return null;

    $package_id = (int)$pkg['id'];

    // Course-fee global settings
    $cf = $db->query('SELECT reg_fee_per_semester, form_id_fee FROM cf_settings WHERE id = 1')->fetch();
    $reg_fee     = $cf ? (float)$cf['reg_fee_per_semester'] : 0.0;
    $form_id_fee = $cf ? (float)$cf['form_id_fee'] : 0.0;

    // Semester fee rows
    $sf_stmt = $db->prepare(
        'SELECT * FROM sfp_semester_fees WHERE package_id = ? ORDER BY semester_number ASC'
    );
    $sf_stmt->execute([$package_id]);
    $semester_fees = $sf_stmt->fetchAll();
    $num_semesters = count($semester_fees);

    // Paid amounts per fee_type and per semester_fee_id
    $paid_stmt = $db->prepare(
        'SELECT fee_type, semester_fee_id, COALESCE(SUM(amount),0) AS paid
         FROM sfp_payments
         WHERE package_id = ?
         GROUP BY fee_type, semester_fee_id'
    );
    $paid_stmt->execute([$package_id]);
    $paid_rows = $paid_stmt->fetchAll();

    // Build lookup: [fee_type][semester_fee_id|'total'] => paid_amount
    $paid_map = [];
    foreach ($paid_rows as $row) {
        $key = $row['semester_fee_id'] ?? 'total';
        $paid_map[$row['fee_type']][$key] = (float)$row['paid'];
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

    // Registration (per semester)
    $reg_due  = $reg_fee * $num_semesters;
    $reg_paid = $total_paid_for('registration');

    // Fixed institutional fee total (minus any discounts already baked into semester rows)
    $fixed_due  = (float)$pkg['fixed_institutional_fees'];
    $fixed_paid = $total_paid_for('fixed_fee');

    // English course fee total
    $english_due  = (float)$pkg['english_course_fee'];
    $english_paid = $total_paid_for('english_fee');

    // Per-semester tuition
    $months = (float)($pkg['total_months'] ?? 0);
    $mps    = (float)($pkg['months_per_semester'] ?? 0);

    $semesters_enriched = [];
    foreach ($semester_fees as $sf) {
        $sf_id = (int)$sf['id'];
        $tuition_due  = (float)$sf['tuition_payable'];
        $tuition_paid = (float)($paid_map['semester_tuition'][$sf_id] ?? 0);

        // Per-semester portions of fixed and English fees
        $fixed_per_sem   = ($months > 0 && $mps > 0)
            ? round((float)$pkg['fixed_institutional_fees'] / $months * $mps, 2) : 0.0;
        $english_per_sem = ($months > 0 && $mps > 0)
            ? round((float)$pkg['english_course_fee']        / $months * $mps, 2) : 0.0;

        // Apply any per-semester fixed/English discounts stored in sfp_semester_fees
        $fixed_per_sem   = max(0.0, $fixed_per_sem   - (float)($sf['fixed_discount_amount']   ?? 0));
        $english_per_sem = max(0.0, $english_per_sem - (float)($sf['english_discount_amount'] ?? 0));

        $semesters_enriched[] = array_merge($sf, [
            'tuition_due'     => $tuition_due,
            'tuition_paid'    => $tuition_paid,
            'tuition_out'     => max(0.0, $tuition_due - $tuition_paid),
            'fixed_per_sem'   => $fixed_per_sem,
            'english_per_sem' => $english_per_sem,
            'reg_fee'         => $reg_fee,
        ]);
    }

    $total_tuition_due  = array_sum(array_column($semesters_enriched, 'tuition_due'));
    $total_tuition_paid = $total_paid_for('semester_tuition');

    return [
        'package'    => $pkg,
        'cf_settings' => ['reg_fee_per_semester' => $reg_fee, 'form_id_fee' => $form_id_fee],
        'semesters'  => $semesters_enriched,
        'totals'     => [
            'admission'  => ['due' => $admission_due,       'paid' => $admission_paid,       'out' => max(0.0, $admission_due - $admission_paid)],
            'registration'=> ['due' => $reg_due,            'paid' => $reg_paid,             'out' => max(0.0, $reg_due - $reg_paid)],
            'tuition'    => ['due' => $total_tuition_due,   'paid' => $total_tuition_paid,   'out' => max(0.0, $total_tuition_due - $total_tuition_paid)],
            'fixed'      => ['due' => $fixed_due,           'paid' => $fixed_paid,           'out' => max(0.0, $fixed_due - $fixed_paid)],
            'english'    => ['due' => $english_due,         'paid' => $english_paid,         'out' => max(0.0, $english_due - $english_paid)],
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

    // Post the receipt voucher
    $voucher_id = acc_post_voucher('receipt', $date, [
        ['account_id' => $cash_account_id,   'debit' => $amount, 'credit' => 0,       'description' => $narration],
        ['account_id' => $income_account_id, 'debit' => 0,       'credit' => $amount, 'description' => $narration],
    ], $narration, $reference);

    // Record the payment in sfp_payments
    $user = auth_user();
    $db->prepare(
        'INSERT INTO sfp_payments
            (student_id, package_id, semester_fee_id, fee_type, semester_number, amount, voucher_id, note, collected_by)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $student_id,
        $package_id,
        $semester_fee_id,
        $fee_type,
        $semester_number,
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
 * Send a fee payment invoice email using the 'fee_payment_invoice' email template.
 *
 * @param array $student     Row from students table (full_name, email, student_id, dept_name etc.)
 * @param array $payment_info Associative array with payment details for the template vars
 */
function acc_send_fee_invoice_email(array $student, array $payment_info): bool
{
    if (acc_setting('email_invoice', '1') !== '1') {
        return false;
    }
    if (empty($student['email'])) {
        return false;
    }

    require_once __DIR__ . '/../includes/mailer.php';

    $currency = acc_currency();

    $narration_row = '';
    if (!empty($payment_info['narration'])) {
        $narration_row = '<p style="margin:4px 0 0;font-size:13px;color:#6b7280;">Note: ' . htmlspecialchars($payment_info['narration'], ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $vars = [
        'student_name'     => $student['full_name'],
        'student_sid'      => $student['student_id'],
        'department'       => $student['dept_name'] ?? '',
        'voucher_number'   => $payment_info['voucher_number'],
        'payment_date'     => date('d M Y', strtotime($payment_info['payment_date'])),
        'fee_type_label'   => $payment_info['fee_type_label'],
        'semester_label'   => !empty($payment_info['semester_label']) ? ' – ' . $payment_info['semester_label'] : '',
        'currency'         => $currency,
        'amount'           => number_format((float)$payment_info['amount'], 2),
        'outstanding_total'=> number_format((float)$payment_info['outstanding_total'], 2),
        'reference'        => $payment_info['reference'] ?: '—',
        'narration_row'    => $narration_row,
    ];

    return send_template_email(
        'fee_payment_invoice',
        $student['email'],
        $student['full_name'],
        $vars
    );
}

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

    $cf      = $db->query('SELECT reg_fee_per_semester FROM cf_settings WHERE id = 1')->fetch();
    $reg_fee = $cf ? (float)$cf['reg_fee_per_semester'] : 0.0;

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
