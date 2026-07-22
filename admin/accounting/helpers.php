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
require_once __DIR__ . '/../semester-drop/helpers.php';

const ACC_INVOICE_CUSTOM_LOGO_FILE = 'Prime_University_Invoice logo.png';
const ACC_STUDENT_FORM_FEE = 500.0;
const ACC_STUDENT_ID_CARD_FEE = 500.0;

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
 * Look up an active asset account by its COA code.
 *
 * Accounting Settings lets admins map the "Received Into" account to any active
 * asset account (current_asset, fixed_asset or other_asset), so the lookup must
 * accept any asset sub_type — otherwise a configured bank/mobile-banking account
 * that is not a current_asset would silently fail to resolve and fall back to a
 * default cash account.
 *
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
         WHERE code = ? AND type = 'asset' AND is_active = 1
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
    if (!in_array($method, ['cash', 'bank', 'mobile_banking', 'old_erp'], true)) {
        return '';
    }
    $fallback = ($method === 'bank' || $method === 'mobile_banking')
        ? acc_setting('default_bank_account', '1200')
        : acc_setting('default_cash_account', '1100');

    // Payments previously collected in the old ERP are treated as cash already
    // received, so they map to the same received-into account as cash.
    $setting_key = match ($method) {
        'cash', 'old_erp' => 'received_into_cash_account',
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

    if (!in_array($method, ['cash', 'bank', 'mobile_banking', 'old_erp'], true)) {
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
         WHERE type = 'asset' AND is_active = 1
         ORDER BY (sub_type = 'current_asset') DESC, (code LIKE '1%') DESC, code ASC
         LIMIT 1"
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
    $methods = $methods ?: ['cash', 'bank', 'mobile_banking', 'old_erp'];
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
    ?int   $reversal_of = null,
    string $status     = 'posted'
): int {
    // `memo` vouchers (e.g. Old ERP receipts) are recorded so the receipt and
    // student dues stay correct, but are intentionally excluded from the books
    // and every collection report, all of which only count `posted` vouchers.
    if (!in_array($status, ['posted', 'memo'], true)) {
        $status = 'posted';
    }
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
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $voucher_num,
            $type,
            $date,
            $reference ?: null,
            $narration ?: null,
            $total_debit,
            $status,
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
            ucfirst($type) . ' voucher ' . ($status === 'memo' ? 'recorded (memo, not counted): ' : 'posted: ') . $narration
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
        'memo'     => '<span class="badge bg-secondary">Old ERP (not counted)</span>',
        default    => '<span class="badge bg-secondary">' . h(ucfirst($status)) . '</span>',
    };
}

// ── Voucher Delete Workflow ───────────────────────────────────────────────────
//
// Flow:
//   Super Admin    → deletes immediately.
//   "Accounts"     → raises a delete request   (pending_dd).
//   "DD Accounts"  → reviews with a note        (→ pending_treasurer / rejected).
//   "Treasurer"    → confirms with a note       (→ deleted / rejected).
//
// Every action is also written to the immutable change_log.

/**
 * Configurable workflow group names (override via Accounting Settings keys
 * voucher_delete_group_accounts / _dd / _treasurer).
 */
function acc_vdel_group_name(string $role): string
{
    $defaults = [
        'accounts'  => 'Accounts',
        'dd'        => 'DD Accounts',
        'treasurer' => 'Treasurer',
    ];
    $default = $defaults[$role] ?? '';
    return trim(acc_setting('voucher_delete_group_' . $role, $default)) ?: $default;
}

/**
 * Whether the current user belongs to a user group with the given name.
 */
