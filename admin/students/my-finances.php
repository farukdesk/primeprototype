<?php
/**
 * Student Portal – My Finances
 * Allows the logged-in student portal user to view their own fee schedule,
 * outstanding balance, and payment transaction history (read-only).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../accounting/helpers.php';

if (!is_portal_student()) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

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
        badge.textContent   = 'Outstanding: ' + fmt(grandOut);
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
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
