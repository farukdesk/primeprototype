<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Balance Sheet';
$currency   = acc_currency();

$as_of  = $_GET['as_of'] ?? date('Y-m-d');
$data   = acc_balance_sheet($as_of ?: null);

// Net income from all time up to as_of for retained earnings
$is_data = acc_income_statement(null, $as_of ?: null);
$net_income = $is_data['total_revenue'] - $is_data['total_expenses'];
$total_equity_with_ni = $data['total_equity'] + $net_income;
$balanced = round($data['total_assets'], 2) === round($data['total_liabilities'] + $total_equity_with_ni, 2);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-building me-2 text-primary"></i>Balance Sheet</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Balance Sheet</li>
        </ol></nav>
    </div>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i> Print</button>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">As of Date</label>
                <input type="date" name="as_of" class="form-control form-control-sm" value="<?= h($as_of) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync me-1"></i> Generate</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <!-- Left: Assets -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header py-2 px-4" style="background:#dbeafe">
                <strong class="text-primary"><i class="fas fa-building me-1"></i>Assets</strong>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Account</th><th class="text-end">Amount (<?= $currency ?>)</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['assets'] as $a): if ($a['balance'] == 0) continue; ?>
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark border me-1"><?= h($a['code']) ?></span>
                                <?= h($a['name']) ?>
                            </td>
                            <td class="text-end fw-semibold"><?= number_format($a['balance'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-primary">
                        <tr>
                            <td class="fw-bold">Total Assets</td>
                            <td class="text-end fw-bold"><?= number_format($data['total_assets'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Right: Liabilities + Equity -->
    <div class="col-md-6">
        <!-- Liabilities -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header py-2 px-4" style="background:#fee2e2">
                <strong class="text-danger"><i class="fas fa-hand-holding me-1"></i>Liabilities</strong>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Account</th><th class="text-end">Amount (<?= $currency ?>)</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['liabilities'] as $a): if ($a['balance'] == 0) continue; ?>
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark border me-1"><?= h($a['code']) ?></span>
                                <?= h($a['name']) ?>
                            </td>
                            <td class="text-end fw-semibold text-danger"><?= number_format($a['balance'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-danger">
                        <tr>
                            <td class="fw-bold">Total Liabilities</td>
                            <td class="text-end fw-bold text-danger"><?= number_format($data['total_liabilities'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Equity -->
        <div class="card border-0 shadow-sm">
            <div class="card-header py-2 px-4" style="background:#d1fae5">
                <strong class="text-success"><i class="fas fa-university me-1"></i>Equity</strong>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Account</th><th class="text-end">Amount (<?= $currency ?>)</th></tr></thead>
                    <tbody>
                        <?php foreach ($data['equity'] as $a): ?>
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark border me-1"><?= h($a['code']) ?></span>
                                <?= h($a['name']) ?>
                            </td>
                            <td class="text-end fw-semibold text-success"><?= number_format($a['balance'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border me-1">NET</span> Net Income / (Loss)</td>
                            <td class="text-end fw-semibold <?= $net_income >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($net_income, 2) ?></td>
                        </tr>
                    </tbody>
                    <tfoot class="table-success">
                        <tr>
                            <td class="fw-bold">Total Equity</td>
                            <td class="text-end fw-bold text-success"><?= number_format($total_equity_with_ni, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Balance check -->
    <div class="col-12">
        <div class="card border-0 shadow-sm <?= $balanced ? '' : 'border-danger' ?>">
            <div class="card-body py-3 px-4">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <div class="text-muted small">Total Assets</div>
                        <div class="fw-bold fs-5"><?= $currency ?> <?= number_format($data['total_assets'], 2) ?></div>
                    </div>
                    <div class="col-md-4 text-center">
                        <span class="<?= $balanced ? 'text-success' : 'text-danger' ?> fw-semibold">
                            <i class="fas fa-<?= $balanced ? 'check-circle' : 'exclamation-triangle' ?> me-1"></i>
                            <?= $balanced ? 'Balanced' : 'Unbalanced' ?>
                        </span>
                        <div class="text-muted small">Assets = Liabilities + Equity</div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="text-muted small">Liabilities + Equity</div>
                        <div class="fw-bold fs-5"><?= $currency ?> <?= number_format($data['total_liabilities'] + $total_equity_with_ni, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>@media print { #sidebar, #topbar, form, .btn, nav[aria-label="breadcrumb"] { display:none!important; } #main-wrapper { margin-left:0!important; } }</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
