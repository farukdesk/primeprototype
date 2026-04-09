<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title   = 'Collect Payment';
$cash_accounts   = acc_cash_accounts();
$income_accounts = acc_income_accounts();
$default_cash    = acc_setting('default_cash_account', '1100');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $amount          = (float)($_POST['amount'] ?? 0);
    $cash_account_id = (int)($_POST['cash_account_id'] ?? 0);
    $income_account_id = (int)($_POST['income_account_id'] ?? 0);
    $date            = trim($_POST['voucher_date'] ?? date('Y-m-d'));
    $reference       = trim($_POST['reference'] ?? '');
    $narration       = trim($_POST['narration'] ?? '');

    if ($amount <= 0)            $errors[] = 'Amount must be greater than zero.';
    if (!$cash_account_id)       $errors[] = 'Please select the received-into account.';
    if (!$income_account_id)     $errors[] = 'Please select the income type.';
    if (!$date)                  $errors[] = 'Date is required.';
    if ($cash_account_id === $income_account_id) $errors[] = 'Source and destination accounts cannot be the same.';

    if (empty($errors)) {
        try {
            $vid = acc_collect_payment($amount, $cash_account_id, $income_account_id, $date, $reference, $narration);
            flash_set('success', 'Payment collected successfully. <a href="' . APP_URL . '/accounting/voucher-view.php?id=' . $vid . '" class="alert-link">View Voucher</a>');
            redirect(APP_URL . '/accounting/collect-payment.php');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-hand-holding-usd me-2 text-success"></i>Collect Payment</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Collect Payment</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/vouchers.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-list me-1"></i> All Vouchers</a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success p-2"><i class="fas fa-hand-holding-usd"></i></span>
                    <div>
                        <div class="fw-semibold">Receipt Voucher</div>
                        <div class="text-muted small">Records money received (income)</div>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <form method="post">
                    <?= csrf_field() ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                            <input type="date" name="voucher_date" class="form-control"
                                   value="<?= old('voucher_date', date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Amount (<?= acc_currency() ?>) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                                   placeholder="0.00" value="<?= old('amount') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Received Into <span class="text-danger">*</span></label>
                            <select name="cash_account_id" class="form-select" required>
                                <option value="">— Select Account —</option>
                                <?php foreach ($cash_accounts as $a): ?>
                                <option value="<?= $a['id'] ?>"
                                    <?= (old('cash_account_id', '') == $a['id'] || ($a['code'] == $default_cash && !old('cash_account_id'))) ? 'selected' : '' ?>>
                                    <?= h($a['code'] . ' – ' . $a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Cash or bank account that receives the money</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Income Type <span class="text-danger">*</span></label>
                            <select name="income_account_id" class="form-select" required>
                                <option value="">— Select Income —</option>
                                <?php foreach ($income_accounts as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= old('income_account_id') == $a['id'] ? 'selected' : '' ?>>
                                    <?= h($a['code'] . ' – ' . $a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Type of income being received</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Reference / Invoice # <small class="text-muted fw-normal">(optional)</small></label>
                            <input type="text" name="reference" class="form-control"
                                   placeholder="e.g. INV-2025-001, Student ID, Admission Form #"
                                   value="<?= old('reference') ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Description / Narration <small class="text-muted fw-normal">(optional)</small></label>
                            <textarea name="narration" class="form-control" rows="2"
                                      placeholder="e.g. Tuition fee payment – Fall 2025, Student: John Doe"><?= old('narration') ?></textarea>
                        </div>
                    </div>

                    <!-- Double-entry info box -->
                    <div class="alert alert-light border mt-4 small">
                        <i class="fas fa-info-circle text-primary me-1"></i>
                        <strong>Accounting entry:</strong>
                        <span class="text-success">Debit</span> the received-into account &amp;
                        <span class="text-danger">Credit</span> the income type account automatically.
                    </div>

                    <div class="d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i> Post Receipt Voucher</button>
                        <a href="<?= APP_URL ?>/accounting/index.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