function acc_user_in_group_named(string $name): bool
{
    if ($name === '') return false;
    $user = auth_user();
    if (!$user) return false;
    $group_ids = $user['group_ids'] ?? [(int)$user['group_id']];
    if (empty($group_ids)) return false;

    static $cache = null;
    if ($cache === null) {
        $placeholders = implode(',', array_fill(0, count($group_ids), '?'));
        $stmt = db()->prepare(
            "SELECT LOWER(name) FROM user_groups WHERE id IN ($placeholders) AND is_active = 1"
        );
        $stmt->execute($group_ids);
        $cache = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return in_array(strtolower($name), $cache, true);
}

/** Super admins may delete a voucher outright. */
function acc_can_delete_voucher_directly(): bool
{
    return is_super_admin();
}

/** Members of the "Accounts" group may raise a delete request. */
function acc_can_request_voucher_delete(): bool
{
    return is_super_admin() || acc_user_in_group_named(acc_vdel_group_name('accounts'));
}

/** Members of the "DD Accounts" group review pending_dd requests. */
function acc_can_review_voucher_delete_dd(): bool
{
    return is_super_admin() || acc_user_in_group_named(acc_vdel_group_name('dd'));
}

/** Members of the "Treasurer" group give final confirmation. */
function acc_can_review_voucher_delete_treasurer(): bool
{
    return is_super_admin() || acc_user_in_group_named(acc_vdel_group_name('treasurer'));
}

/** Anyone who participates in the workflow can see the delete request queue. */
function acc_can_access_voucher_delete(): bool
{
    return acc_can_delete_voucher_directly()
        || acc_can_request_voucher_delete()
        || acc_can_review_voucher_delete_dd()
        || acc_can_review_voucher_delete_treasurer();
}

/** Status badge for a delete request. */
function acc_voucher_delete_status_badge(string $status): string
{
    return match ($status) {
        'pending_dd'        => '<span class="badge bg-warning text-dark">Pending DD Accounts</span>',
        'pending_treasurer' => '<span class="badge bg-info text-dark">Pending Treasurer</span>',
        'deleted'           => '<span class="badge bg-danger">Deleted</span>',
        'rejected'          => '<span class="badge bg-secondary">Rejected</span>',
        default             => '<span class="badge bg-secondary">' . h(ucfirst($status)) . '</span>',
    };
}

/**
 * Latest delete request for a voucher (any status), with actor names.
 */
function acc_get_delete_request_for_voucher(int $voucher_id): ?array
{
    $stmt = db()->prepare(
        'SELECT r.*, ru.full_name AS requested_by_name,
                du.full_name AS dd_user_name, tu.full_name AS treasurer_user_name,
                xu.full_name AS rejected_by_name
         FROM acc_voucher_delete_requests r
         LEFT JOIN users ru ON ru.id = r.requested_by
         LEFT JOIN users du ON du.id = r.dd_user_id
         LEFT JOIN users tu ON tu.id = r.treasurer_user_id
         LEFT JOIN users xu ON xu.id = r.rejected_by
         WHERE r.voucher_id = ?
         ORDER BY r.id DESC LIMIT 1'
    );
    $stmt->execute([$voucher_id]);
    return $stmt->fetch() ?: null;
}

/**
 * Fetch a single delete request by id (with actor names).
 */
function acc_get_delete_request(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT r.*, ru.full_name AS requested_by_name,
                du.full_name AS dd_user_name, tu.full_name AS treasurer_user_name,
                xu.full_name AS rejected_by_name
         FROM acc_voucher_delete_requests r
         LEFT JOIN users ru ON ru.id = r.requested_by
         LEFT JOIN users du ON du.id = r.dd_user_id
         LEFT JOIN users tu ON tu.id = r.treasurer_user_id
         LEFT JOIN users xu ON xu.id = r.rejected_by
         WHERE r.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Is there an open (not finalised) delete request for this voucher? */
function acc_voucher_has_open_delete_request(int $voucher_id): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM acc_voucher_delete_requests
         WHERE voucher_id = ? AND status IN ('pending_dd','pending_treasurer') LIMIT 1"
    );
    $stmt->execute([$voucher_id]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Build a JSON snapshot of a voucher and its line items for permanent audit.
 */
function acc_voucher_snapshot(int $voucher_id): string
{
    $stmt = db()->prepare('SELECT * FROM acc_vouchers WHERE id = ?');
    $stmt->execute([$voucher_id]);
    $voucher = $stmt->fetch() ?: [];
    $items   = acc_get_voucher_items($voucher_id);
    return json_encode(['voucher' => $voucher, 'items' => $items], JSON_UNESCAPED_UNICODE);
}

/**
 * Apply the soft-delete to a voucher and record it on the request + change_log.
 * Internal: callers must have already authorised the action.
 */
function acc_soft_delete_voucher(int $voucher_id, string $reason, ?int $request_id = null): void
{
    $db      = db();
    $user    = auth_user();
    $voucher = acc_get_voucher($voucher_id);
    if (!$voucher) {
        throw new RuntimeException('Voucher not found or already deleted.');
    }

    $db->prepare(
        'UPDATE acc_vouchers
         SET is_deleted = 1, deleted_by = ?, deleted_at = NOW(),
             delete_reason = ?, delete_request_id = ?
         WHERE id = ? AND is_deleted = 0'
    )->execute([$user['id'], $reason, $request_id, $voucher_id]);

    log_change(
        'accounting',
        'DELETE',
        $voucher_id,
        $voucher['voucher_number'],
        'is_deleted',
        '0',
        '1',
        'Voucher deleted (amount ' . number_format((float)$voucher['total_amount'], 2) . '). Reason: ' . $reason
    );
}

/**
 * Super-admin immediate deletion. Records a finalised request row for history.
 *
 * @return int request id
 */
function acc_direct_delete_voucher(int $voucher_id, string $reason, ?string $attachment = null): int
{
    if (!acc_can_delete_voucher_directly()) {
        throw new RuntimeException('You are not authorised to delete vouchers directly.');
    }
    $voucher = acc_get_voucher($voucher_id);
    if (!$voucher) {
        throw new RuntimeException('Voucher not found or already deleted.');
    }
    if (acc_voucher_has_open_delete_request($voucher_id)) {
        throw new RuntimeException('This voucher already has a delete request in progress.');
    }

    $db   = db();
    $user = auth_user();
    $stmt = $db->prepare(
        'INSERT INTO acc_voucher_delete_requests
            (voucher_id, voucher_number, voucher_snapshot, total_amount, status,
             reason, attachment, requested_by, requested_at,
             treasurer_user_id, treasurer_note, treasurer_at)
         VALUES (?,?,?,?, "deleted", ?,?,?, NOW(), ?, ?, NOW())'
    );
    $stmt->execute([
        $voucher_id,
        $voucher['voucher_number'],
        acc_voucher_snapshot($voucher_id),
        $voucher['total_amount'],
        $reason,
        $attachment,
        $user['id'],
        $user['id'],
        'Immediate deletion by Super Administrator.',
    ]);
    $request_id = (int)$db->lastInsertId();

    acc_soft_delete_voucher($voucher_id, $reason, $request_id);
    return $request_id;
}

/**
 * Super-admin bulk immediate deletion. Deletes many vouchers with one shared
 * reason (and optional shared attachment). Each voucher is deleted via
 * acc_direct_delete_voucher so it keeps the same per-voucher audit trail.
 *
 * Vouchers that are missing/already deleted or that already have an open delete
 * request are skipped silently. Returns the count actually deleted.
 *
 * @param int[] $voucher_ids
 */
function acc_bulk_direct_delete_vouchers(array $voucher_ids, string $reason, ?string $attachment = null): int
{
    if (!acc_can_delete_voucher_directly()) {
        throw new RuntimeException('You are not authorised to delete vouchers directly.');
    }

    $voucher_ids = array_map('intval', $voucher_ids);
    $voucher_ids = array_filter($voucher_ids, static fn(int $id): bool => $id > 0);
    $voucher_ids = array_values(array_unique($voucher_ids));

    $deleted = 0;
    foreach ($voucher_ids as $vid) {
        if (!acc_get_voucher($vid)) {
            continue; // not found or already deleted
        }
        if (acc_voucher_has_open_delete_request($vid)) {
            continue; // an open workflow request exists; skip
        }
        acc_direct_delete_voucher($vid, $reason, $attachment);
        $deleted++;
    }
    return $deleted;
}

/**
 * "Accounts" group raises a pending delete request.
 *
 * @return int request id
 */
function acc_create_delete_request(int $voucher_id, string $reason, ?string $attachment = null): int
{
    if (!acc_can_request_voucher_delete()) {
        throw new RuntimeException('You are not authorised to request voucher deletion.');
    }
    $voucher = acc_get_voucher($voucher_id);
    if (!$voucher) {
        throw new RuntimeException('Voucher not found or already deleted.');
    }
    if (acc_voucher_has_open_delete_request($voucher_id)) {
        throw new RuntimeException('A delete request for this voucher is already in progress.');
    }

    $db   = db();
    $user = auth_user();
    $stmt = $db->prepare(
        'INSERT INTO acc_voucher_delete_requests
            (voucher_id, voucher_number, voucher_snapshot, total_amount, status,
             reason, attachment, requested_by, requested_at)
         VALUES (?,?,?,?, "pending_dd", ?,?,?, NOW())'
    );
    $stmt->execute([
        $voucher_id,
        $voucher['voucher_number'],
        acc_voucher_snapshot($voucher_id),
        $voucher['total_amount'],
        $reason,
        $attachment,
        $user['id'],
    ]);
    $request_id = (int)$db->lastInsertId();

    log_change(
        'accounting',
        'UPDATE',
        $voucher_id,
        $voucher['voucher_number'],
        'delete_request',
        null,
        'pending_dd',
        'Voucher delete requested. Reason: ' . $reason
    );
    return $request_id;
}

/**
 * DD Accounts review: approve (→ pending_treasurer) or reject.
 */
function acc_dd_review_delete_request(int $request_id, bool $approve, string $note): void
{
    if (!acc_can_review_voucher_delete_dd()) {
        throw new RuntimeException('You are not authorised to review at the DD Accounts stage.');
    }
    $req = acc_get_delete_request($request_id);
    if (!$req)                                 throw new RuntimeException('Delete request not found.');
    if ($req['status'] !== 'pending_dd')       throw new RuntimeException('This request is not awaiting DD Accounts review.');
    if (trim($note) === '')                    throw new RuntimeException('A note is required.');

    $db   = db();
    $user = auth_user();

    if ($approve) {
        $db->prepare(
            "UPDATE acc_voucher_delete_requests
             SET status = 'pending_treasurer', dd_user_id = ?, dd_note = ?, dd_at = NOW()
             WHERE id = ?"
        )->execute([$user['id'], $note, $request_id]);
        $new = 'pending_treasurer';
    } else {
        $db->prepare(
            "UPDATE acc_voucher_delete_requests
             SET status = 'rejected', dd_user_id = ?, dd_note = ?, dd_at = NOW(),
                 rejected_by = ?, reject_note = ?, rejected_at = NOW()
             WHERE id = ?"
        )->execute([$user['id'], $note, $user['id'], $note, $request_id]);
        $new = 'rejected';
    }

    log_change(
        'accounting',
        'UPDATE',
        (int)$req['voucher_id'],
        $req['voucher_number'],
        'delete_request',
        'pending_dd',
        $new,
        'DD Accounts ' . ($approve ? 'approved' : 'rejected') . ' delete request. Note: ' . $note
    );
}

/**
 * Treasurer review: confirm (deletes the voucher) or reject.
 */
function acc_treasurer_review_delete_request(int $request_id, bool $confirm, string $note): void
{
    if (!acc_can_review_voucher_delete_treasurer()) {
        throw new RuntimeException('You are not authorised to give final confirmation.');
    }
    $req = acc_get_delete_request($request_id);
    if (!$req)                                       throw new RuntimeException('Delete request not found.');
    if ($req['status'] !== 'pending_treasurer')      throw new RuntimeException('This request is not awaiting Treasurer confirmation.');
    if (trim($note) === '')                          throw new RuntimeException('A note is required.');

    $db   = db();
    $user = auth_user();

    if ($confirm) {
        $db->prepare(
            "UPDATE acc_voucher_delete_requests
             SET status = 'deleted', treasurer_user_id = ?, treasurer_note = ?, treasurer_at = NOW()
             WHERE id = ?"
        )->execute([$user['id'], $note, $request_id]);

        // Final irreversible (soft) delete of the voucher and all its calculations.
        acc_soft_delete_voucher(
            (int)$req['voucher_id'],
            $req['reason'] . ' | Treasurer: ' . $note,
            $request_id
        );
        $new = 'deleted';
    } else {
        $db->prepare(
            "UPDATE acc_voucher_delete_requests
             SET status = 'rejected', treasurer_user_id = ?, treasurer_note = ?, treasurer_at = NOW(),
                 rejected_by = ?, reject_note = ?, rejected_at = NOW()
             WHERE id = ?"
        )->execute([$user['id'], $note, $user['id'], $note, $request_id]);
        $new = 'rejected';
    }

    log_change(
        'accounting',
        $confirm ? 'DELETE' : 'UPDATE',
        (int)$req['voucher_id'],
        $req['voucher_number'],
        'delete_request',
        'pending_treasurer',
        $new,
        'Treasurer ' . ($confirm ? 'confirmed deletion' : 'rejected delete request') . '. Note: ' . $note
    );
}

/**
 * Validate and store an optional supporting attachment for a delete request.
 * Returns the stored filename, or null when no file was supplied.
 * Throws RuntimeException on an invalid upload.
 */
function acc_store_delete_attachment(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Attachment upload failed.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Attachment must be 5 MB or smaller.');
    }

    $allowed_exts  = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
    $allowed_mimes = [
        'application/pdf', 'image/jpeg', 'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        throw new RuntimeException('Invalid attachment type. Allowed: ' . implode(', ', $allowed_exts) . '.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) {
        throw new RuntimeException('Attachment content type is not allowed.');
    }

    $dir = UPLOAD_DIR . '/voucher-deletes';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the attachment directory.');
    }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the attachment.');
    }
    return $name;
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
                COALESCE(SUM(CASE WHEN v.id IS NOT NULL THEN vi.debit_amount  ELSE 0 END),0) AS total_debit,
                COALESCE(SUM(CASE WHEN v.id IS NOT NULL THEN vi.credit_amount ELSE 0 END),0) AS total_credit
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
                COALESCE(SUM(CASE WHEN v.id IS NOT NULL THEN vi.debit_amount  ELSE 0 END),0) AS total_debit,
                COALESCE(SUM(CASE WHEN v.id IS NOT NULL THEN vi.credit_amount ELSE 0 END),0) AS total_credit
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
function acc_student_form_fee_amount(): float
{
    return ACC_STUDENT_FORM_FEE;
}

