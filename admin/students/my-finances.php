<?php
/**
 * Student Portal – My Finances
 * Allows the logged-in student portal user to view their own fee schedule,
 * outstanding balance, and payment transaction history (read-only).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../accounting/helpers.php';
require_once __DIR__ . '/../accounting/payment-methods-helpers.php';

if (!is_portal_student()) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

// ── Pay Online data ───────────────────────────────────────────────────────
$opm_user    = auth_user();
$opm_student = null;
if ($opm_user) {
    $opm_stmt = db()->prepare('SELECT id, student_id, full_name FROM students WHERE portal_user_id = ? LIMIT 1');
    $opm_stmt->execute([(int)($opm_user['id'] ?? 0)]);
    $opm_student = $opm_stmt->fetch() ?: null;
}
$opm_methods     = opm_all_methods(true);
$opm_submissions = $opm_student ? opm_student_submissions((int)$opm_student['id']) : [];
$opm_guidelines  = [
    'bank'           => opm_guideline('bank'),
    'mobile_banking' => opm_guideline('mobile_banking'),
];

// Scheduled banks of Bangladesh — searchable list for the "Bank Name" field
// shown when the student submits a bank payment.
$opm_bd_banks = [
    'AB Bank PLC', 'Agrani Bank PLC', 'Al-Arafah Islami Bank PLC', 'Bangladesh Commerce Bank Limited',
    'Bangladesh Development Bank PLC', 'Bangladesh Krishi Bank', 'Bank Al-Falah Limited', 'Bank Asia PLC',
    'BASIC Bank Limited', 'Bengal Commercial Bank PLC', 'BRAC Bank PLC', 'Citibank N.A.',
    'Citizens Bank PLC', 'City Bank PLC', 'Commercial Bank of Ceylon PLC', 'Community Bank Bangladesh PLC',
    'Dhaka Bank PLC', 'Dutch-Bangla Bank PLC', 'Eastern Bank PLC', 'EXIM Bank PLC',
    'First Security Islami Bank PLC', 'Global Islami Bank PLC', 'Habib Bank Limited', 'HSBC',
    'ICB Islamic Bank PLC', 'IFIC Bank PLC', 'Islami Bank Bangladesh PLC', 'Jamuna Bank PLC',
    'Janata Bank PLC', 'Meghna Bank PLC', 'Mercantile Bank PLC', 'Midland Bank PLC',
    'Modhumoti Bank PLC', 'Mutual Trust Bank PLC', 'National Bank of Pakistan', 'National Bank PLC',
    'NCC Bank PLC', 'NRB Bank PLC', 'NRBC Bank PLC', 'One Bank PLC',
    'Padma Bank PLC', 'Premier Bank PLC', 'Prime Bank PLC', 'Probashi Kallyan Bank',
    'Pubali Bank PLC', 'Rajshahi Krishi Unnayan Bank', 'Rupali Bank PLC', 'Shahjalal Islami Bank PLC',
    'Shimanto Bank PLC', 'Social Islami Bank PLC', 'Sonali Bank PLC', 'South Bangla Agriculture & Commerce Bank PLC',
    'Southeast Bank PLC', 'Standard Bank PLC', 'Standard Chartered Bank', 'State Bank of India',
    'Trust Bank PLC', 'Union Bank PLC', 'United Commercial Bank PLC', 'Uttara Bank PLC',
    'Woori Bank',
];

$page_title = 'My Finances';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-file-invoice-dollar me-2 text-success"></i>My Finances
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Home</a></li>
            <li class="breadcrumb-item active">My Finances</li>
        </ol></nav>
    </div>
</div>

<?= flash_show() ?>

<!-- Student info strip (populated by JS) -->
<div class="card border-0 shadow-sm mb-3" id="studentInfoCard" style="display:none;">
    <div class="card-body py-3 px-4 d-flex align-items-center gap-3 flex-wrap">
        <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;flex-shrink:0;">
            <i class="fas fa-user-graduate fa-lg"></i>
        </div>
        <div>
            <div class="fw-bold fs-6" id="infoName"></div>
            <div class="small text-muted" id="infoMeta"></div>
        </div>
        <div class="ms-auto">
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2" id="infoStatus"></span>
        </div>
    </div>
</div>

<?php if ($opm_student && $opm_methods): ?>
<!-- ── Pay Online ──────────────────────────────────────────────────────── -->
<style>
    .opm-pay-header { background: linear-gradient(135deg, #1d976c 0%, #2f80ed 100%); }
    .opm-type-card {
        display: block; text-align: center; cursor: pointer; margin-bottom: 0;
        border: 2px solid #e9ecef; border-radius: .75rem; padding: .75rem .5rem;
        background: #fff; transition: border-color .15s, box-shadow .15s, transform .15s;
    }
    .opm-method-card {
        display: flex; align-items: center; gap: .75rem; cursor: pointer; margin-bottom: 0;
        border: 2px solid #e9ecef; border-radius: .75rem; padding: .7rem .9rem;
        background: #fff; transition: border-color .15s, box-shadow .15s, transform .15s;
    }
    .opm-type-card:hover, .opm-method-card:hover { border-color: #adb5bd; transform: translateY(-1px); box-shadow: 0 .25rem .5rem rgba(0,0,0,.07); }
    .btn-check:checked + .opm-type-card[for="opmTypeBank"] { border-color: #3b5bdb; color: #2b3a91; background: linear-gradient(135deg, #eef2ff, #e7f0ff); box-shadow: 0 .25rem .75rem rgba(59,91,219,.25); }
    .btn-check:checked + .opm-type-card[for="opmTypeMobile"] { border-color: #e0218a; color: #a61e6a; background: linear-gradient(135deg, #fff0f6, #ffe3ef); box-shadow: 0 .25rem .75rem rgba(224,33,138,.25); }
    .btn-check:checked + .opm-method-card { border-color: #198754; background: linear-gradient(135deg, #f0fff6, #e6f9ef); box-shadow: 0 .25rem .75rem rgba(25,135,84,.2); }
    .opm-method-check { color: #198754; opacity: 0; transition: opacity .15s; margin-left: auto; }
    .btn-check:checked + .opm-method-card .opm-method-check { opacity: 1; }
    .opm-method-icon { width: 40px; height: 40px; flex: 0 0 40px; border-radius: .6rem; display: flex; align-items: center; justify-content: center; color: #fff; }
    .opm-icon-bank { background: linear-gradient(135deg, #4c6ef5, #3b5bdb); }
    .opm-icon-mobile { background: linear-gradient(135deg, #f06595, #e0218a); }
    #bdBankMenu { -webkit-overflow-scrolling: touch; }
</style>
<div class="card border-0 shadow-sm mb-3 overflow-hidden" id="payOnlineCard">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2 border-0 opm-pay-header">
        <span class="fw-semibold text-white"><i class="fas fa-globe me-2"></i>Pay Online</span>
        <button type="button" class="btn btn-sm btn-light fw-semibold" data-bs-toggle="collapse" data-bs-target="#payOnlineCollapse">
            <i class="fas fa-hand-holding-usd me-1 text-success"></i>Make a Payment
        </button>
    </div>
    <div class="collapse" id="payOnlineCollapse">
        <div class="card-body p-3 p-md-4">
            <div class="alert alert-info small">
                <i class="fas fa-info-circle me-1"></i>
                Pay your fees through a bank deposit / transfer or mobile banking, then submit the payment details here with a receipt.
                After review and approval by the Accounts Office the payment is added to your account. Verification is normally completed
                within <strong>24 hours</strong>, but occasionally may take up to <strong>48 hours</strong>.
            </div>
            <form method="post" action="<?= APP_URL ?>/accounting/online-payment-submit.php" enctype="multipart/form-data" id="payOnlineForm">
                <?= csrf_field() ?>
                <label class="form-label fw-semibold">Payment Type <span class="text-danger">*</span></label>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <input type="radio" class="btn-check" name="opm_type_choice" id="opmTypeBank" value="bank" autocomplete="off">
                        <label class="opm-type-card w-100" for="opmTypeBank">
                            <i class="fas fa-university fa-lg d-block mb-1"></i>
                            <span class="fw-semibold">Bank</span>
                            <span class="d-block small opacity-75">Deposit / transfer</span>
                        </label>
                    </div>
                    <div class="col-6">
                        <input type="radio" class="btn-check" name="opm_type_choice" id="opmTypeMobile" value="mobile_banking" autocomplete="off">
                        <label class="opm-type-card w-100" for="opmTypeMobile">
                            <i class="fas fa-mobile-alt fa-lg d-block mb-1"></i>
                            <span class="fw-semibold">Mobile Banking</span>
                            <span class="d-block small opacity-75">bKash / Nagad / Rocket</span>
                        </label>
                    </div>
                </div>
                <div id="opmMethodWrap" style="display:none;">
                    <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                    <div class="row g-2 mb-1">
                        <?php foreach ($opm_methods as $opm_m): ?>
                        <div class="col-12 col-sm-6 opm-method-col" data-type="<?= h((string)$opm_m['method_type']) ?>" style="display:none;">
                            <input type="radio" class="btn-check opm-method-radio" name="method_id" id="opmMethod<?= (int)$opm_m['id'] ?>" value="<?= (int)$opm_m['id'] ?>" required>
                            <label class="opm-method-card w-100" for="opmMethod<?= (int)$opm_m['id'] ?>">
                                <span class="opm-method-icon <?= (string)$opm_m['method_type'] === 'bank' ? 'opm-icon-bank' : 'opm-icon-mobile' ?>">
                                    <i class="fas fa-<?= (string)$opm_m['method_type'] === 'bank' ? 'university' : 'mobile-alt' ?>"></i>
                                </span>
                                <span class="flex-grow-1" style="min-width: 0;">
                                    <span class="d-block fw-semibold text-truncate"><?= h(opm_method_title($opm_m)) ?></span>
                                    <?php if ((string)$opm_m['method_type'] === 'bank' && !empty($opm_m['account_number'])): ?>
                                    <span class="d-block small text-muted font-monospace text-truncate"><?= h((string)$opm_m['account_number']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($opm_m['charge_note'])): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle mt-1"><?= h((string)$opm_m['charge_note']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <i class="fas fa-check-circle opm-method-check"></i>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="opmDetails" class="mt-3" style="display:none;"></div>
                <div id="opmGuideline" class="alert alert-warning small mt-3 mb-0" style="display:none; white-space: pre-line;"></div>
                <!-- Bank payments: structured payer details (shown only for type = bank) -->
                <div class="row g-3 mt-1" id="opmBankPayerFields" style="display:none;">
                    <div class="col-12 col-md-4 position-relative">
                        <label class="form-label fw-semibold">Bank Name (paid from) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="payer_bank_name" id="opmPayerBankName"
                               maxlength="190" autocomplete="off" placeholder="Type to search your bank…">
                        <div id="bdBankMenu" class="list-group position-absolute start-0 end-0 mt-1 shadow border rounded bg-white overflow-auto"
                             style="display:none; z-index: 1056; max-height: 240px;"></div>
                        <div class="form-text">Start typing to search all Bangladeshi banks, or type the name manually.</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Paid From Account Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="payer_account_name" id="opmPayerAccountName"
                               maxlength="190" placeholder="Account holder name you paid from">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Account Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="payer_account_number" id="opmPayerAccountNumber"
                               maxlength="64" placeholder="Account number you paid from">
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12 col-md-6" id="opmPaidFromWrap">
                        <label class="form-label fw-semibold">Paid From (wallet name or number) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="paid_from" id="opmPaidFrom" maxlength="190" required
                               placeholder="e.g. the wallet number you paid from">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Amount Paid (<?= h(acc_currency()) ?>) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" min="1" step="0.01" required>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="paid_date" max="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label fw-semibold">Payment Time</label>
                        <input type="time" class="form-control" name="paid_time">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Transaction / Reference No. <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="transaction_number" maxlength="190" required>
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label fw-semibold">Receipt / Screenshot <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="receipt" id="opmReceipt" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                        <div class="form-text">JPG, PNG, WEBP or PDF — max 5 MB.</div>
                    </div>
                    <div class="col-12 col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn w-100 text-white fw-semibold border-0 opm-pay-header">
                            <i class="fas fa-paper-plane me-1"></i> Submit for Verification
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($opm_submissions): ?>
<?php
    $opm_has_pending = false;
    foreach ($opm_submissions as $opm_s) {
        if ((string)$opm_s['status'] === 'pending') { $opm_has_pending = true; break; }
    }
?>
<!-- ── My Online Payment Submissions ───────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-3 px-4 fw-semibold">
        <i class="fas fa-clipboard-check me-2 text-info"></i>My Online Payment Submissions
    </div>
    <?php if ($opm_has_pending): ?>
    <div class="alert alert-info m-3 mb-0 small">
        <i class="fas fa-hourglass-half me-1"></i>
        Your payment is being verified. Verification is normally done within <strong>24 hours</strong>,
        but sometimes it may take up to <strong>48 hours</strong>. You will see the result here.
    </div>
    <?php endif; ?>
    <div class="table-responsive d-none d-md-block">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">Submitted</th>
                    <th>Method</th>
                    <th>Paid On</th>
                    <th>Txn No.</th>
                    <th class="text-end">Amount</th>
                    <th>Status</th>
                    <th>Note from Accounts Office</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($opm_submissions as $opm_s): ?>
                <tr>
                    <td class="ps-4 small"><?= h((string)$opm_s['created_at']) ?></td>
                    <td class="small"><?= h((string)$opm_s['method_title']) ?></td>
                    <td class="small"><?= h((string)$opm_s['paid_date']) ?><?= !empty($opm_s['paid_time']) ? ' ' . h((string)$opm_s['paid_time']) : '' ?></td>
                    <td class="small font-monospace"><?= h((string)$opm_s['transaction_number']) ?></td>
                    <td class="text-end fw-semibold"><?= h(number_format((float)$opm_s['amount'], 2)) ?></td>
                    <td><?= opm_status_badge((string)$opm_s['status']) ?></td>
                    <td class="small text-muted"><?= !empty($opm_s['admin_note']) ? h((string)$opm_s['admin_note']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Mobile: stacked cards instead of a wide table -->
    <div class="d-md-none p-3 pt-2">
        <?php foreach ($opm_submissions as $opm_s): ?>
        <div class="border rounded-3 p-3 mb-2 shadow-sm">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                <div class="fw-semibold small"><?= h((string)$opm_s['method_title']) ?></div>
                <?= opm_status_badge((string)$opm_s['status']) ?>
            </div>
            <div class="fs-5 fw-bold mb-1"><?= h(number_format((float)$opm_s['amount'], 2)) ?></div>
            <div class="small text-muted">Paid on: <?= h((string)$opm_s['paid_date']) ?><?= !empty($opm_s['paid_time']) ? ' ' . h((string)$opm_s['paid_time']) : '' ?></div>
            <div class="small text-muted">Txn: <span class="font-monospace"><?= h((string)$opm_s['transaction_number']) ?></span></div>
            <div class="small text-muted">Submitted: <?= h((string)$opm_s['created_at']) ?></div>
            <?php if (!empty($opm_s['admin_note'])): ?>
            <div class="small mt-1"><i class="fas fa-comment-dots me-1 text-info"></i><?= h((string)$opm_s['admin_note']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var opmMethods = <?= json_encode(array_map(static fn(array $m): array => [
        'id'             => (int)$m['id'],
        'type'           => (string)$m['method_type'],
        'title'          => opm_method_title($m),
        'bank_name'      => (string)($m['bank_name'] ?? ''),
        'branch_name'    => (string)($m['branch_name'] ?? ''),
        'account_name'   => (string)($m['account_name'] ?? ''),
        'account_number' => (string)($m['account_number'] ?? ''),
        'operator_name'  => (string)($m['operator_name'] ?? ''),
        'wallet_number'  => (string)($m['wallet_number'] ?? ''),
        'charge_note'    => (string)($m['charge_note'] ?? ''),
    ], $opm_methods), JSON_UNESCAPED_UNICODE) ?>;
    var opmGuidelines = <?= json_encode($opm_guidelines, JSON_UNESCAPED_UNICODE) ?>;

    var typeRadios   = Array.prototype.slice.call(document.querySelectorAll('input[name="opm_type_choice"]'));
    var methodWrap   = document.getElementById('opmMethodWrap');
    var methodCols   = Array.prototype.slice.call(document.querySelectorAll('.opm-method-col'));
    var methodRadios = Array.prototype.slice.call(document.querySelectorAll('.opm-method-radio'));
    var details   = document.getElementById('opmDetails');
    var guideline = document.getElementById('opmGuideline');
    var receipt   = document.getElementById('opmReceipt');
    var form      = document.getElementById('payOnlineForm');
    var bankFields   = document.getElementById('opmBankPayerFields');
    var paidFromWrap = document.getElementById('opmPaidFromWrap');
    var paidFrom     = document.getElementById('opmPaidFrom');
    var payerInputs  = ['opmPayerBankName', 'opmPayerAccountName', 'opmPayerAccountNumber']
        .map(function (id) { return document.getElementById(id); });
    var bdBanks   = <?= json_encode($opm_bd_banks, JSON_UNESCAPED_UNICODE) ?>;
    var bankInput = document.getElementById('opmPayerBankName');
    var bankMenu  = document.getElementById('bdBankMenu');
    if (!typeRadios.length || !methodRadios.length) { return; }

    // ── Searchable Bangladeshi bank picker (custom dropdown — works on all
    // browsers and phones, unlike the native datalist) ──
    function renderBankMenu(filter) {
        if (!bankMenu) { return; }
        var q = (filter || '').toLowerCase();
        var matches = bdBanks.filter(function (b) { return b.toLowerCase().indexOf(q) !== -1; });
        if (!matches.length) {
            bankMenu.innerHTML = '<div class="list-group-item small text-muted">No bank found — you can still type the name manually.</div>';
        } else {
            bankMenu.innerHTML = matches.map(function (b) {
                return '<button type="button" class="list-group-item list-group-item-action py-2 small" data-bank="' + esc(b) + '">' + esc(b) + '</button>';
            }).join('');
        }
        bankMenu.style.display = '';
    }
    function hideBankMenu() { if (bankMenu) { bankMenu.style.display = 'none'; } }
    if (bankInput && bankMenu) {
        bankInput.addEventListener('focus', function () { renderBankMenu(bankInput.value.trim()); });
        bankInput.addEventListener('input', function () { renderBankMenu(bankInput.value.trim()); });
        bankInput.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hideBankMenu(); } });
        bankMenu.addEventListener('mousedown', function (e) {
            var btn = e.target.closest('[data-bank]');
            if (btn) {
                e.preventDefault();
                bankInput.value = btn.getAttribute('data-bank');
                hideBankMenu();
            }
        });
        document.addEventListener('click', function (e) {
            if (e.target !== bankInput && !bankMenu.contains(e.target)) { hideBankMenu(); }
        });
    }

    function togglePayerFields(t) {
        var isBank = t === 'bank';
        if (bankFields)   { bankFields.style.display   = isBank ? '' : 'none'; }
        if (paidFromWrap) { paidFromWrap.style.display = isBank ? 'none' : ''; }
        if (paidFrom) {
            paidFrom.required = !isBank;
            if (isBank) { paidFrom.value = ''; }
        }
        payerInputs.forEach(function (el) {
            if (!el) { return; }
            el.required = isBank;
            if (!isBank) { el.value = ''; }
        });
    }

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function row(label, value, mono) {
        return '<div class="d-flex flex-column flex-sm-row py-1">'
             + '<div class="text-muted small" style="flex: 0 0 160px;">' + esc(label) + '</div>'
             + '<div class="fw-semibold' + (mono ? ' font-monospace' : '') + '" style="word-break: break-all;">' + esc(value) + '</div>'
             + '</div>';
    }

    function applyType(t) {
        togglePayerFields(t);
        details.style.display = 'none';
        details.innerHTML = '';
        methodRadios.forEach(function (r) { r.checked = false; });
        methodCols.forEach(function (col) {
            col.style.display = col.getAttribute('data-type') === t ? '' : 'none';
        });
        if (methodWrap) { methodWrap.style.display = t ? '' : 'none'; }
        guideline.textContent = t ? (opmGuidelines[t] || '') : '';
        guideline.style.display = guideline.textContent !== '' ? '' : 'none';
    }
    typeRadios.forEach(function (r) {
        r.addEventListener('change', function () { if (r.checked) { applyType(r.value); } });
    });

    function onMethodChange(radio) {
        var id = parseInt(radio.value, 10) || 0;
        var m = null;
        opmMethods.forEach(function (x) { if (x.id === id) { m = x; } });
        if (!m) { details.style.display = 'none'; details.innerHTML = ''; return; }
        var isBank = m.type === 'bank';
        var html = '<div class="rounded-3 p-3" style="background: linear-gradient(135deg, #f8fbff, #f3fff9); border: 1px solid #dbe7f6; border-left: 4px solid ' + (isBank ? '#3b5bdb' : '#e0218a') + ';">'
                 + '<div class="fw-semibold mb-2" style="color: ' + (isBank ? '#3b5bdb' : '#c2185b') + ';"><i class="fas fa-' + (isBank ? 'university' : 'mobile-alt') + ' me-1"></i>'
                 + 'Pay to this ' + (isBank ? 'bank account' : 'wallet') + ':</div>';
        if (m.type === 'bank') {
            html += row('Bank Name', m.bank_name)
                  + row('Branch Name', m.branch_name)
                  + row('Accounts Name', m.account_name)
                  + row('Accounts Number', m.account_number, true);
        } else {
            html += row('Operator', m.operator_name)
                  + row('Number', m.wallet_number, true);
            if (m.charge_note) { html += row('Charge', m.charge_note); }
        }
        html += '</div>';
        if (m.type === 'mobile_banking' && String(m.operator_name || '').toLowerCase() === 'bkash') {
            html += '<div class="alert alert-primary small mt-2 mb-0">'
                  + '<div class="fw-semibold mb-1"><i class="fas fa-mobile-alt me-1"></i>How to pay with bKash:</div>'
                  + '<ol class="mb-2 ps-3">'
                  + '<li>Log in to your bKash account / app</li>'
                  + '<li>Go to the <strong>Payment</strong> option</li>'
                  + '<li>Enter: <strong class="font-monospace">' + esc(m.wallet_number) + '</strong></li>'
                  + '<li>Enter the amount</li>'
                  + '<li>Complete the payment</li>'
                  + '<li>Write the <strong>Transaction ID (TrxID)</strong> in the field below</li>'
                  + '</ol>'
                  + '<div class="mb-0"><i class="fas fa-info-circle me-1"></i><strong>Note:</strong> 1.5% of the payment is the bKash fee — '
                  + 'it will <strong>not</strong> be adjusted to your due amount, but it will be shown on your invoice.</div>'
                  + '</div>';
        }
        details.innerHTML = html;
        details.style.display = '';
    }
    methodRadios.forEach(function (radio) {
        radio.addEventListener('change', function () { if (radio.checked) { onMethodChange(radio); } });
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            if (receipt && receipt.files && receipt.files[0] && receipt.files[0].size > 5242880) {
                e.preventDefault();
                alert('The receipt file is too large — maximum size is 5 MB.');
                return;
            }
            if (!confirm('Submit this payment for verification? Make sure the amount, date and transaction number exactly match your receipt.')) {
                e.preventDefault();
            }
        });
    }
})();
</script>
<?php endif; ?>

<style>
    /* ── Mobile responsiveness ──
       Below 768px the three data sections render as stacked cards (the wide
       tables are desktop-only) and header badges shrink and wrap instead of
       overflowing the viewport. */
    @media (max-width: 767.98px) {
        #totalOutstandingBadge { font-size: .78rem !important; }
        #feeScheduleCard .card-header, #scholarshipCard .card-header,
        #transactionHistoryCard .card-header {
            padding-left: 1rem !important; padding-right: 1rem !important;
        }
    }
