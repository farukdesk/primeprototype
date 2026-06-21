<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting');
require_once __DIR__ . '/helpers.php';

if (!acc_can_request_voucher_delete()) {
    flash_set('error', 'You do not have permission to delete vouchers.');
    redirect(APP_URL . '/accounting/vouchers.php');
}

$id      = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$voucher = acc_get_voucher($id);
if (!$voucher) {
    flash_set('error', 'Voucher not found or already deleted.');
    redirect(APP_URL . '/accounting/vouchers.php');
}

$is_super = acc_can_delete_voucher_directly();
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $reason = trim($_POST['reason'] ?? '');

    if (strlen($reason) < 10) {
        $errors[] = 'Please provide a detailed reason (minimum 10 characters).';
    }
    if (acc_voucher_has_open_delete_request($id)) {
        $errors[] = 'A delete request for this voucher is already in progress.';
    }

    $attachment = null;
    if (empty($errors) && !empty($_FILES['attachment']['name'])) {
        try {
            $attachment = acc_store_delete_attachment($_FILES['attachment']);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            if ($is_super) {
                acc_direct_delete_voucher($id, $reason, $attachment);
                flash_set('success', 'Voucher ' . h($voucher['voucher_number']) . ' has been deleted and logged.');
                redirect(APP_URL . '/accounting/vouchers.php');
            } else {
                $req_id = acc_create_delete_request($id, $reason, $attachment);
                flash_set('success', 'Delete request submitted for approval (DD Accounts → Treasurer).');
                redirect(APP_URL . '/accounting/voucher-delete-requests.php?id=' . $req_id);
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$items      = acc_get_voucher_items($id);
$currency   = acc_currency();
$page_title = 'Delete Voucher: ' . $voucher['voucher_number'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-trash-alt me-2 text-danger"></i>Delete Voucher</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $id ?>">Voucher</a></li>
            <li class="breadcrumb-item active">Delete</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="alert alert-<?= $is_super ? 'danger' : 'warning' ?>">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php if ($is_super): ?>
            <strong>Immediate deletion.</strong> As a Super Administrator you can delete this voucher right away.
            The whole entry and all its calculations will be cleared from every report and balance. This action is logged permanently and cannot be undone.
            <?php else: ?>
            <strong>Delete request.</strong> Your request will be <em>pending</em> and must be reviewed by
            <strong>DD Accounts</strong> and then confirmed by the <strong>Treasurer</strong> before the voucher is deleted.
            <?php endif; ?>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header py-2 px-4"><strong class="small">Voucher: <?= h($voucher['voucher_number']) ?> — <?= $currency ?> <?= number_format($voucher['total_amount'], 2) ?></strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 small">
                        <thead class="table-light">
                            <tr><th>Account</th><th class="text-end text-success">Debit</th><th class="text-end text-danger">Credit</th></tr>
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

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason for Deletion <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="4" required minlength="10"
                                  placeholder="Explain in detail why this voucher must be deleted…"><?= h($_POST['reason'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Attachment <span class="text-muted small">(optional)</span></label>
                        <input type="file" name="attachment" class="form-control"
                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                        <div class="form-text">Allowed: pdf, jpg, png, doc, docx, xls, xlsx (max 5 MB).</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-<?= $is_super ? 'danger' : 'warning' ?>"
                                onclick="return confirm(<?= $is_super ? "'Permanently delete this voucher? This cannot be undone.'" : "'Submit this delete request?'" ?>)">
                            <i class="fas fa-<?= $is_super ? 'trash-alt' : 'paper-plane' ?> me-1"></i>
                            <?= $is_super ? 'Delete Voucher' : 'Submit Delete Request' ?>
                        </button>
                        <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
