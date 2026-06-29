<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Financial Reports';
$currency   = acc_currency();
$db         = db();

// ── Lightweight headline KPIs (current month collections) ──────────────────
$month_start = date('Y-m-01');
$today       = date('Y-m-d');

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(p.amount),0)
     FROM sfp_payments p
     JOIN acc_vouchers v ON v.id = p.voucher_id
     WHERE v.status = 'posted' AND v.is_deleted = 0
       AND DATE(v.voucher_date) BETWEEN ? AND ?"
);
$stmt->execute([$month_start, $today]);
$month_collection = (float)$stmt->fetchColumn();

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(p.amount),0)
     FROM sfp_payments p
     JOIN acc_vouchers v ON v.id = p.voucher_id
     WHERE v.status = 'posted' AND v.is_deleted = 0
       AND DATE(v.voucher_date) = ?"
);
$stmt->execute([$today]);
$today_collection = (float)$stmt->fetchColumn();

// ── Report catalogue ────────────────────────────────────────────────────────
$groups = [
    'Collections & Dues' => [
        ['student-collection.php', 'Student Collection', 'Fees collected from students with charts, filters & invoices', 'file-invoice-dollar', 'primary'],
        ['staff-collection.php',   'Staff Collection',   'Collections grouped by the staff who received them',           'users',              'info'],
        ['due-report.php',         'Due Report',         'Outstanding student dues, semester-wise breakdown',            'exclamation-circle', 'danger'],
    ],
    'Financial Statements' => [
        ['trial-balance.php',    'Trial Balance',    'Debit / credit balances of every ledger account', 'balance-scale', 'secondary'],
        ['income-statement.php', 'Income Statement', 'Revenue vs. expenses for the period',             'chart-bar',     'success'],
        ['balance-sheet.php',    'Balance Sheet',    'Assets, liabilities & equity snapshot',           'building',      'primary'],
        ['cash-flow.php',        'Cash Flow',        'Movement of cash across operating activities',     'water',         'info'],
    ],
    'Books & Ledgers' => [
        ['ledger.php',    'General Ledger', 'Transaction history of a single account', 'book',            'secondary'],
        ['cash-book.php', 'Cash Book',      'All cash receipts & payments',            'money-bill-wave', 'success'],
        ['bank-book.php', 'Bank Book',      'All bank receipts & payments',            'university',      'primary'],
    ],
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-chart-pie me-2 text-primary"></i>Financial Reports</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Reports</li>
        </ol></nav>
    </div>
</div>

<!-- ── Headline KPIs ── -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#0d6efd,#6ea8fe)">
            <div class="card-body p-3 text-white d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 bg-white bg-opacity-25"><i class="fas fa-calendar-day fa-lg"></i></div>
                <div>
                    <div class="small opacity-90">Today's Collection</div>
                    <div class="fw-bold fs-5"><?= $currency ?> <?= number_format($today_collection, 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#16a34a,#4ade80)">
            <div class="card-body p-3 text-white d-flex align-items-center gap-3">
                <div class="rounded-3 p-3 bg-white bg-opacity-25"><i class="fas fa-calendar-alt fa-lg"></i></div>
                <div>
                    <div class="small opacity-90">This Month (<?= date('M Y') ?>)</div>
                    <div class="fw-bold fs-5"><?= $currency ?> <?= number_format($month_collection, 2) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php foreach ($groups as $group_name => $reports): ?>
<h6 class="text-uppercase text-muted fw-semibold small mb-2 mt-4"><?= h($group_name) ?></h6>
<div class="row g-3">
    <?php foreach ($reports as [$file, $title, $desc, $icon, $color]): ?>
    <div class="col-12 col-sm-6 col-xl-4">
        <a href="<?= APP_URL ?>/accounting/reports/<?= h($file) ?>" class="card border-0 shadow-sm h-100 text-decoration-none report-tile">
            <div class="card-body p-3 d-flex align-items-start gap-3">
                <div class="rounded-3 p-3 bg-<?= $color ?> bg-opacity-10 text-<?= $color ?>"><i class="fas fa-<?= $icon ?> fa-lg"></i></div>
                <div>
                    <div class="fw-semibold text-dark"><?= h($title) ?> <i class="fas fa-arrow-right ms-1 small text-muted arrow"></i></div>
                    <div class="text-muted small"><?= h($desc) ?></div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<style>
.report-tile { transition: transform .12s ease, box-shadow .12s ease; }
.report-tile:hover { transform: translateY(-3px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.12) !important; }
.report-tile .arrow { opacity: 0; transition: opacity .12s ease, transform .12s ease; }
.report-tile:hover .arrow { opacity: 1; transform: translateX(3px); }
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
