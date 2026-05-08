<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Staff Collection Report';
$currency   = acc_currency();

$date_from  = trim($_GET['date_from'] ?? date('Y-m-01'));
$date_to    = trim($_GET['date_to']   ?? date('Y-m-d'));
$fee_type   = trim($_GET['fee_type']  ?? '');
$pay_method = trim($_GET['payment_method'] ?? '');
$staff_id   = (int)($_GET['staff_id'] ?? 0);

// ── Build query ───────────────────────────────────────────────────────────────

$where  = ['v.status = \'posted\'', 'v.is_deleted = 0'];
$params = [];

if ($date_from) {
    $where[]  = 'DATE(v.voucher_date) >= ?';
    $params[] = $date_from;
}
if ($date_to) {
    $where[]  = 'DATE(v.voucher_date) <= ?';
    $params[] = $date_to;
}
if ($fee_type) {
    $where[]  = 'p.fee_type = ?';
    $params[] = $fee_type;
}
if ($pay_method) {
    $where[]  = 'p.payment_method = ?';
    $params[] = $pay_method;
}
if ($staff_id) {
    $where[]  = 'p.collected_by = ?';
    $params[] = $staff_id;
}

$where_sql = implode(' AND ', $where);

// ── Detailed rows ──────────────────────────────────────────────────────────

$detail_rows = db()->prepare(
    "SELECT
         COALESCE(u.full_name, 'Unknown / System') AS staff_name,
         p.collected_by,
         p.fee_type,
         p.payment_method,
         p.mobile_banking_provider,
         p.amount
     FROM sfp_payments p
     JOIN acc_vouchers v ON v.id = p.voucher_id
     LEFT JOIN users   u ON u.id = p.collected_by
     WHERE $where_sql
     ORDER BY staff_name ASC, p.fee_type ASC, p.payment_method ASC"
);
$detail_rows->execute($params);
$detail_rows = $detail_rows->fetchAll();

// ── Summary: group by staff → fee_type → payment_method ────────────────────

$summary = [];
foreach ($detail_rows as $r) {
    $sname = $r['staff_name'];
    $ft    = $r['fee_type'];
    $pm    = $r['payment_method'];
    $mbp   = $r['mobile_banking_provider'];

    if (!isset($summary[$sname])) {
        $summary[$sname] = ['total' => 0.0, 'breakdown' => []];
    }
    $pm_key = $pm . ($mbp ? '_' . $mbp : '');
    if (!isset($summary[$sname]['breakdown'][$ft][$pm_key])) {
        $summary[$sname]['breakdown'][$ft][$pm_key] = [
            'payment_method'          => $pm,
            'mobile_banking_provider' => $mbp,
            'amount'                  => 0.0,
        ];
    }
    $summary[$sname]['breakdown'][$ft][$pm_key]['amount'] += (float)$r['amount'];
    $summary[$sname]['total'] += (float)$r['amount'];
}

$grand_total = array_sum(array_column($summary, 'total'));

// ── Staff list for filter dropdown ─────────────────────────────────────────

$staff_list = db()->query(
    "SELECT DISTINCT u.id, u.full_name
     FROM sfp_payments p
     JOIN users u ON u.id = p.collected_by
     ORDER BY u.full_name ASC"
)->fetchAll();

$fee_types   = acc_student_fee_types();
$pay_methods = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_banking' => 'Mobile Banking'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-info"></i>Staff Collection Report</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Staff Collection Report</li>
        </ol></nav>
    </div>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="fas fa-print me-1"></i> Print</button>
</div>

