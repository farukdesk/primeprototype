<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Cash Book';
$currency   = acc_currency();

// Find all cash accounts (petty cash, etc.) – code 11xx
$cash_accounts_list = db()->query(
    "SELECT * FROM acc_accounts WHERE type = 'asset' AND code LIKE '11%' AND is_active = 1 ORDER BY code ASC"
)->fetchAll();

$account_id = (int)($_GET['account_id'] ?? ($cash_accounts_list[0]['id'] ?? 0));
$date_from  = $_GET['date_from'] ?? date('Y-m-01');
$date_to    = $_GET['date_to']   ?? date('Y-m-d');

$account = $account_id ? acc_get_account($account_id) : null;
$entries  = $account ? acc_ledger_entries($account_id, $date_from ?: null, $date_to ?: null) : [];

// Opening balance before date_from
$opening = $account ? (float)$account['opening_balance'] : 0.0;
if ($account && $date_from) {
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(vi.debit_amount),0) AS d, COALESCE(SUM(vi.credit_amount),0) AS c
         FROM acc_voucher_items vi
         JOIN acc_vouchers v ON v.id = vi.voucher_id
         WHERE vi.account_id = ? AND v.status = 'posted' AND v.is_deleted = 0 AND v.voucher_date < ?"
    );
    $stmt->execute([$account_id, $date_from]);
    $r = $stmt->fetch();
    $opening += (float)$r['d'] - (float)$r['c'];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-money-bill-wave me-2 text-success"></i>Cash Book</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Cash Book</li>
        </ol></nav>
    </div>
    <?php if ($account): ?>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i> Print</button>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Cash Account</label>
                <select name="account_id" class="form-select form-select-sm">
                    <?php foreach ($cash_accounts_list as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $account_id == $a['id'] ? 'selected' : '' ?>>
                        <?= h($a['code'] . ' – ' . $a['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
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
            </div>
        </form>
    </div>
</div>

<?php if ($account): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header py-2 px-4">
        <strong>Cash Book — <?= h($account['name']) ?></strong>
        <span class="text-muted small ms-2"><?= $date_from ? date('d M Y', strtotime($date_from)) : 'All time' ?> to <?= date('d M Y', strtotime($date_to)) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Voucher #</th>
                        <th>Description / Narration</th>
                        <th class="text-end text-success">Cash In</th>
                        <th class="text-end text-danger">Cash Out</th>
                        <th class="text-end">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="table-light">
                        <td colspan="5" class="fw-semibold small text-muted">Opening Balance</td>
                        <td class="text-end fw-bold"><?= $currency ?> <?= number_format($opening, 2) ?></td>
                    </tr>
                    <?php
                    $total_in = 0;
                    $total_out = 0;
                    foreach ($entries as $entry):
                        $total_in  += (float)$entry['debit_amount'];
                        $total_out += (float)$entry['credit_amount'];
                    ?>
                    <tr>
                        <td class="text-muted small"><?= date('d M Y', strtotime($entry['voucher_date'])) ?></td>
                        <td><span class="small fw-semibold"><?= h($entry['voucher_number']) ?></span></td>
                        <td class="small"><?= h($entry['description'] ?? $entry['narration'] ?? '–') ?></td>
                        <td class="text-end <?= $entry['debit_amount'] > 0 ? 'text-success fw-semibold' : 'text-muted' ?>">
                            <?= $entry['debit_amount'] > 0 ? number_format($entry['debit_amount'], 2) : '–' ?>
                        </td>
                        <td class="text-end <?= $entry['credit_amount'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
                            <?= $entry['credit_amount'] > 0 ? number_format($entry['credit_amount'], 2) : '–' ?>
                        </td>
                        <td class="text-end fw-semibold"><?= $currency ?> <?= number_format($entry['balance'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($entries)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No cash transactions in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="3" class="fw-bold text-end">Totals / Closing Balance</td>
                        <td class="text-end fw-bold text-success"><?= number_format($total_in, 2) ?></td>
                        <td class="text-end fw-bold text-danger"><?= number_format($total_out, 2) ?></td>
                        <td class="text-end fw-bold"><?= $currency ?> <?= number_format(!empty($entries) ? end($entries)['balance'] : $opening, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-money-bill-wave fa-3x mb-3 opacity-25"></i>
        <p>No cash accounts found. Please add a cash account (code starting with 11) in the Chart of Accounts.</p>
    </div>
</div>
<?php endif; ?>

<style>@media print { #sidebar, #topbar, form, .btn, nav[aria-label="breadcrumb"] { display:none!important; } #main-wrapper { margin-left:0!important; } }</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