</style>

<!-- Loading indicator -->
<div id="loadingWrap" class="text-center py-5 text-muted">
    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
    Loading your fee information…
</div>

<!-- Error panel -->
<div id="errorWrap" class="alert alert-danger" style="display:none;"></div>

<!-- Fee Schedule & Outstanding Balance -->
<div class="card border-0 shadow-sm mb-3" id="feeScheduleCard" style="display:none;">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="fw-semibold">
            <i class="fas fa-file-invoice-dollar me-2 text-success"></i>Fee Schedule &amp; Outstanding Balance
        </span>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 fs-6 text-wrap text-start"
                  id="totalOutstandingBadge" style="display:none;"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="collapse" data-bs-target="#feeScheduleCollapse">
                <i class="fas fa-list me-1"></i>Details
            </button>
        </div>
    </div>
    <div class="collapse show" id="feeScheduleCollapse">
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover table-sm align-middle mb-0" id="feeTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Fee Type</th>
                        <th class="text-end">Total Due</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end fw-bold">Outstanding</th>
                        <th class="text-center">Due Date</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody id="feeTableBody"></tbody>
                <tfoot class="table-light fw-bold" id="feeTableFoot">
                    <tr>
                        <td class="ps-4">Total</td>
                        <td class="text-end" id="footTotalDue"></td>
                        <td class="text-end" id="footTotalPaid"></td>
                        <td class="text-end text-danger" id="footTotalOut"></td>
                        <td></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <!-- Mobile: stacked rows instead of a wide table -->
        <div class="d-md-none p-3 pt-2" id="feeMobileBody"></div>
    </div>
