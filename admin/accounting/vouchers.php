<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting');
require_once __DIR__ . '/helpers.php';

$page_title = 'Vouchers';
$currency   = acc_currency();

// ── Filters ───────────────────────────────────────────────────────────────────
$search    = trim($_GET['search']   ?? '');
$f_type    = $_GET['type']          ?? '';
$f_status  = $_GET['status']        ?? '';
$f_from    = $_GET['date_from']     ?? '';
$f_to      = $_GET['date_to']       ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 20;

$valid_types   = ['receipt','payment','contra','journal'];
$valid_statuses = ['posted','reversed'];

$where  = ['v.is_deleted = 0'];
$params = [];

if ($search !== '') {
    $like    = '%' . $search . '%';
    $where[] = '(v.voucher_number LIKE ? OR v.narration LIKE ? OR v.reference LIKE ?)';
    array_push($params, $like, $like, $like);
}
if (in_array($f_type,   $valid_types,    true)) { $where[] = 'v.voucher_type = ?'; $params[] = $f_type; }
if (in_array($f_status, $valid_statuses, true)) { $where[] = 'v.status = ?';       $params[] = $f_status; }
if ($f_from) { $where[] = 'v.voucher_date >= ?'; $params[] = $f_from; }
if ($f_to)   { $where[] = 'v.voucher_date <= ?'; $params[] = $f_to; }

$where_sql = 'WHERE ' . implode(' AND ', $where);

$count_stmt = db()->prepare("SELECT COUNT(*) FROM acc_vouchers v $where_sql");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = db()->prepare(
    "SELECT v.*, u.full_name AS created_by_name
     FROM acc_vouchers v
     LEFT JOIN users u ON u.id = v.created_by
     $where_sql
     ORDER BY v.created_at DESC
     LIMIT $per_page OFFSET $offset"
);
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

$filter_qs = http_build_query(array_filter([
    'search'    => $search,
    'type'      => $f_type,
    'status'    => $f_status,
    'date_from' => $f_from,
    'date_to'   => $f_to,
]));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-file-invoice me-2 text-primary"></i>Vouchers</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">All Vouchers</li>
        </ol></nav>
    </div>
    <?php if (acc_can_create()): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/accounting/collect-payment.php" class="btn btn-success btn-sm"><i class="fas fa-hand-holding-usd me-1"></i> Collect Payment</a>
        <a href="<?= APP_URL ?>/accounting/add-expense.php"     class="btn btn-danger btn-sm"><i class="fas fa-receipt me-1"></i> Add Expense</a>
        <a href="<?= APP_URL ?>/accounting/transfer-money.php"  class="btn btn-info btn-sm text-white"><i class="fas fa-exchange-alt me-1"></i> Transfer</a>
    </div>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<!-- Filter tabs by type -->
<ul class="nav nav-tabs mb-0" style="border-bottom:none">
    <?php
    $tabs = ['' => 'All'] + array_combine($valid_types, ['Receipt','Payment','Transfer','Journal']);
    foreach ($tabs as $tv => $tl):
        $q = http_build_query(array_filter(array_merge(['search'=>$search,'status'=>$f_status,'date_from'=>$f_from,'date_to'=>$f_to],['type'=>$tv])));
    ?>
    <li class="nav-item">
        <a class="nav-link <?= $f_type === $tv ? 'active' : '' ?>" href="?<?= $q ?>"><?= h($tl) ?></a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="card border-0 shadow-sm" style="border-top-left-radius:0">
    <div class="card-body">
        <!-- Filters -->
        <form method="get" class="row g-2 mb-3">
            <input type="hidden" name="type" value="<?= h($f_type) ?>">
            <div class="col-12 col-md-3">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Voucher #, narration, reference…" value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="posted"   <?= $f_status === 'posted'   ? 'selected' : '' ?>>Posted</option>
                    <option value="reversed" <?= $f_status === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= h($f_from) ?>" placeholder="From date">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= h($f_to) ?>" placeholder="To date">
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/accounting/vouchers.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>

        <?php if (empty($vouchers)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-file-invoice fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No vouchers found.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Voucher #</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Narration / Reference</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vouchers as $v): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $v['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= h($v['voucher_number']) ?>
                            </a>
                            <?php if ($v['reversal_of']): ?>
                            <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">REV</span>
                            <?php endif; ?>
                        </td>
                        <td><?= acc_voucher_type_badge($v['voucher_type']) ?></td>
                        <td class="text-muted small"><?= date('d M Y', strtotime($v['voucher_date'])) ?></td>
                        <td>
                            <div class="small"><?= h($v['narration'] ?? '–') ?></div>
                            <?php if ($v['reference']): ?><div class="text-muted" style="font-size:.72rem"><?= h($v['reference']) ?></div><?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold"><?= $currency ?> <?= number_format($v['total_amount'], 2) ?></td>
                        <td><?= acc_voucher_status_badge($v['status']) ?></td>
                        <td class="text-muted small"><?= h($v['created_by_name'] ?? '–') ?></td>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $v['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="View"><i class="fas fa-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= $filter_qs ?>&page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <p class="text-center text-muted small mt-2">Showing <?= count($vouchers) ?> of <?= number_format($total_rows) ?> voucher(s)</p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