function acc_student_id_card_fee_amount(): float
{
    return ACC_STUDENT_ID_CARD_FEE;
}

function acc_student_form_id_total_fee(): float
{
    return acc_student_form_fee_amount() + acc_student_id_card_fee_amount();
}

function acc_package_form_id_fee(array $pkg): float
{
    $snapshot = (float)($pkg['form_id_fee'] ?? 0);
    return $snapshot > 0 ? $snapshot : acc_student_form_id_total_fee();
}

function acc_split_form_id_fee(float $total_fee): array
{
    $default_total = acc_student_form_id_total_fee();
    if (abs($total_fee - $default_total) < 0.01) {
        return [
            'form_fee'    => acc_student_form_fee_amount(),
            'id_card_fee' => acc_student_id_card_fee_amount(),
        ];
    }

    $form_fee = round($total_fee / 2, 2);
    return [
        'form_fee'    => $form_fee,
        'id_card_fee' => round($total_fee - $form_fee, 2),
    ];
}

/**
 * One-time Project Fee snapshotted on the package (sfp_packages.project_fee).
 * 0.00 for packages without a project fee (all batches except those explicitly
 * assigned one, e.g. batch 261 = 3000.00). Null-safe so the code keeps working
 * if the project-fee-v1.sql migration has not been applied yet.
 */
function acc_package_project_fee(array $pkg): float
{
    return max(0.0, (float)($pkg['project_fee'] ?? 0));
}

function acc_package_payment_start(array $pkg, array $semester_fees = []): array
{
    $note = (string)($pkg['note'] ?? '');
    if (preg_match('/Payment start:\s*(\d{1,2})-(\d{4})/i', $note, $m)) {
        $month = (int)$m[1];
        $year  = (int)$m[2];
        if ($month >= 1 && $month <= 12) {
            return ['month' => $month, 'year' => $year];
        }
    }

    return [
        'month' => acc_package_start_month($pkg),
        'year'  => acc_package_start_year($pkg, $semester_fees),
    ];
}

/**
 * Whether a package uses a flat (fixed) monthly fee instead of the
 * merit-based per-semester calculation.
 */
