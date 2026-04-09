<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Income Statement';
$currency   = acc_currency();

$fy_start  = acc_fiscal_year_start();
$fy_end    = acc_fiscal_year_end();
$date_from = $_GET['date_from'] ?? $fy_start;
$date_to   = $_GET['date_to']   ?? $fy_end;

$data      = acc_income_statement($date_from ?: null, $date_to ?: null);
$net_income = $data['total_revenue'] - $data['total_expenses'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-chart-bar me-2 text-success"></i>Income Statement (P&amp;L)</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Income Statement</li>
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
    <!-- Revenue -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-4" style="background:#d1fae5">
                <strong class="text-success"><i class="fas fa-arrow-down me-1"></i>Revenue</strong>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Account</th><th class="text-end">Amount (<?= $currency ?>)</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['revenue'] as $r): if ($r['net'] == 0) continue; ?>
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark border me-1"><?= h($r['code']) ?></span>
                                <?= h($r['name']) ?>
                            </td>
                            <td class="text-end fw-semibold text-success"><?= number_format($r['net'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($data['revenue']) || array_sum(array_column($data['revenue'], 'net')) === 0.0): ?>
                        <tr><td colspan="2" class="text-center text-muted small">No revenue recorded</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-success">
                        <tr>
                            <td class="fw-bold">Total Revenue</td>
                            <td class="text-end fw-bold text-success"><?= number_format($data['total_revenue'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Expenses -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-4" style="background:#fee2e2">
                <strong class="text-danger"><i class="fas fa-arrow-up me-1"></i>Expenses</strong>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Account</th><th class="text-end">Amount (<?= $currency ?>)</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['expenses'] as $r): if ($r['net'] == 0) continue; ?>
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark border me-1"><?= h($r['code']) ?></span>
                                <?= h($r['name']) ?>
                            </td>
                            <td class="text-end fw-semibold text-danger"><?= number_format($r['net'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($data['expenses']) || array_sum(array_column($data['expenses'], 'net')) === 0.0): ?>
                        <tr><td colspan="2" class="text-center text-muted small">No expenses recorded</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-danger">
                        <tr>
                            <td class="fw-bold">Total Expenses</td>
                            <td class="text-end fw-bold text-danger"><?= number_format($data['total_expenses'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Net Income Summary -->
    <div class="col-12">
        <div class="card border-0 shadow-sm" style="border-left: 4px solid <?= $net_income >= 0 ? '#22c55e' : '#ef4444' ?> !important">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h5 class="mb-1">Net <?= $net_income >= 0 ? 'Surplus (Income)' : 'Deficit (Loss)' ?></h5>
                        <p class="text-muted mb-0 small">Period: <?= $date_from ? date('d M Y', strtotime($date_from)) : 'All time' ?> – <?= date('d M Y', strtotime($date_to)) ?></p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="fw-bold fs-3 <?= $net_income >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $currency ?> <?= number_format(abs($net_income), 2) ?>
                        </div>
                        <div class="small text-muted"><?= $currency ?> <?= number_format($data['total_revenue'], 2) ?> revenue − <?= $currency ?> <?= number_format($data['total_expenses'], 2) ?> expenses</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>@media print { #sidebar, #topbar, form, .btn, nav[aria-label="breadcrumb"] { display:none!important; } #main-wrapper { margin-left:0!important; } }</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
