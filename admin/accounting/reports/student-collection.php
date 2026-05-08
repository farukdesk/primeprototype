<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Student Collection Report';
$currency   = acc_currency();

$date_from  = trim($_GET['date_from'] ?? date('Y-m-01'));
$date_to    = trim($_GET['date_to']   ?? date('Y-m-d'));
$fee_type   = trim($_GET['fee_type']  ?? '');
$pay_method = trim($_GET['payment_method'] ?? '');

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

$where_sql = implode(' AND ', $where);

$rows = db()->prepare(
    "SELECT
         s.student_id        AS sid,
         s.full_name         AS student_name,
         v.voucher_date      AS collection_date,
         v.voucher_number    AS invoice_no,
         p.fee_type,
         p.payment_method,
         p.mobile_banking_provider,
         p.amount,
         p.id                AS payment_id
     FROM sfp_payments p
     JOIN students       s ON s.id = p.student_id
     JOIN acc_vouchers   v ON v.id = p.voucher_id
     WHERE $where_sql
     ORDER BY v.voucher_date DESC, p.id DESC"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$total = array_sum(array_column($rows, 'amount'));

$fee_types   = acc_student_fee_types();
$pay_methods = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_banking' => 'Mobile Banking'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Student Collection Report</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Student Collection Report</li>
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
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync me-1"></i> Generate</button>
                <a href="?" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── Report header (print) ── -->
<div class="d-none d-print-block text-center mb-3">
    <h5 class="fw-bold mb-0">Student Collection Report</h5>
    <div class="small text-muted">
        <?= $date_from ? date('d M Y', strtotime($date_from)) : 'All time' ?> — <?= $date_to ? date('d M Y', strtotime($date_to)) : 'All time' ?>
        <?php if ($fee_type):   ?> | <?= h(acc_fee_type_label($fee_type)) ?>  <?php endif; ?>
        <?php if ($pay_method): ?> | <?= h($pay_methods[$pay_method] ?? $pay_method) ?> <?php endif; ?>
    </div>
</div>

<!-- ── Table ── -->
<div class="card border-0 shadow-sm">
    <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between">
        <strong class="small">
            <?= count($rows) ?> record(s)
            <?php if ($date_from || $date_to): ?>
            &nbsp;·&nbsp;
            <?= $date_from ? date('d M Y', strtotime($date_from)) : 'All time' ?> —
            <?= $date_to ? date('d M Y', strtotime($date_to)) : 'All time' ?>
            <?php endif; ?>
        </strong>
        <span class="fw-bold small"><?= $currency ?> <?= number_format($total, 2) ?> total</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-search fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No collection records found for the selected filters.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Collection Date</th>
                        <th>Invoice No</th>
                        <th>Fee Type</th>
                        <th>Payment Method</th>
                        <th class="text-end">Amount (<?= h($currency) ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($r['sid']) ?></td>
                        <td><?= h($r['student_name']) ?></td>
                        <td class="text-muted"><?= date('d M Y', strtotime($r['collection_date'])) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= h($r['invoice_no']) ?></span></td>
                        <td><?= h(acc_fee_type_label($r['fee_type'])) ?></td>
                        <td><?= h(acc_payment_method_label($r['payment_method'], $r['mobile_banking_provider'])) ?></td>
                        <td class="text-end fw-semibold"><?= number_format((float)$r['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="7" class="text-end fw-bold">Grand Total</td>
                        <td class="text-end fw-bold"><?= $currency ?> <?= number_format($total, 2) ?></td>
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