function acc_package_is_fixed_monthly(array $pkg): bool
{
    return (($pkg['payment_type'] ?? 'merit') === 'fixed')
        && (float)($pkg['monthly_payment'] ?? 0) > 0;
}

/**
 * Resolve the semester total due and the per-month fee for a semester row,
 * honoring fixed-monthly packages.
 *
 * Merit-based packages use the supplied merit calculation (tuition_payable +
 * fixed + English portions). Fixed packages use the same per-semester basis:
 * the payable tuition for the semester is the Base Tuition / Semester
 * (`tuition_payable`, already net of any manual scholarship/concession), never
 * the flat `monthly_payment`. The Fixed Monthly Payment is a static,
 * informational figure only and is never multiplied into any semester total.
 *
 * Neither the institutional (fixed) fees nor the English Course Fee are part of
 * the per-semester tuition: both are charged separately, on top of the tuition,
 * so they are always counted in the semester total (and therefore in
 * collection, dues and statements).
 *
 * @return array{0: float, 1: float} [sem_total_due, monthly_fee]
 */
function acc_semester_monthly_due(array $pkg, array $sf, float $merit_sem_total_due, int $months_int): array
{
    $months_int = max(1, $months_int);

    if (acc_package_is_fixed_monthly($pkg)) {
        // Payable tuition per semester is the Base Tuition / Semester. We read it
        // from $sf['tuition_payable'], which sfp_recalculate_semester() already
        // stores net of any manual scholarship/concession (tuition_fee minus
        // discounts). The Fixed Monthly Payment is static and is intentionally NOT
        // used in this calculation.
        $tuition_per_sem = max(0.0, (float)($sf['tuition_payable'] ?? 0));

        $total_months    = (float)($pkg['total_months'] ?? 0);
        $mps             = (float)($pkg['months_per_semester'] ?? 0);

        // English Course Fee is billed separately, on top of the tuition.
        $english_per_sem = ($total_months > 0 && $mps > 0)
            ? round((float)($pkg['english_course_fee'] ?? 0) / $total_months * $mps, 2) : 0.0;
        $english_per_sem = max(0.0, $english_per_sem - (float)($sf['english_discount_amount'] ?? 0));

        // Institutional (fixed) fees are billed separately, on top of the tuition.
        $fixed_per_sem = ($total_months > 0 && $mps > 0)
            ? round((float)($pkg['fixed_institutional_fees'] ?? 0) / $total_months * $mps, 2) : 0.0;
        $fixed_per_sem = max(0.0, $fixed_per_sem - (float)($sf['fixed_discount_amount'] ?? 0));

        $sem_total = $tuition_per_sem + $english_per_sem + $fixed_per_sem;
    } else {
        $sem_total = $merit_sem_total_due;
    }

    $monthly_fee = $months_int > 1 ? round($sem_total / $months_int, 2) : $sem_total;
    return [$sem_total, $monthly_fee];
}