<!-- ── Filters ── -->
<div class="card border-0 shadow-sm mb-3 no-print">
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
                <label class="form-label small fw-semibold mb-1">Fee Type</label>
                <select name="fee_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach ($fee_types as $ft): ?>
                    <option value="<?= h($ft) ?>" <?= $fee_type === $ft ? 'selected' : '' ?>><?= h(acc_fee_type_label($ft)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Payment Method</label>
                <select name="payment_method" class="form-select form-select-sm">
                    <option value="">All Methods</option>
                    <?php foreach ($pay_methods as $k => $label): ?>
                    <option value="<?= h($k) ?>" <?= $pay_method === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($staff_list)): ?>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Staff</label>
                <select name="staff_id" class="form-select form-select-sm">
                    <option value="">All Staff</option>
                    <?php foreach ($staff_list as $st): ?>
                    <option value="<?= (int)$st['id'] ?>" <?= $staff_id === (int)$st['id'] ? 'selected' : '' ?>><?= h($st['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync me-1"></i> Generate</button>
                <a href="?" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── Report header (print) ── -->
<div class="d-none d-print-block text-center mb-3">
    <h5 class="fw-bold mb-0">Staff Collection Report</h5>
    <div class="small text-muted">
        <?= $date_from ? date('d M Y', strtotime($date_from)) : 'All time' ?> — <?= $date_to ? date('d M Y', strtotime($date_to)) : 'All time' ?>
        <?php if ($fee_type):   ?> | <?= h(acc_fee_type_label($fee_type)) ?>  <?php endif; ?>
        <?php if ($pay_method): ?> | <?= h($pay_methods[$pay_method] ?? $pay_method) ?> <?php endif; ?>
    </div>
</div>

<!-- ── Summary cards ── -->
<?php if (!empty($summary)): ?>
<div class="row g-3 mb-3">
    <?php foreach ($summary as $sname => $sdata): ?>
    <div class="col-md-4 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="fas fa-user-circle text-info"></i>
                    <span class="fw-semibold small"><?= h($sname) ?></span>
                </div>
                <div class="fw-bold fs-6 text-primary"><?= $currency ?> <?= number_format($sdata['total'], 2) ?></div>
                <div class="text-muted" style="font-size:.72rem">Total Collected</div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Detailed breakdown table ── -->
<div class="card border-0 shadow-sm">
    <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between">
        <strong class="small">
            Breakdown by Staff
            <?php if ($date_from || $date_to): ?>
            &nbsp;·&nbsp;
            <?= $date_from ? date('d M Y', strtotime($date_from)) : 'All time' ?> —
            <?= $date_to ? date('d M Y', strtotime($date_to)) : 'All time' ?>
            <?php endif; ?>
        </strong>
        <span class="fw-bold small"><?= $currency ?> <?= number_format($grand_total, 2) ?> grand total</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($summary)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-search fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No collection records found for the selected filters.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Staff Name</th>
                        <th>Fee Type</th>
                        <th>Payment Method</th>
                        <th class="text-end">Amount (<?= h($currency) ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary as $sname => $sdata):
                        $staff_first = true;
                        $staff_rows  = 0;
                        foreach ($sdata['breakdown'] as $bd_fee_rows) {
                            $staff_rows += count($bd_fee_rows);
                        }
                    ?>
                    <?php foreach ($sdata['breakdown'] as $bd_ft => $bd_methods):
                        $ft_first = true;
                        $ft_rows  = count($bd_methods);
                    ?>
                    <?php foreach ($bd_methods as $bd_pm): ?>
                    <tr>
                        <?php if ($staff_first): ?>
                        <td rowspan="<?= $staff_rows ?>" class="fw-semibold align-middle">
                            <i class="fas fa-user-circle text-info me-1"></i><?= h($sname) ?>
                        </td>
                        <?php $staff_first = false; endif; ?>
                        <?php if ($ft_first): ?>
                        <td rowspan="<?= $ft_rows ?>" class="align-middle"><?= h(acc_fee_type_label($bd_ft)) ?></td>
                        <?php $ft_first = false; endif; ?>
                        <td><?= h(acc_payment_method_label($bd_pm['payment_method'], $bd_pm['mobile_banking_provider'])) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($bd_pm['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                    <!-- Staff sub-total -->
                    <tr class="table-light">
                        <td class="text-end fw-bold" colspan="3">
                            <span class="text-muted small me-2"><?= h($sname) ?> — Sub-total</span>
                        </td>
                        <td class="text-end fw-bold"><?= $currency ?> <?= number_format($sdata['total'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="3" class="text-end fw-bold">Grand Total</td>
                        <td class="text-end fw-bold"><?= $currency ?> <?= number_format($grand_total, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    #sidebar, #topbar, .no-print, nav[aria-label="breadcrumb"] { display: none !important; }
    #main-wrapper { margin-left: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