</div>

<!-- Scholarships & Waivers -->
<div class="card border-0 shadow-sm mb-3" id="scholarshipCard" style="display:none;">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="fw-semibold">
            <i class="fas fa-graduation-cap me-2 text-warning"></i>Applied Scholarships &amp; Waivers
        </span>
        <button type="button" class="btn btn-sm btn-outline-secondary"
                data-bs-toggle="collapse" data-bs-target="#scholarshipCollapse">
            <i class="fas fa-list me-1"></i>Details
        </button>
    </div>
    <div class="collapse show" id="scholarshipCollapse">
        <div class="table-responsive d-none d-md-block">
            <table class="table table-sm table-hover align-middle mb-0" id="scholarshipTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Semester</th>
                        <th>Scholarship / Waiver Title</th>
                        <th class="text-end">Discount</th>
                        <th class="text-center">Scope</th>
                    </tr>
                </thead>
                <tbody id="scholarshipTableBody"></tbody>
            </table>
        </div>
        <!-- Mobile: stacked cards instead of a wide table -->
        <div class="d-md-none p-3 pt-2" id="scholarshipMobileBody"></div>
    </div>
</div>

<!-- Payment Transaction History -->
<div class="card border-0 shadow-sm mb-4" id="transactionHistoryCard" style="display:none;">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="fw-semibold">
            <i class="fas fa-history me-2 text-info"></i>Payment Transaction History
        </span>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-info-subtle text-info border border-info-subtle px-3 py-2"
                  id="transactionCount"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="collapse" data-bs-target="#transactionHistoryCollapse">
                <i class="fas fa-list me-1"></i>History
            </button>
        </div>
    </div>
    <div class="collapse show" id="transactionHistoryCollapse">
        <div class="table-responsive d-none d-md-block">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Date</th>
                        <th>Fee Type</th>
                        <th>Semester</th>
                        <th>Month</th>
                        <th>Payment Method</th>
                        <th>Txn #</th>
                        <th class="text-end">Amount</th>
                        <th>Voucher #</th>
                        <th>Invoice</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="transactionTableBody"></tbody>
            </table>
        </div>
        <!-- Mobile: stacked cards instead of a wide table -->
        <div class="d-md-none p-3 pt-2" id="transactionMobileBody"></div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
