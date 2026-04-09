<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Trial Balance';
$currency   = acc_currency();

$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

$rows      = acc_trial_balance($date_from ?: null, $date_to ?: null);
$total_d   = array_sum(array_column($rows, 'balance_debit'));
$total_c   = array_sum(array_column($rows, 'balance_credit'));
$balanced  = round($total_d, 2) === round($total_c, 2);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-balance-scale me-2 text-primary"></i>Trial Balance</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Trial Balance</li>
        </ol></nav>
    </div>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i> Print</button>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">From Date</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($date_from) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($date_to) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync me-1"></i> Generate</button>
                <a href="?" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if (!$balanced): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i> <strong>Warning:</strong> Trial balance is not balanced. Check for data integrity issues.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header py-2 px-4 d-flex justify-content-between align-items-center">
        <strong>Trial Balance <?= $date_from ? ('from ' . date('d M Y', strtotime($date_from))) : '' ?> to <?= date('d M Y', strtotime($date_to)) ?></strong>
        <?php if ($balanced): ?>
        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Balanced</span>
        <?php else: ?>
        <span class="badge bg-danger">Unbalanced</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Account Name</th>
                        <th>Type</th>
                        <th class="text-end text-success">Debit (<?= $currency ?>)</th>
                        <th class="text-end text-danger">Credit (<?= $currency ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $type_order = ['asset','liability','equity','income','expense'];
                    $type_labels = ['asset'=>'Assets','liability'=>'Liabilities','equity'=>'Equity','income'=>'Income','expense'=>'Expenses'];
                    $by_type = [];
                    foreach ($rows as $r) $by_type[$r['type']][] = $r;

                    foreach ($type_order as $type):
                        if (empty($by_type[$type])) continue;
                        $type_d = array_sum(array_column($by_type[$type], 'balance_debit'));
                        $type_c = array_sum(array_column($by_type[$type], 'balance_credit'));
                    ?>
                    <tr class="table-light">
                        <td colspan="5" class="fw-bold small text-uppercase text-muted"><?= $type_labels[$type] ?></td>
                    </tr>
                    <?php foreach ($by_type[$type] as $row):
                        if ($row['balance_debit'] == 0 && $row['balance_credit'] == 0) continue; ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border"><?= h($row['code']) ?></span></td>
                        <td><?= h($row['name']) ?></td>
                        <td class="text-muted small"><?= ucfirst($row['type']) ?></td>
                        <td class="text-end <?= $row['balance_debit'] > 0 ? 'text-success fw-semibold' : 'text-muted' ?>">
                            <?= $row['balance_debit'] > 0 ? number_format($row['balance_debit'], 2) : '–' ?>
                        </td>
                        <td class="text-end <?= $row['balance_credit'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
                            <?= $row['balance_credit'] > 0 ? number_format($row['balance_credit'], 2) : '–' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="fw-semibold" style="background:#f8f9fb">
                        <td colspan="3" class="text-end small">Subtotal <?= $type_labels[$type] ?></td>
                        <td class="text-end text-success"><?= number_format($type_d, 2) ?></td>
                        <td class="text-end text-danger"><?= number_format($type_c, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="3" class="fw-bold text-end">TOTAL</td>
                        <td class="text-end fw-bold"><?= number_format($total_d, 2) ?></td>
                        <td class="text-end fw-bold"><?= number_format($total_c, 2) ?></td>
                    </tr>
                    <tr>
                        <td colspan="5" class="text-center small">
                            <?php if ($balanced): ?>
                            <span class="text-success"><i class="fas fa-check-circle me-1"></i>Trial balance is balanced</span>
                            <?php else: ?>
                            <span class="text-danger"><i class="fas fa-times-circle me-1"></i>Difference: <?= $currency ?> <?= number_format(abs($total_d - $total_c), 2) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<style>@media print { #sidebar, #topbar, form, .btn, nav[aria-label="breadcrumb"] { display:none!important; } #main-wrapper { margin-left:0!important; } }</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
