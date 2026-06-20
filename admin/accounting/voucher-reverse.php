<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';

$id      = (int)($_GET['id'] ?? 0);
$voucher = acc_get_voucher($id);

if (!$voucher) {
    flash_set('error', 'Voucher not found.');
    redirect(APP_URL . '/accounting/vouchers.php');
}

if ($voucher['status'] !== 'posted') {
    flash_set('error', 'Only posted vouchers can be reversed.');
    redirect(APP_URL . '/accounting/voucher-view.php?id=' . $id);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $reason = trim($_POST['reason'] ?? '');

    if (strlen($reason) < 5) {
        $errors[] = 'Please provide a reason for reversal (minimum 5 characters).';
    }

    if (empty($errors)) {
        try {
            $reversal_id = acc_reverse_voucher($id, $reason);
            flash_set('success', 'Voucher reversed successfully. <a href="' . APP_URL . '/accounting/voucher-view.php?id=' . $reversal_id . '" class="alert-link">View Reversal Voucher</a>');
            redirect(APP_URL . '/accounting/voucher-view.php?id=' . $id);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$items    = acc_get_voucher_items($id);
$currency = acc_currency();
$page_title = 'Reverse Voucher: ' . $voucher['voucher_number'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-undo me-2 text-warning"></i>Reverse Voucher</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $id ?>">Voucher</a></li>
            <li class="breadcrumb-item active">Reverse</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Important:</strong> You are about to reverse voucher <strong><?= h($voucher['voucher_number']) ?></strong>.
            A new mirror-image voucher will be created with all debits and credits swapped.
            The original voucher will be marked as <em>Reversed</em> and <strong>cannot be edited</strong>.
        </div>

        <!-- Original voucher summary -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header py-2 px-4"><strong class="small">Original Voucher: <?= h($voucher['voucher_number']) ?></strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Account</th>
                                <th class="text-end text-success">Debit</th>
                                <th class="text-end text-danger">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['code'] . ' – ' . $item['account_name']) ?></td>
                                <td class="text-end text-success"><?= $item['debit_amount'] > 0 ? $currency . ' ' . number_format($item['debit_amount'], 2) : '–' ?></td>
                                <td class="text-end text-danger"><?= $item['credit_amount'] > 0 ? $currency . ' ' . number_format($item['credit_amount'], 2) : '–' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason for Reversal <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required minlength="5"
                                  placeholder="Explain why this voucher is being reversed…"><?= old('reason') ?></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning"
                                onclick="return confirm('Confirm reversal of <?= h($voucher['voucher_number']) ?>? This cannot be undone.')">
                            <i class="fas fa-undo me-1"></i> Confirm Reversal
                        </button>
                        <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
