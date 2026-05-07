<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';

$page_title = 'Accounting Settings';
$errors = [];

// Get all cash/bank accounts for default selection
$asset_accounts = acc_accounts_by_type('asset');
$asset_codes = array_column($asset_accounts, 'code');
$income_accounts = acc_income_accounts();
$income_codes = array_column($income_accounts, 'code');
$fee_types = acc_student_fee_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $settings_to_save = [
        'fiscal_year_start'    => trim($_POST['fiscal_year_start']    ?? '07-01'),
        'default_cash_account' => trim($_POST['default_cash_account'] ?? '1100'),
        'default_bank_account' => trim($_POST['default_bank_account'] ?? '1200'),
        'received_into_cash_account' => trim($_POST['received_into_cash_account'] ?? acc_setting('default_cash_account', '1100')),
        'received_into_bank_account' => trim($_POST['received_into_bank_account'] ?? acc_setting('default_bank_account', '1200')),
        'received_into_mobile_banking_account' => trim($_POST['received_into_mobile_banking_account'] ?? acc_setting('default_bank_account', '1200')),
        'currency_symbol'      => trim($_POST['currency_symbol']      ?? '৳'),
        'currency_code'        => strtoupper(trim($_POST['currency_code'] ?? 'BDT')),
        'email_invoice'        => isset($_POST['email_invoice'])  ? '1' : '0',
        'sms_enabled'          => isset($_POST['sms_enabled'])    ? '1' : '0',
        'sms_sender_id'        => trim($_POST['sms_sender_id']    ?? ''),
        'sms_template'         => trim($_POST['sms_template']     ?? ''),
    ];

    // Keep the API key unless a new one is submitted (avoid clearing it accidentally)
    $new_api_key = trim($_POST['sms_api_key'] ?? '');
    if ($new_api_key !== '') {
        $settings_to_save['sms_api_key'] = $new_api_key;
    }

    foreach ([
        'default_cash_account',
        'default_bank_account',
        'received_into_cash_account',
        'received_into_bank_account',
        'received_into_mobile_banking_account',
    ] as $asset_key) {
        if (!in_array($settings_to_save[$asset_key], $asset_codes, true)) {
            $settings_to_save[$asset_key] = ($asset_key === 'default_bank_account' || $asset_key === 'received_into_bank_account' || $asset_key === 'received_into_mobile_banking_account')
                ? '1200'
                : '1100';
        }
    }

    foreach ($fee_types as $fee_type) {
        $key = 'income_account_' . $fee_type;
        $selected_code = trim((string)($_POST[$key] ?? ''));
        if (!in_array($selected_code, $income_codes, true)) {
            $selected_code = acc_default_income_code_for_fee_type($fee_type);
        }
        $settings_to_save[$key] = $selected_code;
    }

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

                    <h6 class="fw-bold mb-3 text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.06em">Collect Payment → Received Into Mapping</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Cash Payment</label>
                            <select name="received_into_cash_account" class="form-select" required>
                                <?php $selected_cash_recv = acc_setting('received_into_cash_account', acc_setting('default_cash_account', '1100')); ?>
                                <?php foreach ($asset_accounts as $a): ?>
                                <option value="<?= h($a['code']) ?>" <?= $selected_cash_recv === $a['code'] ? 'selected' : '' ?>>
                                    <?= h($a['code'] . ' – ' . $a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Bank Payment</label>
                            <select name="received_into_bank_account" class="form-select" required>
                                <?php $selected_bank_recv = acc_setting('received_into_bank_account', acc_setting('default_bank_account', '1200')); ?>
                                <?php foreach ($asset_accounts as $a): ?>
                                <option value="<?= h($a['code']) ?>" <?= $selected_bank_recv === $a['code'] ? 'selected' : '' ?>>
                                    <?= h($a['code'] . ' – ' . $a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Mobile Banking Payment</label>
                            <select name="received_into_mobile_banking_account" class="form-select" required>
                                <?php $selected_mobile_recv = acc_setting('received_into_mobile_banking_account', acc_setting('default_bank_account', '1200')); ?>
                                <?php foreach ($asset_accounts as $a): ?>
                                <option value="<?= h($a['code']) ?>" <?= $selected_mobile_recv === $a['code'] ? 'selected' : '' ?>>
                                    <?= h($a['code'] . ' – ' . $a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3 text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.06em">Student Fee Income Mapping</h6>
                    <div class="row g-3 mb-4">
                        <?php foreach ($fee_types as $fee_type): ?>
                            <?php
                            $setting_key = 'income_account_' . $fee_type;
                            $selected_code = acc_income_account_code_for_fee_type($fee_type);
                            ?>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold"><?= h(acc_fee_type_label($fee_type)) ?></label>
                                <select name="<?= h($setting_key) ?>" class="form-select" required>
                                    <?php foreach ($income_accounts as $a): ?>
                                        <option value="<?= h($a['code']) ?>" <?= $selected_code === $a['code'] ? 'selected' : '' ?>>
                                            <?= h($a['code'] . ' – ' . $a['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
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

                    <!-- ── Student Fee Notifications ───────────────────────── -->
                    <hr class="my-4">
                    <h6 class="fw-bold mb-1 text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.06em">
                        <i class="fas fa-bell me-1"></i>Student Fee Notifications
                    </h6>
                    <p class="text-muted small mb-3">Sent automatically after collecting a student fee payment.</p>

                    <!-- Email invoice -->
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="emailInvoice" name="email_invoice" value="1"
                               <?= acc_setting('email_invoice', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="emailInvoice">
                            <i class="fas fa-envelope me-1 text-primary"></i>Send email invoice to student after payment
                        </label>
                    </div>
                    <div class="alert alert-light border small mb-4">
                        <i class="fas fa-info-circle text-primary me-1"></i>
                        The email template <strong>fee_payment_invoice</strong> can be customised in the Email Templates section.
                        The student's email address must be set in their Student Profile.
                    </div>

                    <!-- SMS -->
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="smsEnabled" name="sms_enabled" value="1"
                               <?= acc_setting('sms_enabled', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="smsEnabled">
                            <i class="fas fa-sms me-1 text-success"></i>Send SMS notification to student after payment
                        </label>
                    </div>

                    <div id="smsFields" <?= acc_setting('sms_enabled', '0') !== '1' ? 'style="display:none"' : '' ?>>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">FastSMS BD API Key</label>
                                <input type="text" name="sms_api_key" class="form-control"
                                       placeholder="Leave blank to keep existing"
                                       autocomplete="off">
                                <div class="form-text">Leave blank to keep the existing saved key.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Sender ID</label>
                                <input type="text" name="sms_sender_id" class="form-control"
                                       value="<?= h(acc_setting('sms_sender_id', '')) ?>"
                                       placeholder="e.g. PrimeUni">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">SMS Template</label>
                            <textarea name="sms_template" class="form-control" rows="3"><?= h(acc_setting('sms_template', 'Dear {{student_name}}, your payment of {{currency}}{{amount}} has been received for {{fee_type}}. Voucher: {{voucher_number}}. Thank you. - {{app_name}}')) ?></textarea>
                            <div class="form-text">
                                Available placeholders: <code>{{student_name}}</code> <code>{{student_sid}}</code>
                                <code>{{amount}}</code> <code>{{currency}}</code> <code>{{fee_type}}</code>
                                <code>{{voucher_number}}</code> <code>{{app_name}}</code>
                            </div>
                        </div>
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

<script>
document.getElementById('smsEnabled').addEventListener('change', function () {
    document.getElementById('smsFields').style.display = this.checked ? '' : 'none';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
