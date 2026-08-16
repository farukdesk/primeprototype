<?php
/**
 * Student Accounts Portal – read-only view of the student's own fee schedule,
 * outstanding balance, and payment history.
 *
 * Accessible to admin users whose user account has student_sid set and who
 * belong to a group granted access to the 'student-accounts-portal' module.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts-portal');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/payment-methods-helpers.php';

$user        = auth_user();
$student_sid = trim((string)($user['student_sid'] ?? ''));

// ── Pay Online data ───────────────────────────────────────────────────────
// Resolve the student record: portal-linked account first, then Student ID.
$opm_student = null;
if ($user) {
    $opm_stmt = db()->prepare('SELECT id, student_id, full_name FROM students WHERE portal_user_id = ? LIMIT 1');
    $opm_stmt->execute([(int)($user['id'] ?? 0)]);
    $opm_student = $opm_stmt->fetch() ?: null;
}
if (!$opm_student && $student_sid !== '') {
    $opm_student = acc_get_student_by_sid($student_sid) ?: null;
}
$opm_methods     = opm_all_methods(true);
$opm_submissions = $opm_student ? opm_student_submissions((int)$opm_student['id']) : [];
$opm_guidelines  = [
    'bank'           => opm_guideline('bank'),
    'mobile_banking' => opm_guideline('mobile_banking'),
];

$page_title = 'Accounts';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-file-invoice-dollar me-2 text-success"></i>Accounts
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Accounts</li>
        </ol></nav>
    </div>
</div>

<?= flash_show() ?>

<?php if ($student_sid === ''): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i>
    Your account is not yet linked to a student record.
    Please contact the Accounts Office to have your Student ID associated with this login.
</div>
<?php else: ?>

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
<div class="card border-0 shadow-sm mb-3" id="payOnlineCard">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <span class="fw-semibold"><i class="fas fa-globe me-2 text-primary"></i>Pay Online</span>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#payOnlineCollapse">
            <i class="fas fa-hand-holding-usd me-1"></i>Make a Payment
        </button>
    </div>
    <div class="collapse" id="payOnlineCollapse">
        <div class="card-body p-4">
            <div class="alert alert-info small">
                <i class="fas fa-info-circle me-1"></i>
                Pay your fees through a bank deposit / transfer or mobile banking, then submit the payment details here with a receipt.
                After review and approval by the Accounts Office the payment is added to your account. Verification is normally completed
                within <strong>24 hours</strong>, but occasionally may take up to <strong>48 hours</strong>.
            </div>
            <form method="post" action="<?= APP_URL ?>/accounting/online-payment-submit.php" enctype="multipart/form-data" id="payOnlineForm">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Payment Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="opmType">
                            <option value="">Select…</option>
                            <option value="bank">Bank</option>
                            <option value="mobile_banking">Mobile Banking</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" name="method_id" id="opmMethod" required disabled>
                            <option value="">Select the payment type first…</option>
                        </select>
                    </div>
                </div>
                <div id="opmDetails" class="mt-3" style="display:none;"></div>
                <div id="opmGuideline" class="alert alert-warning small mt-3 mb-0" style="display:none; white-space: pre-line;"></div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Paid From (account / wallet name or number) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="paid_from" maxlength="190" required
                               placeholder="e.g. the account name or wallet number you paid from">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Amount Paid (<?= h(acc_currency()) ?>) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount" min="1" step="0.01" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="paid_date" max="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Payment Time</label>
                        <input type="time" class="form-control" name="paid_time">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Transaction / Reference No. <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="transaction_number" maxlength="190" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Receipt / Screenshot <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="receipt" id="opmReceipt" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                        <div class="form-text">JPG, PNG, WEBP or PDF — max 5 MB.</div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
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
    <div class="table-responsive">
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
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var methods = <?= json_encode(array_map(static fn(array $m): array => [
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
    var guidelines = <?= json_encode($opm_guidelines, JSON_UNESCAPED_UNICODE) ?>;

    var typeSel   = document.getElementById('opmType');
    var methodSel = document.getElementById('opmMethod');
    var details   = document.getElementById('opmDetails');
    var guideline = document.getElementById('opmGuideline');
    var receipt   = document.getElementById('opmReceipt');
    var form      = document.getElementById('payOnlineForm');
    if (!typeSel || !methodSel) { return; }

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function row(label, value, mono) {
        return '<tr><th class="text-muted small" style="width: 180px;">' + esc(label) + '</th>'
             + '<td class="fw-semibold' + (mono ? ' font-monospace' : '') + '">' + esc(value) + '</td></tr>';
    }

    typeSel.addEventListener('change', function () {
        var t = typeSel.value;
        methodSel.innerHTML = '';
        details.style.display = 'none';
        details.innerHTML = '';
        if (t === '') {
            methodSel.disabled = true;
            methodSel.innerHTML = '<option value="">Select the payment type first…</option>';
            guideline.style.display = 'none';
            return;
        }
        var opts = '<option value="">Select…</option>';
        methods.forEach(function (m) {
            if (m.type === t) {
                opts += '<option value="' + m.id + '">' + esc(m.title) + (m.charge_note ? ' — ' + esc(m.charge_note) : '') + '</option>';
            }
        });
        methodSel.innerHTML = opts;
        methodSel.disabled = false;
        guideline.textContent = guidelines[t] || '';
        guideline.style.display = guideline.textContent !== '' ? '' : 'none';
    });

    methodSel.addEventListener('change', function () {
        var id = parseInt(methodSel.value, 10) || 0;
        var m = null;
        methods.forEach(function (x) { if (x.id === id) { m = x; } });
        if (!m) { details.style.display = 'none'; details.innerHTML = ''; return; }
        var html = '<div class="border rounded p-3 bg-light">'
                 + '<div class="fw-semibold mb-2"><i class="fas fa-' + (m.type === 'bank' ? 'university' : 'mobile-alt') + ' me-1"></i>'
                 + 'Pay to this ' + (m.type === 'bank' ? 'bank account' : 'wallet') + ':</div>'
                 + '<table class="table table-sm table-borderless mb-0"><tbody>';
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
        html += '</tbody></table></div>';
        details.innerHTML = html;
        details.style.display = '';
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

<!-- Loading indicator -->
<div id="loadingWrap" class="text-center py-5 text-muted">
    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
    Loading your fee information…
</div>

<!-- Error panel -->
<div id="errorWrap" class="alert alert-danger" style="display:none;"></div>

<!-- Fee Schedule & Outstanding Balance -->
<div class="card border-0 shadow-sm mb-3" id="feeScheduleCard" style="display:none;">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <span class="fw-semibold">
            <i class="fas fa-file-invoice-dollar me-2 text-success"></i>Fee Schedule &amp; Outstanding Balance
        </span>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 fs-6"
                  id="totalOutstandingBadge" style="display:none;"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="collapse" data-bs-target="#feeScheduleCollapse">
                <i class="fas fa-list me-1"></i>Details
            </button>
        </div>
    </div>
    <div class="collapse show" id="feeScheduleCollapse">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0" id="feeTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Fee Type</th>
                        <th class="text-end">Total Due</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end fw-bold">Outstanding</th>
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
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Payment Transaction History -->
<div class="card border-0 shadow-sm mb-4" id="transactionHistoryCard" style="display:none;">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <span class="fw-semibold">
            <i class="fas fa-history me-2 text-info"></i>Payment Transaction History
        </span>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-info-subtle text-info border border-info-subtle px-3 py-2"
                  id="transactionCount"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="collapse" data-bs-target="#transactionHistoryCollapse">
                <i class="fas fa-list me-1"></i>History
            </button>
        </div>
    </div>
    <div class="collapse show" id="transactionHistoryCollapse">
        <div class="table-responsive">
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
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php if ($student_sid !== ''): ?>
<script>
const APP_URL = <?= json_encode(APP_URL) ?>;
const CURRENCY = <?= json_encode(acc_currency()) ?>;

function fmt(n) {
    return CURRENCY + ' ' + parseFloat(n).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function feeTypeLabel(type) {
    const map = {
        admission:        'Admission Fee',
        registration:     'Registration Fee',
        semester_tuition: 'Tuition Fee',
        fixed_fee:        'Institutional Fee',
        english_fee:      'English Course Fee',
        other:            'Other Fee',
    };
    return map[type] || type;
}

function renderFeeSummary(data) {
    const s     = data.summary;
    const pkg   = s.package;

    // Student info strip
    document.getElementById('infoName').textContent   = data.student.full_name;
    document.getElementById('infoMeta').textContent   =
        'ID: ' + data.student.student_id + '   |   Program: ' + pkg.program_name;
    document.getElementById('infoStatus').textContent = pkg.student_status ?? 'Active';
    document.getElementById('studentInfoCard').style.display = '';

    const tbody = document.getElementById('feeTableBody');
    tbody.innerHTML = '';

    let grandDue = 0, grandPaid = 0, grandOut = 0;

    function addSectionRow(label) {
        const tr = document.createElement('tr');
        tr.className = 'table-secondary';
        tr.innerHTML = `<td colspan="5" class="ps-4 py-1 small fw-semibold text-muted">
            <i class="fas fa-chevron-right me-1"></i>${label}
        </td>`;
        tbody.appendChild(tr);
    }

    function addRow(label, due, paid, out) {
        grandDue  += due;
        grandPaid += paid;
        grandOut  += out;

        const tr  = document.createElement('tr');
        const pct = (due > 0 && out > 0) ? Math.round((out / due) * 100) : 0;

        tr.innerHTML = `
            <td class="ps-4">
                <div class="small">${label}</div>
                ${due > 0 && out > 0
                    ? `<div class="progress mt-1" style="height:3px;width:100px;">
                           <div class="progress-bar bg-success" style="width:${100 - pct}%"></div>
                           <div class="progress-bar bg-danger opacity-50" style="width:${pct}%"></div>
                       </div>`
                    : ''}
            </td>
            <td class="text-end small">${due > 0 ? fmt(due) : '—'}</td>
            <td class="text-end small text-success">${paid > 0 ? fmt(paid) : '—'}</td>
            <td class="text-end small fw-semibold ${out > 0 ? 'text-danger' : 'text-success'}">
                ${out > 0 ? fmt(out) : (due > 0 ? '<i class="fas fa-check-circle"></i> Paid' : '—')}
            </td>
            <td class="text-center">
                ${out > 0
                    ? '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-clock me-1"></i>Due</span>'
                    : (due > 0 ? '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-check me-1"></i>Paid</span>' : '—')}
            </td>`;
        tbody.appendChild(tr);
    }

    const t = s.totals;

    addSectionRow('Admission');
    addRow('Admission Fee', t.admission.due, t.admission.paid, t.admission.out);

    s.semesters.forEach(sf => {
        const semLabel = sf.semester_label || ('Semester ' + sf.semester_number);
        addSectionRow(semLabel);
        addRow(semLabel + ' – Registration Fee', sf.reg_fee, sf.reg_paid, sf.reg_out);
        sf.monthly_rows.forEach(mr => {
            addRow(
                semLabel + ' – Month ' + mr.month_number + (mr.month_label ? ' (' + mr.month_label + ')' : ''),
                mr.due, mr.paid, mr.out
            );
        });
    });

    document.getElementById('footTotalDue').textContent  = fmt(grandDue);
    document.getElementById('footTotalPaid').textContent = fmt(grandPaid);
    document.getElementById('footTotalOut').textContent  = fmt(grandOut);

    const badge = document.getElementById('totalOutstandingBadge');
    if (grandOut > 0) {
        badge.textContent    = 'Outstanding: ' + fmt(grandOut);
        badge.style.display  = '';
    } else {
        badge.style.display  = 'none';
    }

    document.getElementById('feeScheduleCard').style.display = '';
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
            const statusBadge = p.voucher_status === 'posted'
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
                <td>${statusBadge}</td>`;
            tbody.appendChild(tr);
        });
    }
    card.style.display = '';
}

// Load data on page ready
document.addEventListener('DOMContentLoaded', function () {
    fetch(APP_URL + '/accounting/get-student-fees-self.php', {
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
<?php endif; ?>