function acc_student_fee_summary(int $student_id): ?array
{
    $db = db();

    // Load package
    $pkg_stmt = $db->prepare(
        'SELECT p.*, s.full_name AS student_name, s.student_id AS student_sid, s.admitted_semester,
                s.status AS student_status,
                cp.bi_semester_start_month  AS linked_bi_semester_start_month,
                cp.tri_semester_start_month AS linked_tri_semester_start_month
         FROM sfp_packages p
         JOIN students s ON s.id = p.student_id
         LEFT JOIN cf_programs cp ON cp.id = p.cf_program_id
         WHERE p.student_id = ?'
    );
    $pkg_stmt->execute([$student_id]);
    $pkg = $pkg_stmt->fetch();
    if (!$pkg) return null;

    $package_id  = (int)$pkg['id'];
    // Registration fee remains snapshotted on the package (not global cf_settings)
    // Form fee and ID card fee are fixed accounting constants (500 + 500)
    $reg_fee     = (float)($pkg['reg_fee_per_semester'] ?? 0.0);
    $form_id_total_fee = acc_package_form_id_fee($pkg);
    $split_form_id_fee = acc_split_form_id_fee($form_id_total_fee);
    $form_fee_due      = (float)$split_form_id_fee['form_fee'];
    $id_card_fee_due   = (float)$split_form_id_fee['id_card_fee'];

    // Semester fee rows
    $sf_stmt = $db->prepare(
        'SELECT * FROM sfp_semester_fees WHERE package_id = ? ORDER BY semester_number ASC'
    );
    $sf_stmt->execute([$package_id]);
    $semester_fees = $sf_stmt->fetchAll();
    $num_semesters = count($semester_fees);
    $payment_start = acc_package_payment_start($pkg, $semester_fees);
    $start_month   = (int)$payment_start['month'];
    $start_year    = (int)$payment_start['year'];

    // Paid amounts per fee_type and per semester_fee_id
    $paid_stmt = $db->prepare(
        "SELECT sp.fee_type, COALESCE(sp.semester_fee_id, 0) AS sfid, COALESCE(SUM(sp.amount),0) AS paid
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         WHERE sp.package_id = ?
           AND v.is_deleted = 0
           AND v.status IN ('posted','memo')
         GROUP BY sp.fee_type, sp.semester_fee_id"
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

    // Admission-day one-time fees — collected as three separate heads:
    //   1. Admission Fee, 2. Form Fee, 3. ID Card Fee
    $admission_base_due = (float)$pkg['admission_fees'];

    // Legacy payments recorded under the bundled 'admission' fee_type covered all
    // three heads at once. Allocate that paid amount sequentially across the heads
    // (admission base → form fee → ID card fee) so historic data reports correctly,
    // while newer payments use the dedicated 'form_fee' / 'id_card_fee' types.
    $bundled_admission_paid = $total_paid_for('admission');
    $form_fee_paid_direct   = $total_paid_for('form_fee');
    $id_card_fee_paid_direct = $total_paid_for('id_card_fee');

    $alloc = $bundled_admission_paid;
    $admission_base_paid = min($alloc, $admission_base_due);
    $alloc -= $admission_base_paid;
    $form_fee_alloc = min($alloc, $form_fee_due);
    $alloc -= $form_fee_alloc;
    $id_card_fee_alloc = min($alloc, $id_card_fee_due);
    $alloc -= $id_card_fee_alloc;
    // Any leftover bundled payment (overpayment) stays attributed to the admission head.
    $admission_base_paid += $alloc;

    $form_fee_paid    = $form_fee_paid_direct + $form_fee_alloc;
    $id_card_fee_paid = $id_card_fee_paid_direct + $id_card_fee_alloc;

    // Retained for backwards compatibility (combined admission obligation/paid).
    $admission_due  = $admission_base_due + $form_id_total_fee;
    $admission_paid = $bundled_admission_paid + $form_fee_paid_direct + $id_card_fee_paid_direct;

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
        $merit_sem_total_due = $tuition_payable_sem + $fixed_per_sem + $english_per_sem;

        // Monthly fee (distribute evenly; last month absorbs any rounding remainder).
        // Fixed and merit packages share the same per-semester basis (Base Tuition /
        // Semester); the Fixed Monthly Payment is static and never used here.
        [$sem_total_due, $monthly_fee] = acc_semester_monthly_due($pkg, $sf, $merit_sem_total_due, $months_int);

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
            // Semester drop (deferral): obligation months are shifted forward past
            // any active drop windows so the dropped months' tuition is not erased
            // but pushed to the end – the student still owes the full programme
            // total and the schedule is extended by the drop length.
            $month_info = (function_exists('sd_shifted_slot_calendar') && $student_id > 0)
                ? sd_shifted_slot_calendar($student_id, $start_month, $start_year, $month_offset)
                : acc_month_year_for_slot($start_month, $start_year, $month_offset);
            $monthly_rows[] = [
                'month_number' => $m,
                'month_label'  => $month_info['label'],
                'cal_month'    => $month_info['month'],
                'cal_year'     => $month_info['year'],
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

    // Additional / examination fees (variable amount, collected outside the
    // scheduled obligations). They have no "due"; only the paid amount matters.
    $additional_items = [];
    $additional_total_paid = 0.0;
    foreach (acc_additional_fee_types() as $add_type) {
        $paid = $total_paid_for($add_type);
        $additional_total_paid += $paid;
        $additional_items[] = [
            'fee_type'  => $add_type,
            'label'     => acc_fee_type_label($add_type),
            'paid'      => round($paid, 2),
        ];
    }

    return [
        'package'     => $pkg,
        'cf_settings' => ['reg_fee_per_semester' => $reg_fee, 'form_id_fee' => $form_id_total_fee],
        'semesters'   => $semesters_enriched,
        'additional'  => [
            'items'       => $additional_items,
            'total_paid'  => round($additional_total_paid, 2),
        ],
        'totals'      => [
            // Admission head now reflects only the base admission fee. Form fee and
            // ID card fee are collected as their own heads below.
            'admission'    => ['due' => $admission_base_due, 'paid' => $admission_base_paid, 'out' => max(0.0, $admission_base_due - $admission_base_paid)],
            'form_fee'     => ['due' => $form_fee_due,       'paid' => $form_fee_paid,       'out' => max(0.0, $form_fee_due - $form_fee_paid)],
            'id_card_fee'  => ['due' => $id_card_fee_due,    'paid' => $id_card_fee_paid,    'out' => max(0.0, $id_card_fee_due - $id_card_fee_paid)],
            // Combined admission obligation retained for backwards compatibility.
            'admission_combined' => ['due' => $admission_due, 'paid' => $admission_paid, 'out' => max(0.0, $admission_due - $admission_paid)],
            'admission_breakdown' => [
                'admission_base_fee' => $admission_base_due,
                'form_fee'      => $form_fee_due,
                'id_card_fee'   => $id_card_fee_due,
            ],
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
 * @param  bool   $allow_duplicate_transaction_number When true, the unique
 *         transaction/receipt-number guard is skipped. Used by the Old ERP bulk
 *         merge, where one historical receipt commonly bundles several fee heads
 *         (e.g. Admission + Form + ID Card + Registration) and so legitimately
 *         appears on more than one row.
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
    string $narration  = '',
    bool   $allow_duplicate_transaction_number = false
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

    if (!$allow_duplicate_transaction_number && $transaction_number !== null && acc_transaction_number_exists($transaction_number)) {
        throw new RuntimeException(
            'Transaction number "' . $transaction_number . '" has already been used. Each payment must have a unique transaction number.'
        );
    }

    // Post the receipt voucher. Payments already collected in the old ERP are
    // recorded as `memo` vouchers: the receipt and the student's dues stay
    // correct, but the amount is not counted again in this system's books or
    // collection reports (it was already counted in the old ERP).
    $voucher_status = $payment_method === 'old_erp' ? 'memo' : 'posted';
    $voucher_id = acc_post_voucher('receipt', $date, [
        ['account_id' => $cash_account_id,   'debit' => $amount, 'credit' => 0,       'description' => $narration],
        ['account_id' => $income_account_id, 'debit' => 0,       'credit' => $amount, 'description' => $narration],
    ], $narration, $reference, null, $voucher_status);

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
           AND v.is_deleted = 0
           AND v.status IN ("posted","memo")
         ORDER BY sp.collected_at DESC, sp.id DESC'
    );
    $stmt->execute([$package_id]);
    return $stmt->fetchAll();
}

/**
 * Fetch the payment-method details recorded for a voucher.
 *
 * Looks in sfp_payments first, then adm_admission_fee_payments. Returns null
 * when no payment row is linked (e.g. journal / expense / transfer vouchers).
 *
 * @return array{payment_method:string,mobile_banking_provider:?string,transaction_number:?string}|null
 */
function acc_get_voucher_payment_info(int $voucher_id): ?array
{
    if ($voucher_id <= 0) {
        return null;
    }
    $db = db();

    $stmt = $db->prepare(
        'SELECT payment_method, mobile_banking_provider, transaction_number
         FROM sfp_payments
         WHERE voucher_id = ?
         -- Prefer rows with non-empty txn/receipt numbers (false sorts before true),
         -- then newest row as fallback when none carry a txn number.
         ORDER BY (transaction_number IS NULL OR transaction_number = "") ASC, id DESC
         LIMIT 1'
    );
    $stmt->execute([$voucher_id]);
    $row = $stmt->fetch() ?: null;

    if (!$row) {
        $stmt = $db->prepare(
            'SELECT payment_method, mobile_banking_provider, transaction_number
             FROM adm_admission_fee_payments WHERE voucher_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$voucher_id]);
        $row = $stmt->fetch() ?: null;
    }

    if (!$row) {
        return null;
    }

    return [
        'payment_method'          => (string)($row['payment_method'] ?? 'cash'),
        'mobile_banking_provider' => $row['mobile_banking_provider'] ?? null,
        'transaction_number'      => $row['transaction_number'] ?? null,
    ];
}

/**
 * Resolve business purposes for multiple vouchers in one pass.
 *
 * @param int[] $voucher_ids
 * @return array<int,array> voucher_id => purpose details
 */
function acc_get_voucher_purposes(array $voucher_ids): array
{
    $voucher_ids = array_map('intval', $voucher_ids);
    $voucher_ids = array_filter($voucher_ids, static fn(int $id): bool => $id > 0);
    $voucher_ids = array_unique($voucher_ids);
    $voucher_ids = array_values($voucher_ids);
    if (!$voucher_ids) {
        return [];
    }

    $db = db();
    $purposes = [];
    $placeholders = implode(',', array_fill(0, count($voucher_ids), '?'));

    $stmt = $db->prepare(
        "SELECT sp.voucher_id, sp.fee_type, sp.semester_number, sp.month_number, sp.amount,
                sp.package_id,
                sf.semester_label,
                s.id AS student_pk, s.student_id, s.full_name AS student_name,
                s.admitted_semester
         FROM sfp_payments sp
         JOIN students s ON s.id = sp.student_id
         LEFT JOIN sfp_semester_fees sf ON sf.id = sp.semester_fee_id
         WHERE sp.voucher_id IN ($placeholders)
         ORDER BY sp.voucher_id ASC, sp.id ASC"
    );
    $stmt->execute($voucher_ids);
    $rows = $stmt->fetchAll();

    $student_groups = [];
    foreach ($rows as $r) {
        $vid = (int)$r['voucher_id'];
        if (!isset($student_groups[$vid])) {
            $student_groups[$vid] = [];
        }
        $student_groups[$vid][] = $r;
    }
    foreach ($student_groups as $vid => $group_rows) {
        $first = $group_rows[0];
        $items = [];
        foreach ($group_rows as $r) {
            $sem = $r['semester_label'] !== null && $r['semester_label'] !== ''
                ? $r['semester_label']
                : ($r['semester_number'] ? 'Semester ' . (int)$r['semester_number'] : '');
            $items[] = [
                'fee_type'       => $r['fee_type'],
                'fee_type_label' => acc_fee_type_label((string)$r['fee_type']),
                'semester_label' => $sem,
                'month_number'   => $r['month_number'] !== null ? (int)$r['month_number'] : null,
                'amount'         => (float)$r['amount'],
            ];
        }
        $purposes[$vid] = [
            'kind'              => 'student_fee',
            'label'             => 'Student Fee Payment',
            'student_pk'        => (int)$first['student_pk'],
            'package_id'        => (int)$first['package_id'],
            'student_id'        => (string)$first['student_id'],
            'student_name'      => (string)$first['student_name'],
            'admitted_semester' => (string)($first['admitted_semester'] ?? ''),
            'items'             => $items,
        ];
    }

    $pending = array_values(array_diff($voucher_ids, array_keys($purposes)));
    if ($pending) {
        $placeholders = implode(',', array_fill(0, count($pending), '?'));
        $stmt = $db->prepare(
            "SELECT ap.voucher_id, ap.amount,
                    a.id AS application_pk, a.app_number, a.student_name, a.assigned_student_id
             FROM adm_admission_fee_payments ap
             JOIN admissions_applications a ON a.id = ap.application_id
             WHERE ap.voucher_id IN ($placeholders)
             ORDER BY ap.voucher_id ASC, ap.id ASC"
        );
        $stmt->execute($pending);
        $rows = $stmt->fetchAll();

        $admission_groups = [];
        foreach ($rows as $r) {
            $vid = (int)$r['voucher_id'];
            if (!isset($admission_groups[$vid])) {
                $admission_groups[$vid] = [];
            }
            $admission_groups[$vid][] = $r;
        }
        foreach ($admission_groups as $vid => $group_rows) {
            $first = $group_rows[0];
            $items = [];
            foreach ($group_rows as $r) {
                $items[] = [
                    'fee_type'       => 'admission',
                    'fee_type_label' => acc_fee_type_label('admission'),
                    'semester_label' => '',
                    'month_number'   => null,
                    'amount'         => (float)$r['amount'],
                ];
            }
            $purposes[$vid] = [
                'kind'                => 'admission_fee',
                'label'               => 'Admission Fee Payment',
                'application_pk'      => (int)$first['application_pk'],
                'app_number'          => (string)$first['app_number'],
                'student_name'        => (string)$first['student_name'],
                'assigned_student_id' => (string)($first['assigned_student_id'] ?? ''),
                'items'               => $items,
            ];
        }
    }

    return $purposes;
}

/**
 * Resolve what a voucher is actually for (its business purpose), so a voucher
 * can show e.g. "Student Fee Payment – Semester Tuition for Md Omar Faruk".
 *
 * Looks up the originating record(s) that created the voucher: student fee
 * payments (sfp_payments) or pre-admission admission-fee payments
 * (adm_admission_fee_payments). A single receipt may cover several fee heads.
 *
 * @return array|null Purpose details, or null when the voucher has no linked
 *                    student/applicant payment (e.g. expenses, transfers).
 */
function acc_get_voucher_purpose(int $voucher_id): ?array
{
    if ($voucher_id <= 0) {
        return null;
    }
    $purposes = acc_get_voucher_purposes([$voucher_id]);
    return $purposes[$voucher_id] ?? null;
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
    return array_merge(
        ['admission', 'form_fee', 'id_card_fee', 'registration', 'semester_tuition', 'fixed_fee', 'english_fee'],
        acc_additional_fee_types(),
        ['other']
    );
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
        'form_fee'         => '4200', // Form Fee (one-time, with admission)
        'id_card_fee'      => '4200', // ID Card Fee (one-time, with admission)
        'registration'     => '4100', // Tuition Fees (reg)
        'semester_tuition' => '4100', // Tuition Fees
        'fixed_fee'        => '4100', // Tuition Fees
        'english_fee'      => '4100', // Tuition Fees
        'retake_fee'           => '4700', // Miscellaneous Income
        'improvement_fee'      => '4700', // Miscellaneous Income
        'special_exam_midterm' => '4700', // Miscellaneous Income
        'special_exam_final'   => '4700', // Miscellaneous Income
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
    static $sid_column_support = null;
    if ($sid_column_support === null) {
        $sid_column_support = ['assigned' => false, 'office' => false];
        $col_stmt = db()->query(
            "SHOW COLUMNS FROM admissions_applications WHERE Field IN ('assigned_student_id', 'office_student_id')"
        );
        foreach ($col_stmt->fetchAll() as $col) {
            $field = (string)($col['Field'] ?? '');
            if ($field === 'assigned_student_id') $sid_column_support['assigned'] = true;
            if ($field === 'office_student_id')   $sid_column_support['office'] = true;
        }
    }
    if ($sid_column_support['assigned'] && $sid_column_support['office']) {
        $sql = 'SELECT a.id, a.app_number, a.student_name, a.present_contact, a.present_email,
                a.dept_id, a.program_id, a.status, a.assigned_student_id, a.office_student_id,
                d.name AS dept_name, p.program_name
         FROM admissions_applications a
         LEFT JOIN dept_departments d        ON d.id = a.dept_id
         LEFT JOIN dept_academic_programs p  ON p.id = a.program_id
         WHERE a.app_number = ?
         LIMIT 1';
    } elseif ($sid_column_support['assigned']) {
        $sql = 'SELECT a.id, a.app_number, a.student_name, a.present_contact, a.present_email,
                a.dept_id, a.program_id, a.status, a.assigned_student_id, NULL AS office_student_id,
                d.name AS dept_name, p.program_name
         FROM admissions_applications a
         LEFT JOIN dept_departments d        ON d.id = a.dept_id
         LEFT JOIN dept_academic_programs p  ON p.id = a.program_id
         WHERE a.app_number = ?
         LIMIT 1';
    } elseif ($sid_column_support['office']) {
        $sql = 'SELECT a.id, a.app_number, a.student_name, a.present_contact, a.present_email,
                a.dept_id, a.program_id, a.status, NULL AS assigned_student_id, a.office_student_id,
                d.name AS dept_name, p.program_name
         FROM admissions_applications a
         LEFT JOIN dept_departments d        ON d.id = a.dept_id
         LEFT JOIN dept_academic_programs p  ON p.id = a.program_id
         WHERE a.app_number = ?
         LIMIT 1';
    } else {
        $sql = 'SELECT a.id, a.app_number, a.student_name, a.present_contact, a.present_email,
                a.dept_id, a.program_id, a.status, NULL AS assigned_student_id, NULL AS office_student_id,
                d.name AS dept_name, p.program_name
         FROM admissions_applications a
         LEFT JOIN dept_departments d        ON d.id = a.dept_id
         LEFT JOIN dept_academic_programs p  ON p.id = a.program_id
         WHERE a.app_number = ?
         LIMIT 1';
    }

    $stmt = db()->prepare($sql);
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

    if ($transaction_number !== null && acc_transaction_number_exists($transaction_number)) {
        throw new RuntimeException(
            'Transaction number "' . $transaction_number . '" has already been used. Each payment must have a unique transaction number.'
        );
    }

    // Old ERP payments are recorded as `memo` vouchers so they are not counted
    // again in this system's books or collection reports.
    $voucher_status = $payment_method === 'old_erp' ? 'memo' : 'posted';
    $voucher_id = acc_post_voucher('receipt', $date, [
        ['account_id' => $cash_account_id,   'debit' => $amount, 'credit' => 0,       'description' => $narration],
        ['account_id' => $income_account_id, 'debit' => 0,       'credit' => $amount, 'description' => $narration],
    ], $narration, $reference, null, $voucher_status);

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
        'form_fee'         => 'Form Fee',
        'id_card_fee'      => 'ID Card Fee',
        'registration'     => 'Registration Fee',
        'semester_tuition' => 'Semester Tuition Fee',
        'fixed_fee'        => 'Fixed Institutional Fee',
        'english_fee'      => 'English Course Fee',
        'retake_fee'           => 'Re-Take Fee',
        'improvement_fee'      => 'Improvement Fee',
        'special_exam_midterm' => 'Special Examination (Mid Term)',
        'special_exam_final'   => 'Special Examination (Final)',
        'other'            => 'Other Fee',
        default            => ucfirst(str_replace('_', ' ', $fee_type)),
    };
}

/**
 * Additional / examination fee heads collected with a variable (non-fixed)
 * amount. These are NOT part of the scheduled fee obligations: they have no
 * "due", and once collected they are reported outside the fee schedule's
 * outstanding balance.
 *
 * @return string[]
 */
function acc_additional_fee_types(): array
{
    return ['retake_fee', 'improvement_fee', 'special_exam_midterm', 'special_exam_final'];
}

/**
 * True when the given fee type is a variable-amount additional/examination fee.
 */
function acc_is_additional_fee_type(string $fee_type): bool
{
    return in_array($fee_type, acc_additional_fee_types(), true);
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
        'old_erp' => 'Old ERP',
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
    if (!in_array($method, ['cash', 'bank', 'mobile_banking', 'old_erp'], true)) {
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
    } elseif ($method === 'old_erp') {
        // Old ERP payments must carry the original receipt number.
        if ($txn === null || $txn === '') {
            throw new RuntimeException('Receipt number is required for old ERP payments.');
        }
    } else {
        if ($txn === null || $txn === '') {
            throw new RuntimeException('Transaction number is required for non-cash payments.');
        }
    }

    return [$method, $provider, $txn];
}

/**
 * Return true if a transaction number is already recorded in any payment table.
 * NULL / empty strings are never considered duplicates.
 */
function acc_transaction_number_exists(string $transaction_number): bool
{
    if ($transaction_number === '') {
        return false;
    }
    $db = db();
    $stmt = $db->prepare(
        'SELECT 1
         FROM (
             SELECT transaction_number COLLATE utf8mb4_unicode_ci FROM sfp_payments
                 WHERE transaction_number = ?
             UNION ALL
             SELECT transaction_number COLLATE utf8mb4_unicode_ci FROM adm_admission_fee_payments
                 WHERE transaction_number = ?
         ) AS combined
         LIMIT 1'
    );
    $stmt->execute([$transaction_number, $transaction_number]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Find an existing payment by its transaction / receipt number.
 *
 * Searches sfp_payments first, then adm_admission_fee_payments, and returns the
 * recorded amount, fee type and student so callers can detect duplicates and
 * amount mismatches when merging historical (old ERP) receipts in bulk.
 *
 * @return array{source:string,amount:float,fee_type:?string,student_id:?int,voucher_id:?int}|null
 */
function acc_find_payment_by_transaction_number(string $transaction_number): ?array
{
    $transaction_number = trim($transaction_number);
    if ($transaction_number === '') {
        return null;
    }
    $db = db();

    $stmt = $db->prepare(
        'SELECT amount, fee_type, student_id, voucher_id
         FROM sfp_payments
         WHERE transaction_number = ?
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$transaction_number]);
    $row = $stmt->fetch();
    if ($row) {
        return [
            'source'     => 'sfp_payments',
            'amount'     => (float)$row['amount'],
            'fee_type'   => $row['fee_type'] !== null ? (string)$row['fee_type'] : null,
            'student_id' => $row['student_id'] !== null ? (int)$row['student_id'] : null,
            'voucher_id' => $row['voucher_id'] !== null ? (int)$row['voucher_id'] : null,
        ];
    }

    $stmt = $db->prepare(
        'SELECT amount, voucher_id
         FROM adm_admission_fee_payments
         WHERE transaction_number = ?
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$transaction_number]);
    $row = $stmt->fetch();
    if ($row) {
        return [
            'source'     => 'adm_admission_fee_payments',
            'amount'     => (float)$row['amount'],
            'fee_type'   => 'admission',
            'student_id' => null,
            'voucher_id' => $row['voucher_id'] !== null ? (int)$row['voucher_id'] : null,
        ];
    }

    return null;
}

/**
 * Determine package start month from snapshotted/linked program settings.
 */
function acc_package_start_month(array $pkg): int
{
    $note = (string)($pkg['note'] ?? '');
    if (preg_match('/Payment start:\s*(\d{1,2})-(\d{4})/i', $note, $m)) {
        $month = (int)$m[1];
        if ($month >= 1 && $month <= 12) {
            return $month;
        }
    }

    $total_semesters = (int)($pkg['total_semesters'] ?? 0);
    $is_bi = $total_semesters > 0 && $total_semesters <= 8;
    $start = $is_bi
        ? (int)($pkg['bi_semester_start_month'] ?? 0)
        : (int)($pkg['tri_semester_start_month'] ?? 0);
    if ($start < 1 || $start > 12) {
        $start = $is_bi
            ? (int)($pkg['linked_bi_semester_start_month'] ?? 0)
            : (int)($pkg['linked_tri_semester_start_month'] ?? 0);
    }
    return ($start >= 1 && $start <= 12) ? $start : 1;
}

/**
 * Determine package start year from semester labels/admitted semester.
 */
function acc_package_start_year(array $pkg, array $semester_fees): int
{
    $note = (string)($pkg['note'] ?? '');
    if (preg_match('/Payment start:\s*(\d{1,2})-(\d{4})/i', $note, $m)) {
        return (int)$m[2];
    }

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
 * Compute outstanding balance limited to fees due through the current calendar month.
 *
 * Unlike acc_total_outstanding (which counts every future semester), this function
 * only charges:
 *   - Admission + form/ID fees (always due in full – one-time on admission)
 *   - Registration fee for each semester whose first month ≤ today
 *   - Monthly tuition / fixed / English fees for months that are ≤ today
 *
 * Used by the admit-card access check so students are not blocked by dues that
 * have not yet fallen due.
 */
function acc_outstanding_through_current_month(int $package_id): float
{
    $db = db();

    $pkg_stmt = $db->prepare(
        'SELECT p.*,
                cp.bi_semester_start_month  AS linked_bi_semester_start_month,
                cp.tri_semester_start_month AS linked_tri_semester_start_month
         FROM sfp_packages p
         LEFT JOIN cf_programs cp ON cp.id = p.cf_program_id
         WHERE p.id = ?'
    );
    $pkg_stmt->execute([$package_id]);
    $pkg = $pkg_stmt->fetch();
    if (!$pkg) return 0.0;

    $sf_stmt = $db->prepare(
        'SELECT * FROM sfp_semester_fees WHERE package_id = ? ORDER BY semester_number ASC'
    );
    $sf_stmt->execute([$package_id]);
    $semester_fees = $sf_stmt->fetchAll();
    $num_semesters = count($semester_fees);

    $payment_start = acc_package_payment_start($pkg, $semester_fees);
    $start_month   = (int)$payment_start['month'];
    $start_year    = (int)$payment_start['year'];

    $now_month = (int)date('n');
    $now_year  = (int)date('Y');

    $sd_student_id = (int)($pkg['student_id'] ?? 0);

    // Official dropout: from the dropout effective date the account is frozen and
    // is no longer counted as a due in any financial fact, so report zero.
    if ($sd_student_id > 0 && function_exists('sd_student_dropped_out')
        && sd_student_dropped_out($sd_student_id)) {
        return 0.0;
    }

    $mps        = (float)($pkg['months_per_semester'] ?? 0);
    $months_int = max(1, (int)round($mps));
    $reg_fee    = (float)($pkg['reg_fee_per_semester'] ?? 0.0);

    // Admission and form/ID fees are always due immediately
    $total_due = (float)$pkg['admission_fees'] + acc_package_form_id_fee($pkg);

    foreach ($semester_fees as $sf) {
        $sem_num = (int)$sf['semester_number'];

        // Offset of the first month of this semester. Shifted forward past any
        // active drop windows so a deferred semester's registration / tuition is
        // not treated as due before its real (post-drop) start month.
        $first_offset    = ($sem_num - 1) * $months_int;
        $first_month_info = ($sd_student_id > 0 && function_exists('sd_shifted_slot_calendar'))
            ? sd_shifted_slot_calendar($sd_student_id, $start_month, $start_year, $first_offset)
            : acc_month_year_for_slot($start_month, $start_year, $first_offset);

        // Has this semester started yet?
        $sem_started = ($first_month_info['year'] < $now_year)
            || ($first_month_info['year'] === $now_year && $first_month_info['month'] <= $now_month);

        if (!$sem_started) {
            continue;
        }

        // Registration is due at the start of each semester
        $total_due += $reg_fee;

        // Per-semester portions of fixed institutional + English fees (after discounts)
        $fixed_per_sem   = ($months > 0 && $mps > 0)
            ? round((float)$pkg['fixed_institutional_fees'] / $months * $mps, 2) : 0.0;
        $english_per_sem = ($months > 0 && $mps > 0)
            ? round((float)$pkg['english_course_fee'] / $months * $mps, 2) : 0.0;
        $fixed_per_sem   = max(0.0, $fixed_per_sem   - (float)($sf['fixed_discount_amount']   ?? 0));
        $english_per_sem = max(0.0, $english_per_sem - (float)($sf['english_discount_amount'] ?? 0));

        $merit_sem_total_due = (float)$sf['tuition_payable'] + $fixed_per_sem + $english_per_sem;
        [$sem_total_due, $monthly_fee] = acc_semester_monthly_due($pkg, $sf, $merit_sem_total_due, $months_int);

        // Only add months that have already fallen due (≤ current calendar month).
        // Semester drop (deferral): the obligation month is shifted forward past any
        // active drop windows, so a dropped month's tuition is not discarded – it is
        // simply not counted as due until its deferred calendar month arrives.
        for ($m = 1; $m <= $months_int; $m++) {
            $global_offset = $first_offset + ($m - 1);
            $month_info = ($sd_student_id > 0 && function_exists('sd_shifted_slot_calendar'))
                ? sd_shifted_slot_calendar($sd_student_id, $start_month, $start_year, $global_offset)
                : acc_month_year_for_slot($start_month, $start_year, $global_offset);

            $month_due = ($month_info['year'] < $now_year)
                || ($month_info['year'] === $now_year && $month_info['month'] <= $now_month);

            if (!$month_due) {
                // Months map to strictly increasing (deferred) calendar months, so once
                // one is in the future all remaining months are also in the future.
                break;
            }

            // Last month absorbs any rounding remainder
            $total_due += ($m < $months_int)
                ? $monthly_fee
                : max(0.0, $sem_total_due - $monthly_fee * ($months_int - 1));
        }
    }

    // Total actually paid (real payments)
    $paid_stmt = $db->prepare(
        "SELECT COALESCE(SUM(sp.amount),0)
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         WHERE sp.package_id = ?
           AND v.is_deleted = 0
           AND v.status IN ('posted','memo')"
    );
    $paid_stmt->execute([$package_id]);
    $total_paid = (float)$paid_stmt->fetchColumn();

    return max(0.0, $total_due - $total_paid);
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

    $package_form_id_fee = acc_package_form_id_fee($pkg);
    $total_due = (float)$pkg['admission_fees']
               + $package_form_id_fee
               + ($reg_fee * $num_sems)
               + (float)$pkg['fixed_institutional_fees']
               + (float)$pkg['english_course_fee']
               + $tuition_total;

    $paid_stmt = $db->prepare(
        "SELECT COALESCE(SUM(sp.amount),0)
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         WHERE sp.package_id = ?
           AND v.is_deleted = 0
           AND v.status IN ('posted','memo')"
    );
    $paid_stmt->execute([$package_id]);
    $total_paid = (float)$paid_stmt->fetchColumn();

    return max(0.0, $total_due - $total_paid);
}
