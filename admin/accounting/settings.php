<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';

$page_title = 'Accounting Settings';
$errors = [];

// Get all cash/bank accounts for default selection
$asset_accounts = acc_accounts_by_type('asset');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $settings_to_save = [
        'fiscal_year_start'    => trim($_POST['fiscal_year_start']    ?? '07-01'),
        'default_cash_account' => trim($_POST['default_cash_account'] ?? '1100'),
        'default_bank_account' => trim($_POST['default_bank_account'] ?? '1200'),
        'currency_symbol'      => trim($_POST['currency_symbol']      ?? '৳'),
        'currency_code'        => strtoupper(trim($_POST['currency_code'] ?? 'BDT')),
    ];

    foreach ($settings_to_save as $key => $value) {
        acc_save_setting($key, $value);
    }

    log_change('accounting', 'UPDATE', null, 'Settings', null, null, null, 'Accounting settings updated');
    flash_set('success', 'Settings saved successfully.');
    redirect(APP_URL . '/accounting/settings.php');
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-cog me-2"></i>Accounting Settings</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?= flash_show() ?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="post">
                    <?= csrf_field() ?>

                    <h6 class="fw-bold mb-3 text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.06em">Currency</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Currency Symbol</label>
                            <input type="text" name="currency_symbol" class="form-control"
                                   value="<?= h(acc_setting('currency_symbol', '৳')) ?>" maxlength="5" required>
                            <div class="form-text">e.g., ৳, $, €, £</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Currency Code</label>
                            <input type="text" name="currency_code" class="form-control"
                                   value="<?= h(acc_setting('currency_code', 'BDT')) ?>" maxlength="3" required>
                            <div class="form-text">e.g., BDT, USD, EUR</div>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3 text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.06em">Fiscal Year</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Fiscal Year Start (MM-DD)</label>
                            <input type="text" name="fiscal_year_start" class="form-control"
                                   value="<?= h(acc_setting('fiscal_year_start', '07-01')) ?>"
                                   placeholder="07-01" pattern="\d{2}-\d{2}" required>
                            <div class="form-text">Format: MM-DD (e.g., 07-01 for July 1)</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Current Fiscal Year</label>
                            <div class="form-control-plaintext text-muted small">
                                <?= date('d M Y', strtotime(acc_fiscal_year_start())) ?> –
                                <?= date('d M Y', strtotime(acc_fiscal_year_end())) ?>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3 text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.06em">Default Accounts</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Default Cash Account</label>
                            <select name="default_cash_account" class="form-select">
                                <?php foreach ($asset_accounts as $a): ?>
                                <option value="<?= h($a['code']) ?>"
                                    <?= acc_setting('default_cash_account') === $a['code'] ? 'selected' : '' ?>>
                                    <?= h($a['code'] . ' – ' . $a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Pre-selected in Collect Payment form</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Default Bank Account</label>
                            <select name="default_bank_account" class="form-select">
                                <?php foreach ($asset_accounts as $a): ?>
                                <option value="<?= h($a['code']) ?>"
                                    <?= acc_setting('default_bank_account') === $a['code'] ? 'selected' : '' ?>>
                                    <?= h($a['code'] . ' – ' . $a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Pre-selected in Transfer Money form</div>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3 text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.06em">Voucher Numbering</h6>
                    <div class="alert alert-light border small">
                        <i class="fas fa-info-circle text-primary me-1"></i>
                        Voucher numbers are auto-generated. Current next numbers:
                        <ul class="mb-0 mt-1">
                            <li>Receipt: <strong>RV-<?= date('Y') ?>-<?= str_pad(acc_setting('next_receipt_number', '1'), 5, '0', STR_PAD_LEFT) ?></strong></li>
                            <li>Payment: <strong>PV-<?= date('Y') ?>-<?= str_pad(acc_setting('next_payment_number', '1'), 5, '0', STR_PAD_LEFT) ?></strong></li>
                            <li>Transfer: <strong>CV-<?= date('Y') ?>-<?= str_pad(acc_setting('next_contra_number', '1'), 5, '0', STR_PAD_LEFT) ?></strong></li>
                            <li>Journal: <strong>JV-<?= date('Y') ?>-<?= str_pad(acc_setting('next_journal_number', '1'), 5, '0', STR_PAD_LEFT) ?></strong></li>
                        </ul>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Settings</button>
                        <a href="<?= APP_URL ?>/accounting/index.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
