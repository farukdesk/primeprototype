<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Cash Flow Statement';
$currency   = acc_currency();

$fy_start  = acc_fiscal_year_start();
$fy_end    = acc_fiscal_year_end();
$date_from = $_GET['date_from'] ?? $fy_start;
$date_to   = $_GET['date_to']   ?? $fy_end;

$data = acc_cash_flow($date_from ?: null, $date_to ?: null);

// Opening balance of cash accounts before date_from
$opening_balance = 0.0;
$cash_accounts_list = db()->query(
    "SELECT id, opening_balance FROM acc_accounts WHERE type = 'asset' AND code REGEXP '^1[0-9]' AND is_active = 1"
)->fetchAll();

foreach ($cash_accounts_list as $ca) {
    $opening_balance += (float)$ca['opening_balance'];
    if ($date_from) {
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(vi.debit_amount),0) - COALESCE(SUM(vi.credit_amount),0)
             FROM acc_voucher_items vi
             JOIN acc_vouchers v ON v.id = vi.voucher_id
             WHERE vi.account_id = ? AND v.status = 'posted' AND v.is_deleted = 0
               AND v.voucher_date < ?"
        );
        $stmt->execute([$ca['id'], $date_from]);
        $opening_balance += (float)$stmt->fetchColumn();
    }
}

$closing_balance = $opening_balance + $data['net_flow'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-water me-2 text-info"></i>Cash Flow Statement</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Cash Flow</li>
        </ol></nav>
    </div>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i> Print</button>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($date_from) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($date_to) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync me-1"></i> Generate</button>
                <a href="?date_from=<?= $fy_start ?>&date_to=<?= $fy_end ?>" class="btn btn-outline-secondary btn-sm">FY</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <!-- Opening Balance -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                <div class="fw-semibold">Opening Balance <span class="text-muted fw-normal small">(as of <?= $date_from ? date('d M Y', strtotime($date_from)) : 'Beginning' ?>)</span></div>
                <div class="fw-bold fs-5"><?= $currency ?> <?= number_format($opening_balance, 2) ?></div>
            </div>
        </div>
    </div>

    <!-- Cash Inflows -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-4" style="background:#d1fae5">
                <strong class="text-success"><i class="fas fa-plus-circle me-1"></i>Cash Inflows (Receipts)</strong>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Account</th><th class="text-end">Amount (<?= $currency ?>)</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['inflows'] as $row): ?>
                        <tr>
                            <td><?= h($row['account']) ?></td>
                            <td class="text-end fw-semibold text-success"><?= number_format($row['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($data['inflows'])): ?>
                        <tr><td colspan="2" class="text-center text-muted small py-3">No inflows in this period</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-success">
                        <tr>
                            <td class="fw-bold">Total Inflows</td>
                            <td class="text-end fw-bold text-success"><?= number_format($data['total_in'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Cash Outflows -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-4" style="background:#fee2e2">
                <strong class="text-danger"><i class="fas fa-minus-circle me-1"></i>Cash Outflows (Payments)</strong>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Account</th><th class="text-end">Amount (<?= $currency ?>)</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['outflows'] as $row): ?>
                        <tr>
                            <td><?= h($row['account']) ?></td>
                            <td class="text-end fw-semibold text-danger"><?= number_format($row['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($data['outflows'])): ?>
                        <tr><td colspan="2" class="text-center text-muted small py-3">No outflows in this period</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-danger">
                        <tr>
                            <td class="fw-bold">Total Outflows</td>
                            <td class="text-end fw-bold text-danger"><?= number_format($data['total_out'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Net Flow + Closing -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="row g-3 text-center">
                    <div class="col-md-4">
                        <div class="text-muted small mb-1">Net Cash Flow</div>
                        <div class="fw-bold fs-5 <?= $data['net_flow'] >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $data['net_flow'] >= 0 ? '+' : '' ?><?= $currency ?> <?= number_format($data['net_flow'], 2) ?>
                        </div>
                    </div>
                    <div class="col-md-4 border-start border-end">
                        <div class="text-muted small mb-1">Opening Balance</div>
                        <div class="fw-bold fs-5"><?= $currency ?> <?= number_format($opening_balance, 2) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small mb-1">Closing Balance</div>
                        <div class="fw-bold fs-5 <?= $closing_balance >= 0 ? 'text-primary' : 'text-danger' ?>">
                            <?= $currency ?> <?= number_format($closing_balance, 2) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>@media print { #sidebar, #topbar, form, .btn, nav[aria-label="breadcrumb"] { display:none!important; } #main-wrapper { margin-left:0!important; } }</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
