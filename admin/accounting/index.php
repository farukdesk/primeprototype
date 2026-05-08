<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting');
require_once __DIR__ . '/helpers.php';

$page_title = 'Accounting';
$fy_start   = acc_fiscal_year_start();
$fy_end     = acc_fiscal_year_end();
$currency   = acc_currency();

// ── KPIs ──────────────────────────────────────────────────────────────────────

// Revenue this fiscal year
$stmt = db()->prepare(
    "SELECT COALESCE(SUM(vi.credit_amount),0) - COALESCE(SUM(vi.debit_amount),0)
     FROM acc_voucher_items vi
     JOIN acc_vouchers v ON v.id = vi.voucher_id
     JOIN acc_accounts a ON a.id = vi.account_id
     WHERE a.type = 'income' AND v.status = 'posted' AND v.is_deleted = 0
       AND v.voucher_date BETWEEN ? AND ?"
);
$stmt->execute([$fy_start, $fy_end]);
$total_revenue = (float)$stmt->fetchColumn();

// Expenses this fiscal year
$stmt = db()->prepare(
    "SELECT COALESCE(SUM(vi.debit_amount),0) - COALESCE(SUM(vi.credit_amount),0)
     FROM acc_voucher_items vi
     JOIN acc_vouchers v ON v.id = vi.voucher_id
     JOIN acc_accounts a ON a.id = vi.account_id
     WHERE a.type = 'expense' AND v.status = 'posted' AND v.is_deleted = 0
       AND v.voucher_date BETWEEN ? AND ?"
);
$stmt->execute([$fy_start, $fy_end]);
$total_expenses = (float)$stmt->fetchColumn();

$net_income = $total_revenue - $total_expenses;

// Cash balance (all asset accounts 1xxx)
$stmt = db()->query(
    "SELECT COALESCE(SUM(a.opening_balance),0)
     FROM acc_accounts a WHERE a.type = 'asset' AND a.code REGEXP '^1[0-9]' AND a.is_active = 1"
);
$cash_opening = (float)$stmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT COALESCE(SUM(vi.debit_amount),0) - COALESCE(SUM(vi.credit_amount),0)
     FROM acc_voucher_items vi
     JOIN acc_vouchers v ON v.id = vi.voucher_id
     JOIN acc_accounts a ON a.id = vi.account_id
     WHERE a.type = 'asset' AND a.code REGEXP '^1[0-9]'
       AND v.status = 'posted' AND v.is_deleted = 0"
);
$stmt->execute([]);
$cash_movement = (float)$stmt->fetchColumn();
$total_cash    = $cash_opening + $cash_movement;

