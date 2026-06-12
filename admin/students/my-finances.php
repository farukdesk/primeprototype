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
        registration:     'Registration Fee',
        semester_tuition: 'Tuition Fee',
        fixed_fee:        'Institutional Fee',
        english_fee:      'English Course Fee',
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
                ${due > 0
                    ? (status !== null
                        ? statusBadge(status)
                        : (out > 0
                            ? '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-clock me-1"></i>Due</span>'
                            : '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-check me-1"></i>Paid</span>'))
                    : '—'}
            </td>`;
        tbody.appendChild(tr);
    }

    const t = s.totals;

    addSectionRow('Admission', []);
    addRow('Admission Fee', t.admission.due, t.admission.paid, t.admission.out, null, null);

    s.semesters.forEach(sf => {
        const semLabel = sf.semester_label || ('Semester ' + sf.semester_number);
        addSectionRow(semLabel, sf.scholarships || []);
        addRow(semLabel + ' – Registration Fee', sf.reg_fee, sf.reg_paid, sf.reg_out, null, null);
        sf.monthly_rows.forEach(mr => {
            addRow(
                semLabel + ' – Month ' + mr.month_number + (mr.month_label ? ' (' + mr.month_label + ')' : ''),
                mr.due, mr.paid, mr.out,
                mr.cal_month || null,
                mr.cal_year  || null
            );
        });
    });

    document.getElementById('footTotalDue').textContent  = fmt(grandDue);
    document.getElementById('footTotalPaid').textContent = fmt(grandPaid);
    document.getElementById('footTotalOut').textContent  = fmt(grandOut);

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
