<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Ledger Report';
$currency   = acc_currency();

$account_id = (int)($_GET['account_id'] ?? 0);
$date_from  = $_GET['date_from'] ?? '';
$date_to    = $_GET['date_to']   ?? date('Y-m-d');
$all_accounts = acc_all_active_accounts();

$account = $account_id ? acc_get_account($account_id) : null;
$entries  = [];
$opening  = 0.0;

if ($account) {
    $entries = acc_ledger_entries($account_id, $date_from ?: null, $date_to ?: null);
    // Compute opening balance before date_from
    $opening = (float)$account['opening_balance'];
    if ($date_from) {
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(vi.debit_amount),0) AS d, COALESCE(SUM(vi.credit_amount),0) AS c
             FROM acc_voucher_items vi
             JOIN acc_vouchers v ON v.id = vi.voucher_id
             WHERE vi.account_id = ? AND v.status = 'posted' AND v.is_deleted = 0 AND v.voucher_date < ?"
        );
        $stmt->execute([$account_id, $date_from]);
        $r = $stmt->fetch();
        $is_debit_normal = in_array($account['type'], ['asset','expense'], true);
        $opening += $is_debit_normal ? ((float)$r['d'] - (float)$r['c']) : ((float)$r['c'] - (float)$r['d']);
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-book me-2 text-primary"></i>Ledger Report</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Ledger</li>
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
                <label class="form-label small fw-semibold mb-1">Account <span class="text-danger">*</span></label>
                <select name="account_id" class="form-select form-select-sm" required>
                    <option value="">— Select Account —</option>
                    <?php foreach ($all_accounts as $a): ?>
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
    <div class="card-header py-2 px-4 d-flex justify-content-between align-items-center">
        <strong><?= h($account['code'] . ' – ' . $account['name']) ?></strong>
        <span class="badge bg-secondary"><?= ucfirst($account['type']) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Voucher #</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th class="text-end text-success">Debit</th>
                        <th class="text-end text-danger">Credit</th>
                        <th class="text-end">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Opening balance row -->
                    <tr class="table-light">
                        <td colspan="6" class="fw-semibold small text-muted">Opening Balance</td>
                        <td class="text-end fw-bold"><?= $currency ?> <?= number_format($opening, 2) ?></td>
                    </tr>
                    <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td class="text-muted small"><?= date('d M Y', strtotime($entry['voucher_date'])) ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?php
                                $stmt = db()->prepare('SELECT id FROM acc_vouchers WHERE voucher_number = ?');
                                $stmt->execute([$entry['voucher_number']]);
                                echo (int)$stmt->fetchColumn();
                            ?>" class="text-decoration-none small fw-semibold"><?= h($entry['voucher_number']) ?></a>
                        </td>
                        <td><?= acc_voucher_type_badge($entry['voucher_type']) ?></td>
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
                    <tr><td colspan="7" class="text-center text-muted py-4">No transactions in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($entries)): ?>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="6" class="fw-bold text-end">Closing Balance</td>
                        <td class="text-end fw-bold"><?= $currency ?> <?= number_format(end($entries)['balance'], 2) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-book fa-3x mb-3 opacity-25"></i>
        <p>Select an account above to view its ledger.</p>
    </div>
</div>
<?php endif; ?>

<style>@media print { #sidebar, #topbar, form, .btn, nav[aria-label="breadcrumb"] { display:none!important; } #main-wrapper { margin-left:0!important; } }</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