// Voucher counts
$voucher_counts = db()->query(
    "SELECT voucher_type, COUNT(*) AS cnt
     FROM acc_vouchers WHERE status = 'posted' AND is_deleted = 0
     GROUP BY voucher_type"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$total_vouchers = array_sum($voucher_counts);

// Recent vouchers
$recent_vouchers = db()->query(
    "SELECT v.*, u.full_name AS created_by_name
     FROM acc_vouchers v
     LEFT JOIN users u ON u.id = v.created_by
     WHERE v.is_deleted = 0
     ORDER BY v.created_at DESC
     LIMIT 10"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-coins me-2 text-warning"></i>Accounting</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Accounting</li>
        </ol></nav>
    </div>
    <?php if (acc_can_create()): ?>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/accounting/collect-payment.php" class="btn btn-success btn-sm"><i class="fas fa-hand-holding-usd me-1"></i> Collect Payment</a>
        <a href="<?= APP_URL ?>/accounting/add-expense.php"     class="btn btn-danger btn-sm"><i class="fas fa-receipt me-1"></i> Add Expense</a>
        <a href="<?= APP_URL ?>/accounting/transfer-money.php"  class="btn btn-info btn-sm text-white"><i class="fas fa-exchange-alt me-1"></i> Transfer</a>
    </div>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<!-- ── KPI Cards ── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 p-3" style="background:#d1fae5"><i class="fas fa-arrow-down-to-bracket fa-lg text-success"></i></div>
                    <div>
                        <div class="text-muted small">Revenue (FY)</div>
                        <div class="fw-bold fs-6"><?= $currency ?> <?= number_format($total_revenue, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 p-3" style="background:#fee2e2"><i class="fas fa-file-invoice-dollar fa-lg text-danger"></i></div>
                    <div>
                        <div class="text-muted small">Expenses (FY)</div>
                        <div class="fw-bold fs-6"><?= $currency ?> <?= number_format($total_expenses, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 p-3" style="background:<?= $net_income >= 0 ? '#dbeafe' : '#fef3c7' ?>">
                        <i class="fas fa-chart-line fa-lg" style="color:<?= $net_income >= 0 ? '#3b82f6' : '#f59e0b' ?>"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Net Income (FY)</div>
                        <div class="fw-bold fs-6 <?= $net_income < 0 ? 'text-danger' : 'text-success' ?>"><?= $currency ?> <?= number_format($net_income, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-3 p-3" style="background:#fef9c3"><i class="fas fa-wallet fa-lg text-warning"></i></div>
                    <div>
                        <div class="text-muted small">Cash & Bank</div>
                        <div class="fw-bold fs-6"><?= $currency ?> <?= number_format($total_cash, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Quick Actions + Voucher Counts ── -->
<div class="row g-3 mb-4">
    <!-- Quick Actions -->
    <?php if (acc_can_create()): ?>
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-3"><strong class="small">Quick Actions</strong></div>
            <div class="card-body p-3 d-grid gap-2">
                <a href="<?= APP_URL ?>/accounting/collect-payment.php" class="btn btn-outline-success btn-sm text-start">
                    <i class="fas fa-hand-holding-usd me-2 text-success"></i> Collect Payment <small class="text-muted ms-1">— Receipt Voucher</small>
                </a>
                <a href="<?= APP_URL ?>/accounting/add-expense.php" class="btn btn-outline-danger btn-sm text-start">
                    <i class="fas fa-receipt me-2 text-danger"></i> Add Expense <small class="text-muted ms-1">— Payment Voucher</small>
                </a>
                <a href="<?= APP_URL ?>/accounting/transfer-money.php" class="btn btn-outline-info btn-sm text-start">
                    <i class="fas fa-exchange-alt me-2 text-info"></i> Transfer Money <small class="text-muted ms-1">— Contra Voucher</small>
                </a>
                <a href="<?= APP_URL ?>/accounting/vouchers.php" class="btn btn-outline-secondary btn-sm text-start">
                    <i class="fas fa-list me-2"></i> All Vouchers
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Voucher Counts -->
    <div class="col-md-<?= acc_can_create() ? '7' : '12' ?>">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-3"><strong class="small">Voucher Summary</strong></div>
            <div class="card-body p-3">
                <div class="row g-2">
                    <?php
                    $vt = [
                        'receipt' => ['Receipt',  'success', 'hand-holding-usd'],
                        'payment' => ['Payment',  'danger',  'receipt'],
                        'contra'  => ['Transfer', 'info',    'exchange-alt'],
                        'journal' => ['Journal',  'secondary','book'],
                    ];
                    foreach ($vt as $type => [$label, $color, $icon]):
                    ?>
                    <div class="col-6">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-3" style="background:#f8f9fb">
                            <i class="fas fa-<?= $icon ?> text-<?= $color ?>"></i>
                            <div>
                                <div class="fw-bold small"><?= $voucher_counts[$type] ?? 0 ?></div>
                                <div class="text-muted" style="font-size:.72rem"><?= $label ?> Vouchers</div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (acc_can_reports()): ?>
                <div class="mt-3 d-flex flex-wrap gap-2">
                    <a href="<?= APP_URL ?>/accounting/reports/trial-balance.php"        class="btn btn-outline-secondary btn-sm"><i class="fas fa-balance-scale me-1"></i> Trial Balance</a>
                    <a href="<?= APP_URL ?>/accounting/reports/income-statement.php"     class="btn btn-outline-secondary btn-sm"><i class="fas fa-chart-bar me-1"></i> Income Statement</a>
                    <a href="<?= APP_URL ?>/accounting/reports/balance-sheet.php"        class="btn btn-outline-secondary btn-sm"><i class="fas fa-building me-1"></i> Balance Sheet</a>
                    <a href="<?= APP_URL ?>/accounting/reports/student-collection.php"   class="btn btn-outline-primary btn-sm"><i class="fas fa-file-invoice-dollar me-1"></i> Student Collection</a>
                    <a href="<?= APP_URL ?>/accounting/reports/staff-collection.php"     class="btn btn-outline-info btn-sm"><i class="fas fa-users me-1"></i> Staff Collection</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Recent Vouchers ── -->
<div class="card border-0 shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between py-2 px-3">
        <strong class="small">Recent Vouchers</strong>
        <a href="<?= APP_URL ?>/accounting/vouchers.php" class="btn btn-outline-secondary btn-sm">View All</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recent_vouchers)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-coins fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No vouchers yet. Use the quick actions to get started.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Voucher #</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Narration</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_vouchers as $v): ?>
                    <tr>
                        <td><a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $v['id'] ?>" class="fw-semibold text-decoration-none"><?= h($v['voucher_number']) ?></a></td>
                        <td><?= acc_voucher_type_badge($v['voucher_type']) ?></td>
                        <td class="text-muted small"><?= date('d M Y', strtotime($v['voucher_date'])) ?></td>
                        <td class="small"><?= h($v['narration'] ?? '–') ?></td>
                        <td class="fw-semibold"><?= $currency ?> <?= number_format($v['total_amount'], 2) ?></td>
                        <td><?= acc_voucher_status_badge($v['status']) ?></td>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="text-muted small mt-3">
    <i class="fas fa-info-circle me-1"></i>
    Fiscal Year: <?= date('d M Y', strtotime($fy_start)) ?> – <?= date('d M Y', strtotime($fy_end)) ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
