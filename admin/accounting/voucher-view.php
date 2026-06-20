<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting');
require_once __DIR__ . '/helpers.php';

$id      = (int)($_GET['id'] ?? 0);
$voucher = acc_get_voucher($id);
if (!$voucher) {
    flash_set('error', 'Voucher not found.');
    redirect(APP_URL . '/accounting/vouchers.php');
}

$items    = acc_get_voucher_items($id);
$currency = acc_currency();
$page_title = 'Voucher: ' . $voucher['voucher_number'];

// Original voucher link (if this is a reversal)
$original = null;
if ($voucher['reversal_of']) {
    $stmt = db()->prepare('SELECT id, voucher_number FROM acc_vouchers WHERE id = ?');
    $stmt->execute([$voucher['reversal_of']]);
    $original = $stmt->fetch() ?: null;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-file-invoice me-2 text-primary"></i><?= h($voucher['voucher_number']) ?></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/vouchers.php">Vouchers</a></li>
            <li class="breadcrumb-item active"><?= h($voucher['voucher_number']) ?></li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($voucher['status'] === 'posted' && acc_can_create()): ?>
        <a href="<?= APP_URL ?>/accounting/voucher-reverse.php?id=<?= $voucher['id'] ?>"
           class="btn btn-warning btn-sm"
           onclick="return confirm('Are you sure you want to reverse this voucher? A mirror-image reversal entry will be created.')">
            <i class="fas fa-undo me-1"></i> Reverse Voucher
        </a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i> Print</button>
        <a href="<?= APP_URL ?>/accounting/vouchers.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>
</div>

<?= flash_show() ?>

<div class="row g-3">
    <!-- Voucher Header -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <td class="text-muted small fw-semibold" style="width:140px">Voucher Number</td>
                                <td class="fw-bold"><?= h($voucher['voucher_number']) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Voucher Type</td>
                                <td><?= acc_voucher_type_badge($voucher['voucher_type']) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Date</td>
                                <td><?= date('d F Y', strtotime($voucher['voucher_date'])) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Status</td>
                                <td><?= acc_voucher_status_badge($voucher['status']) ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <td class="text-muted small fw-semibold" style="width:140px">Total Amount</td>
                                <td class="fw-bold fs-5 text-primary"><?= $currency ?> <?= number_format($voucher['total_amount'], 2) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Reference</td>
                                <td><?= h($voucher['reference'] ?? '–') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Created By</td>
                                <td><?= h($voucher['created_by_name'] ?? '–') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Created At</td>
                                <td class="small text-muted"><?= date('d M Y, h:i A', strtotime($voucher['created_at'])) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php if ($voucher['narration']): ?>
                <div class="mt-3 p-3 rounded-3" style="background:#f8f9fb">
                    <span class="text-muted small fw-semibold">Narration: </span>
                    <?= h($voucher['narration']) ?>
                </div>
                <?php endif; ?>

                <?php if ($original): ?>
                <div class="alert alert-warning mt-3 mb-0 small">
                    <i class="fas fa-undo me-1"></i> This is a reversal of voucher
                    <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $original['id'] ?>" class="alert-link fw-bold"><?= h($original['voucher_number']) ?></a>
                </div>
                <?php endif; ?>

                <?php if ($voucher['status'] === 'reversed'): ?>
                <div class="alert alert-warning mt-3 mb-0 small">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    This voucher has been <strong>reversed</strong> by <?= h($voucher['reversed_by_name'] ?? 'N/A') ?>
                    on <?= $voucher['reversed_at'] ? date('d M Y', strtotime($voucher['reversed_at'])) : 'N/A' ?>.
                    <?php if ($voucher['reversal_voucher_number']): ?>
                    Reversal voucher: <strong><?= h($voucher['reversal_voucher_number']) ?></strong>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Voucher Line Items (Journal Entries) -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header py-2 px-4">
                <strong class="small">Journal Entries (Double-Entry Ledger)</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:120px">Account Code</th>
                                <th>Account Name</th>
                                <th>Description</th>
                                <th class="text-end text-success">Debit (<?= $currency ?>)</th>
                                <th class="text-end text-danger">Credit (<?= $currency ?>)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total_debit  = 0;
                            $total_credit = 0;
                            foreach ($items as $item):
                                $total_debit  += (float)$item['debit_amount'];
                                $total_credit += (float)$item['credit_amount'];
                            ?>
                            <tr>
                                <td><span class="badge bg-light text-dark border"><?= h($item['code']) ?></span></td>
                                <td>
                                    <div class="fw-semibold small"><?= h($item['account_name']) ?></div>
                                    <div class="text-muted" style="font-size:.72rem"><?= ucfirst($item['account_type']) ?></div>
                                </td>
                                <td class="small text-muted"><?= h($item['description'] ?? '–') ?></td>
                                <td class="text-end fw-semibold text-success">
                                    <?= $item['debit_amount'] > 0 ? number_format($item['debit_amount'], 2) : '–' ?>
                                </td>
                                <td class="text-end fw-semibold text-danger">
                                    <?= $item['credit_amount'] > 0 ? number_format($item['credit_amount'], 2) : '–' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3" class="text-end">Total</th>
                                <th class="text-end text-success"><?= number_format($total_debit, 2) ?></th>
                                <th class="text-end text-danger"><?= number_format($total_credit, 2) ?></th>
                            </tr>
                            <tr>
                                <td colspan="5" class="text-center small">
                                    <?php if (round($total_debit, 2) === round($total_credit, 2)): ?>
                                    <span class="text-success"><i class="fas fa-check-circle me-1"></i> Balanced — Total Debit = Total Credit</span>
                                    <?php else: ?>
                                    <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i> UNBALANCED</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    #sidebar, #topbar, .btn, nav[aria-label="breadcrumb"] { display: none !important; }
    #main-wrapper { margin-left: 0 !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