const APP_URL = <?= json_encode(APP_URL) ?>;
const CURRENCY = <?= json_encode(acc_currency()) ?>;

// Today's date (no time component)
const TODAY = new Date();
TODAY.setHours(0, 0, 0, 0);

// Monthly payment due day
const DUE_DAY = 10;

function fmt(n) {
    return CURRENCY + ' ' + parseFloat(n).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function feeTypeLabel(type) {
    const map = {
        admission:        'Admission Fee',
        form_fee:         'Form Fee',
        id_card_fee:      'ID Card Fee',
        registration:     'Registration Fee',
        semester_tuition: 'Tuition Fee',
        fixed_fee:        'Institutional Fee',
        english_fee:      'English Course Fee',
        project_fee:      'Project Fee',
        bi_tri_shift_fee: 'Bi-Tri Shift Merge Fee',
        other:            'Other Fee',
    };
    return map[type] || type;
}

/**
 * Determine due-date status for a monthly row.
 * Returns one of: 'overdue' | 'due_now' | 'upcoming' | 'paid' | null (no date)
 */
function monthStatus(calMonth, calYear, out) {
    if (!calMonth || !calYear) return null;
    const dueDate = new Date(calYear, calMonth - 1, DUE_DAY);
    dueDate.setHours(0, 0, 0, 0);
    if (out <= 0) return 'paid';
    if (dueDate < TODAY) return 'overdue';
    if (dueDate.getTime() === TODAY.getTime()) return 'due_now';
    return 'upcoming';
}

function formatDueDate(calMonth, calYear) {
    if (!calMonth || !calYear) return '—';
    const d = new Date(calYear, calMonth - 1, DUE_DAY);
    return d.toLocaleDateString('en-BD', {day: '2-digit', month: 'short', year: 'numeric'});
}

function statusBadge(status) {
    switch (status) {
        case 'overdue':
            return '<span class="badge bg-danger border border-danger">' +
                   '<i class="fas fa-exclamation-circle me-1"></i>Overdue</span>' +
                   '<div class="small text-danger mt-1" style="font-size:.7rem;">Late fees may apply</div>';
        case 'due_now':
            return '<span class="badge bg-warning text-dark border border-warning">' +
                   '<i class="fas fa-clock me-1"></i>Due Today</span>';
        case 'upcoming':
            return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">' +
                   '<i class="fas fa-calendar me-1"></i>Upcoming</span>';
        case 'paid':
            return '<span class="badge bg-success-subtle text-success border border-success-subtle">' +
                   '<i class="fas fa-check me-1"></i>Paid</span>';
        default:
            return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">' +
                   '<i class="fas fa-clock me-1"></i>Due</span>';
    }
}

function renderFeeSummary(data) {
    const s   = data.summary;
    const pkg = s.package;

    // Student info strip
    document.getElementById('infoName').textContent   = data.student.full_name;
    document.getElementById('infoMeta').textContent   =
        'ID: ' + data.student.student_id + '   |   Program: ' + pkg.program_name;
    document.getElementById('infoStatus').textContent = pkg.student_status ?? 'Active';
    document.getElementById('studentInfoCard').style.display = '';

    const tbody = document.getElementById('feeTableBody');
    tbody.innerHTML = '';
    // Mobile stacked view container (shown below 768px instead of the table)
    const mbody = document.getElementById('feeMobileBody');
    if (mbody) mbody.innerHTML = '';

    let grandDue = 0, grandPaid = 0, grandOut = 0;
    let currentlyDueOut = 0; // outstanding for overdue + due_now rows only

    function addSectionRow(label, scholarships) {
        const tr = document.createElement('tr');
        tr.className = 'table-secondary';

        let scHtml = '';
        if (scholarships && scholarships.length > 0) {
            scHtml = '<div class="d-flex flex-wrap gap-1 mt-1">';
            scholarships.forEach(sc => {
                const typeStr = sc.discount_type === 'fixed'
                    ? (CURRENCY + ' ' + parseFloat(sc.fixed_amount || sc.amount).toLocaleString('en-BD', {minimumFractionDigits: 2}))
                    : (parseFloat(sc.discount_pct).toFixed(1) + '%');
                let extras = '';
                if (sc.applies_to_fixed)   extras += '<span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">+Fixed</span>';
                if (sc.applies_to_english) extras += '<span class="badge bg-info text-dark ms-1" style="font-size:.6rem;">+ENG</span>';
                scHtml += `<span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25" style="font-size:.72rem;font-weight:500;">
                    <i class="fas fa-tag me-1"></i>${escHtml(sc.label)}&nbsp;(${typeStr})${extras}
                </span>`;
            });
            scHtml += '</div>';
        }

        tr.innerHTML = `<td colspan="6" class="ps-4 py-2 small fw-semibold text-muted">
            <i class="fas fa-chevron-right me-1"></i>${label}${scHtml}
        </td>`;
        tbody.appendChild(tr);

        if (mbody) {
            mbody.insertAdjacentHTML('beforeend',
                `<div class="small fw-semibold text-muted mt-3 mb-2">
                    <i class="fas fa-chevron-right me-1"></i>${label}${scHtml}
                </div>`);
        }
    }

    function addRow(label, due, paid, out, calMonth, calYear) {
        grandDue  += due;
        grandPaid += paid;
        grandOut  += out;

        const status  = monthStatus(calMonth, calYear, out);
        const dueDateStr = formatDueDate(calMonth, calYear);
        const pct = (due > 0 && out > 0) ? Math.round((out / due) * 100) : 0;

        // Only count as "currently due" for overdue and due-today rows
        if (status === 'overdue' || status === 'due_now') {
            currentlyDueOut += out;
        }

        // Outstanding cell colour: upcoming rows use muted styling instead of red
        const outColour = out > 0
            ? (status === 'upcoming' ? 'text-secondary' : 'text-danger')
            : 'text-success';

        // Status badge markup (shared by the desktop table and the mobile cards)
        const badgeHtml = due > 0
            ? (status !== null
                ? statusBadge(status)
                : (out > 0
                    ? '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-clock me-1"></i>Due</span>'
                    : '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-check me-1"></i>Paid</span>'))
            : '—';

        const tr  = document.createElement('tr');
        tr.innerHTML = `
            <td class="ps-4">
                <div class="small">${label}</div>
                ${due > 0 && out > 0
                    ? `<div class="progress mt-1" style="height:3px;width:100px;">
                           <div class="progress-bar bg-success" style="width:${100 - pct}%"></div>
                           <div class="progress-bar ${status === 'upcoming' ? 'bg-secondary' : 'bg-danger'} opacity-50" style="width:${pct}%"></div>
                       </div>`
                    : ''}
            </td>
            <td class="text-end small">${due > 0 ? fmt(due) : '—'}</td>
            <td class="text-end small text-success">${paid > 0 ? fmt(paid) : '—'}</td>
            <td class="text-end small fw-semibold ${outColour}">
                ${out > 0 ? fmt(out) : (due > 0 ? '<i class="fas fa-check-circle"></i> Paid' : '—')}
            </td>
            <td class="text-center small text-muted">${dueDateStr}</td>
            <td class="text-center">
                ${badgeHtml}
            </td>`;
        tbody.appendChild(tr);

        // Mobile stacked card
        if (mbody) {
            mbody.insertAdjacentHTML('beforeend', `
            <div class="border rounded-3 p-2 px-3 mb-2 bg-white shadow-sm">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="small fw-semibold" style="min-width:0;word-break:break-word;">${label}</div>
                    <div class="text-end flex-shrink-0">${badgeHtml}</div>
                </div>
                <div class="d-flex flex-wrap gap-3 small mt-1">
                    <span class="text-muted">Due: <span class="fw-semibold text-body">${due > 0 ? fmt(due) : '—'}</span></span>
                    <span class="text-muted">Paid: <span class="fw-semibold text-success">${paid > 0 ? fmt(paid) : '—'}</span></span>
                    <span class="text-muted">Outstanding: <span class="fw-semibold ${outColour}">${out > 0 ? fmt(out) : (due > 0 ? 'Paid' : '—')}</span></span>
                </div>
                ${dueDateStr !== '—' ? `<div class="small text-muted mt-1"><i class="fas fa-calendar me-1"></i>Due date: ${dueDateStr}</div>` : ''}
            </div>`);
        }
    }

    const t = s.totals;

    addSectionRow('Admission', []);
    addRow('Admission Fee', t.admission.due, t.admission.paid, t.admission.out, null, null);
    if (t.form_fee && (t.form_fee.due > 0 || t.form_fee.paid > 0)) {
        addRow('Form Fee', t.form_fee.due, t.form_fee.paid, t.form_fee.out, null, null);
    }
    if (t.id_card_fee && (t.id_card_fee.due > 0 || t.id_card_fee.paid > 0)) {
        addRow('ID Card Fee', t.id_card_fee.due, t.id_card_fee.paid, t.id_card_fee.out, null, null);
    }

    s.semesters.forEach(sf => {
        const semLabel = sf.semester_label || ('Semester ' + sf.semester_number);
        addSectionRow(semLabel, sf.scholarships || []);
        // Use the first month's calendar date as the registration fee due period so
        // semesters that haven't started yet are shown as "Upcoming" rather than "Due".
        const firstMonth = sf.monthly_rows && sf.monthly_rows.length ? sf.monthly_rows[0] : null;
        addRow(semLabel + ' – Registration Fee', sf.reg_fee, sf.reg_paid, sf.reg_out,
               firstMonth ? (firstMonth.cal_month || null) : null,
               firstMonth ? (firstMonth.cal_year  || null) : null);
        sf.monthly_rows.forEach(mr => {
            addRow(
                semLabel + ' – Month ' + mr.month_number + (mr.month_label ? ' (' + mr.month_label + ')' : ''),
                mr.due, mr.paid, mr.out,
                mr.cal_month || null,
                mr.cal_year  || null
            );
        });
    });

    // Bi-Tri Shift Merge fee – extra months appended after the last semester
    const bitriMonths = (s.bi_tri_shift && s.bi_tri_shift.months) ? s.bi_tri_shift.months : [];
    if (bitriMonths.length > 0) {
        addSectionRow('Bi-Tri Shift Merge Fee', []);
        bitriMonths.forEach(mr => {
            addRow(
                'Extra Month ' + mr.month_number + (mr.month_label ? ' (' + mr.month_label + ')' : ''),
                mr.due, mr.paid, mr.out,
                mr.cal_month || null,
                mr.cal_year  || null
            );
        });
    }

    // One-time Project Fee (falls due with the final semester)
    if (t.project_fee && (t.project_fee.due > 0 || t.project_fee.paid > 0)) {
        let projCalMonth = null, projCalYear = null;
        const lastRows = bitriMonths.length > 0
            ? bitriMonths
            : (s.semesters.length > 0 ? (s.semesters[s.semesters.length - 1].monthly_rows || []) : []);
        if (lastRows.length > 0) {
            projCalMonth = lastRows[lastRows.length - 1].cal_month || null;
            projCalYear  = lastRows[lastRows.length - 1].cal_year  || null;
        }
        addSectionRow('Project Fee', []);
        addRow('Project Fee (one-time)', t.project_fee.due, t.project_fee.paid, t.project_fee.out, projCalMonth, projCalYear);
    }

    document.getElementById('footTotalDue').textContent  = fmt(grandDue);
    document.getElementById('footTotalPaid').textContent = fmt(grandPaid);
    document.getElementById('footTotalOut').textContent  = fmt(grandOut);

    // Mobile: totals card at the end of the stacked list
    if (mbody) {
        mbody.insertAdjacentHTML('beforeend', `
            <div class="border rounded-3 p-3 mt-3 bg-light">
                <div class="d-flex justify-content-between small mb-1"><span class="fw-semibold">Total Due</span><span class="fw-semibold">${fmt(grandDue)}</span></div>
                <div class="d-flex justify-content-between small mb-1"><span class="fw-semibold">Total Paid</span><span class="fw-semibold text-success">${fmt(grandPaid)}</span></div>
                <div class="d-flex justify-content-between small"><span class="fw-semibold">Total Outstanding</span><span class="fw-bold ${grandOut > 0 ? 'text-danger' : 'text-success'}">${fmt(grandOut)}</span></div>
            </div>`);
    }

    const badge = document.getElementById('totalOutstandingBadge');
    if (grandOut > 0) {
        // Show currently-due amount prominently; note total outstanding separately
        let badgeText = 'Outstanding: ' + fmt(currentlyDueOut);
        if (grandOut > currentlyDueOut) {
            badgeText += ' (Total: ' + fmt(grandOut) + ')';
        }
        badge.textContent   = badgeText;
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
    }

    document.getElementById('feeScheduleCard').style.display = '';
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

function renderScholarships(semesters) {
    const card  = document.getElementById('scholarshipCard');
    const tbody = document.getElementById('scholarshipTableBody');
    const mbody = document.getElementById('scholarshipMobileBody');
    tbody.innerHTML = '';
    if (mbody) mbody.innerHTML = '';

    let hasAny = false;
    semesters.forEach(sf => {
        const scholarships = sf.scholarships || [];
        if (!scholarships.length) return;

        const semLabel = sf.semester_label || ('Semester ' + sf.semester_number);
        scholarships.forEach((sc, idx) => {
            hasAny = true;
            const isFixed = sc.discount_type === 'fixed';
            const discountStr = isFixed
                ? (CURRENCY + '\u00a0' + parseFloat(sc.fixed_amount || sc.amount).toLocaleString('en-BD', {minimumFractionDigits: 2}))
                : (parseFloat(sc.discount_pct).toFixed(1) + '%');

            let scopeBadges = '';
            scopeBadges += '<span class="badge bg-success-subtle text-success border border-success-subtle me-1">Tuition</span>';
            if (sc.applies_to_fixed)   scopeBadges += '<span class="badge bg-warning-subtle text-warning border border-warning-subtle me-1">Fixed Fee</span>';
            if (sc.applies_to_english) scopeBadges += '<span class="badge bg-info-subtle text-info border border-info-subtle me-1">English Fee</span>';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="ps-4 small fw-semibold text-muted">${idx === 0 ? escHtml(semLabel) : ''}</td>
                <td>
                    <span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25"
                          style="font-size:.8rem;font-weight:500;">
                        <i class="fas fa-tag me-1"></i>${escHtml(sc.label)}
                    </span>
                </td>
                <td class="text-end fw-semibold ${isFixed ? 'text-success' : 'text-warning'}">${discountStr}</td>
                <td class="text-center small">${scopeBadges}</td>`;
            tbody.appendChild(tr);

            // Mobile stacked card
            if (mbody) {
                mbody.insertAdjacentHTML('beforeend', `
                <div class="border rounded-3 p-3 mb-2 shadow-sm">
                    <div class="small fw-semibold text-muted mb-1">${escHtml(semLabel)}</div>
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25"
                              style="font-size:.8rem;font-weight:500;">
                            <i class="fas fa-tag me-1"></i>${escHtml(sc.label)}
                        </span>
                        <span class="fw-semibold ${isFixed ? 'text-success' : 'text-warning'}">${discountStr}</span>
                    </div>
                    <div class="small mt-2">${scopeBadges}</div>
                </div>`);
            }
        });
    });

    if (hasAny) {
        card.style.display = '';
    }
}

function renderTransactionHistory(payments) {
    const card       = document.getElementById('transactionHistoryCard');
    const tbody      = document.getElementById('transactionTableBody');
    const countBadge = document.getElementById('transactionCount');

    tbody.innerHTML = '';
    countBadge.textContent = payments.length + ' transaction' + (payments.length !== 1 ? 's' : '');

    if (payments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3 small">' +
            '<i class="fas fa-info-circle me-1"></i>No transactions recorded yet.</td></tr>';
    } else {
        payments.forEach(p => {
            const feeLabel  = feeTypeLabel(p.fee_type);
            const semText   = p.semester_number ? ('Semester ' + p.semester_number) : '—';
            const monText   = p.month_number
                ? ('Month ' + p.month_number + (p.month_label ? ' (' + p.month_label + ')' : ''))
                : (p.fee_type === 'semester_tuition' ? 'Lump sum' : '—');
            const voucherStatusBadge = p.voucher_status === 'posted'
                ? '<span class="badge bg-success-subtle text-success border border-success-subtle">Posted</span>'
                : '<span class="badge bg-warning text-dark">' + p.voucher_status + '</span>';
            const dateStr = p.voucher_date
                ? new Date(p.voucher_date + 'T00:00:00').toLocaleDateString('en-BD', {
                    day: '2-digit', month: 'short', year: 'numeric'
                  })
                : '—';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="ps-4 small">${dateStr}</td>
                <td class="small">${feeLabel}</td>
                <td class="small">${semText}</td>
                <td class="small">${monText}</td>
                <td class="small">${p.payment_method_label || 'Cash'}</td>
                <td class="small">${p.transaction_number || '—'}</td>
                <td class="text-end small fw-semibold text-success">${fmt(p.amount)}</td>
                <td class="small">${p.voucher_number ?? '—'}</td>
                <td class="small">
                    <a href="${APP_URL}/accounting/student-invoice.php?voucher_id=${p.voucher_id}"
                       target="_blank" rel="noopener noreferrer"
                       class="btn btn-sm btn-outline-primary py-0 px-2">
                        <i class="fas fa-print me-1"></i>Student Copy
                    </a>
                </td>
                <td>${voucherStatusBadge}</td>`;
            tbody.appendChild(tr);
        });
    }
    card.style.display = '';
}

// Load data on page ready
document.addEventListener('DOMContentLoaded', function () {
    fetch(APP_URL + '/accounting/get-student-fees-portal.php', {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('loadingWrap').style.display = 'none';
        if (data.error) {
            const el = document.getElementById('errorWrap');
            el.textContent = data.error;
            el.style.display = '';
            return;
        }
        renderFeeSummary(data);
        renderScholarships(data.summary.semesters || []);
        renderTransactionHistory(data.payments || []);
    })
    .catch(() => {
        document.getElementById('loadingWrap').style.display = 'none';
        const el = document.getElementById('errorWrap');
        el.textContent = 'Could not load fee information. Please refresh the page or contact the Accounts Office.';
        el.style.display = '';
    });
});
</script>
