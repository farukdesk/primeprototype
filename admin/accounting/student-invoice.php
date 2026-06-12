<?php
/**
 * Student-facing Fee Receipt – shows STUDENT COPY only.
 * Accessible to users with the student-accounts-portal module.
 * Security: voucher must belong to the logged-in student's own record.
 *
 * Usage: student-invoice.php?voucher_id=123
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();

if (!is_portal_student()) {
    require_access('student-accounts-portal');
}

require_once __DIR__ . '/helpers.php';

$user = auth_user();

if (is_portal_student()) {
    // Portal student: look up their student_id from the students table
    $sp_stmt = db()->prepare('SELECT student_id FROM students WHERE portal_user_id = ? LIMIT 1');
    $sp_stmt->execute([(int)$user['id']]);
    $sp_row      = $sp_stmt->fetch();
    $student_sid = trim((string)($sp_row['student_id'] ?? ''));
} else {
    $student_sid = trim((string)($user['student_sid'] ?? ''));
}

if ($student_sid === '') {
    die('Your account is not linked to a student record. Please contact the accounts office.');
}

$voucher_id = (int)($_GET['voucher_id'] ?? 0);
if (!$voucher_id) {
    die('Invalid invoice.');
}

$voucher = acc_get_voucher($voucher_id);
if (!$voucher) {
    die('Voucher not found.');
}

// ── Security: verify this voucher belongs to the logged-in student ────────────
$sfp_stmt = db()->prepare(
    'SELECT sp.*,
            s.full_name, s.student_id AS student_sid, s.email, s.phone,
            d.name AS dept_name, p.program_name
     FROM sfp_payments sp
     JOIN students s ON s.id = sp.student_id
     LEFT JOIN dept_departments d       ON d.id = s.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = s.program_id
     WHERE sp.voucher_id = ?
     LIMIT 1'
);
$sfp_stmt->execute([$voucher_id]);
$sfp = $sfp_stmt->fetch() ?: null;

$adm = null;
if (!$sfp) {
    $adm_stmt = db()->prepare(
        'SELECT afp.*,
                a.student_name, a.app_number, a.present_contact AS phone,
                a.present_email AS email, a.office_student_id AS student_sid,
                d.name AS dept_name, pr.program_name
         FROM adm_admission_fee_payments afp
         JOIN admissions_applications a ON a.id = afp.application_id
         LEFT JOIN dept_departments d       ON d.id = a.dept_id
         LEFT JOIN dept_academic_programs pr ON pr.id = a.program_id
         WHERE afp.voucher_id = ?
         LIMIT 1'
    );
    $adm_stmt->execute([$voucher_id]);
    $adm = $adm_stmt->fetch() ?: null;
}

// Confirm ownership
$record_sid = '';
if ($sfp) {
    $record_sid = trim((string)($sfp['student_sid'] ?? ''));
} elseif ($adm) {
    $record_sid = trim((string)($adm['student_sid'] ?? ''));
} else {
    die('Invoice not found.');
}

if ($record_sid === '' || strcasecmp($record_sid, $student_sid) !== 0) {
    http_response_code(403);
    die('You do not have permission to view this invoice.');
}

// ── Build fee row ──────────────────────────────────────────────────────────────
$payer_name  = '';
$payer_sid   = '';
$payer_dept  = '';
$payer_prog  = '';
$payer_phone = '';
$payer_email = '';
$payment_method_lbl = 'Cash';
$transaction_number = '';
$fee_rows = [];

$row_fee_lbl = 'Fee Payment';
$row_sem_lbl = '';
$row_mon_lbl = '';
$row_amount  = (float)($voucher['total_amount'] ?? 0);
$row_narration = $voucher['narration'] ?? '';

if ($sfp) {
    $payer_name  = $sfp['full_name']    ?? '';
    $payer_sid   = $sfp['student_sid']  ?? '';
    $payer_dept  = $sfp['dept_name']    ?? '';
    $payer_prog  = $sfp['program_name'] ?? '';
    $payer_phone = $sfp['phone']        ?? '';
    $payer_email = $sfp['email']        ?? '';
    $row_fee_lbl = acc_fee_type_label($sfp['fee_type']);
    $payment_method_lbl = acc_payment_method_label(
        (string)($sfp['payment_method'] ?? 'cash'),
        $sfp['mobile_banking_provider'] ?? null
    );
    $transaction_number = (string)($sfp['transaction_number'] ?? '');

    if ($sfp['semester_number']) {
        $sf_row = db()->prepare('SELECT semester_label FROM sfp_semester_fees WHERE id = ?');
        $sf_row->execute([$sfp['semester_fee_id']]);
        $sf_row = $sf_row->fetch();
        $row_sem_lbl = ($sf_row && $sf_row['semester_label'])
            ? $sf_row['semester_label']
            : 'Semester ' . $sfp['semester_number'];
    }
    if ($sfp['month_number']) {
        $row_mon_lbl = 'Month ' . $sfp['month_number'];
        $summary = acc_student_fee_summary((int)$sfp['student_id']);
        if ($summary) {
            foreach ($summary['semesters'] as $sem) {
                if ((int)$sem['semester_number'] !== (int)$sfp['semester_number']) continue;
                foreach (($sem['monthly_rows'] ?? []) as $mr) {
                    if ((int)$mr['month_number'] === (int)$sfp['month_number']) {
                        $row_mon_lbl .= ' (' . ($mr['month_label'] ?? '') . ')';
                        break 2;
                    }
                }
            }
        }
    }
} elseif ($adm) {
    $payer_name  = $adm['student_name'] ?? '';
    $payer_sid   = $adm['student_sid']  ?? '';
    $payer_dept  = $adm['dept_name']    ?? '';
    $payer_prog  = $adm['program_name'] ?? '';
    $payer_phone = $adm['phone']        ?? '';
    $payer_email = $adm['email']        ?? '';
    $row_fee_lbl = 'Admission Fee';
    $payment_method_lbl = acc_payment_method_label(
        (string)($adm['payment_method'] ?? 'cash'),
        $adm['mobile_banking_provider'] ?? null
    );
    $transaction_number = (string)($adm['transaction_number'] ?? '');
}

$fee_rows[] = [
    'voucher_number' => $voucher['voucher_number'] ?? '—',
    'fee_type_lbl'   => $row_fee_lbl,
    'semester_lbl'   => $row_sem_lbl,
    'month_lbl'      => $row_mon_lbl,
    'amount'         => $row_amount,
    'narration'      => $row_narration,
];

$currency          = acc_currency();
$voucher_number    = $voucher['voucher_number'] ?? '—';
$voucher_date      = date('d F Y', strtotime($voucher['voucher_date']));
$total_amount      = $row_amount;
$voucher_amount    = number_format($total_amount, 2);
$reference         = $voucher['reference'] ?? '';
$narration         = $voucher['narration'] ?? '';
$collected_by      = $voucher['created_by_name'] ?? '—';
$created_at        = date('d M Y, h:i A', strtotime($voucher['created_at']));
$invoice_signature_name = $collected_by;
$university_logo_url = acc_university_logo_url();
$university_address  = acc_university_address();
$university_website  = acc_university_website();
$done_url = APP_URL . '/accounting/student-portal.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Receipt – <?= h($voucher_number) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            background: #e8e8e8;
            color: #222;
        }
        .screen-controls {
            position: fixed; top: 0; left: 0; right: 0; z-index: 999;
            background: #1a3c5e; color: #fff;
            padding: 9px 20px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 2px 6px rgba(0,0,0,.3);
        }
        .screen-controls button, .screen-controls a {
            background: #27ae60; color: #fff; border: none;
            padding: 6px 16px; border-radius: 4px; cursor: pointer;
            font-size: 13px; text-decoration: none;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .screen-controls a.btn-close-inv { background: #6c757d; }
        .screen-controls button.btn-done-inv { background: #0d6efd; }
        .screen-controls .inv-meta { font-size: 12px; opacity: .9; margin-left: auto; }
        .print-wrapper {
            padding: 70px 20px 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .invoice-copy {
            background: #fff;
            width: 740px;
            padding: 0;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }
        .inv-header {
            background: #1a3c5e;
            color: #fff;
            padding: 14px 24px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .inv-header .brand-wrap { display: flex; align-items: center; gap: 10px; }
        .inv-header .brand-logo {
            width: 42px; height: 42px; border-radius: 50%;
            object-fit: contain; background: #fff; padding: 3px;
        }
        .inv-header .uni-name { font-size: 17px; font-weight: 700; letter-spacing: .3px; }
        .inv-header .uni-sub { font-size: 10px; opacity: .8; margin-top: 2px; line-height: 1.35; }
        .inv-header .copy-label {
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 20px;
            padding: 4px 14px;
            font-size: 11px; font-weight: 700;
            letter-spacing: .5px; text-transform: uppercase;
        }
        .inv-title {
            text-align: center;
            border-bottom: 2px solid #1a3c5e;
            padding: 8px 0 6px;
            font-size: 13px; font-weight: 700;
            letter-spacing: 1px; text-transform: uppercase;
            color: #1a3c5e; background: #f0f4f8;
        }
        .inv-body { padding: 14px 24px 16px; }
        .inv-meta-row { display: flex; gap: 16px; margin-bottom: 10px; }
        .inv-meta-col {
            flex: 1; background: #f8fafc;
            border: 1px solid #dee2e6; border-radius: 4px; padding: 8px 12px;
        }
        .meta-line { display: flex; align-items: baseline; margin-bottom: 4px; }
        .meta-label { min-width: 110px; color: #6b7280; font-size: 10.5px; flex-shrink: 0; }
        .meta-value { font-weight: 600; font-size: 11.5px; color: #111; }
        .payer-box {
            border: 1px solid #1a3c5e; border-radius: 4px;
            padding: 8px 12px; margin-bottom: 10px; background: #f0f6ff;
        }
        .payer-box .payer-name { font-size: 14px; font-weight: 700; color: #1a3c5e; margin-bottom: 3px; }
        .payer-meta { font-size: 11px; color: #555; line-height: 1.7; }
        .fee-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 11.5px; }
        .fee-table thead tr { background: #1a3c5e; color: #fff; }
        .fee-table thead th { padding: 6px 10px; font-weight: 600; font-size: 11px; letter-spacing: .3px; }
        .fee-table tbody td { padding: 7px 10px; border-bottom: 1px solid #e9ecef; }
        .fee-table tfoot td {
            padding: 7px 10px; background: #f8fafc;
            font-weight: 700; font-size: 12px; border-top: 2px solid #1a3c5e;
        }
        .text-right { text-align: right; }
        .amount-highlight { font-size: 17px; font-weight: 700; color: #1a6e3c; }
        .sig-row { display: flex; justify-content: space-between; margin-top: 16px; gap: 20px; }
        .sig-box {
            flex: 1; border-top: 1px solid #555;
            padding-top: 4px; text-align: center; font-size: 10px; color: #555;
        }
        .inv-footer {
            text-align: center; font-size: 9.5px; color: #888;
            padding: 8px 24px 10px;
            border-top: 1px solid #e9ecef; background: #f8fafc;
        }
        @media print {
            body { background: #fff; }
            .screen-controls { display: none !important; }
            .print-wrapper { padding: 0; }
            .invoice-copy { box-shadow: none; width: 100%; }
            @page { size: A4; margin: 10mm 10mm; }
        }
    </style>
</head>
<body>

<div class="screen-controls">
    <button onclick="window.print()">&#128438; Print Invoice</button>
    <button type="button" class="btn-done-inv" onclick="doneInvoice()">&#10003; Done</button>
    <a href="javascript:window.close()" class="btn-close-inv">✕ Close</a>
    <span class="inv-meta">
        Voucher: <strong><?= h($voucher_number) ?></strong> &nbsp;|&nbsp;
        <?= h($payer_name) ?>
        <?php if ($payer_sid): ?> &nbsp;(<?= h($payer_sid) ?>)<?php endif; ?>
    </span>
</div>

<div class="print-wrapper">

<div class="invoice-copy">

    <div class="inv-header">
        <div class="brand-wrap">
            <img src="<?= h($university_logo_url) ?>" alt="Prime University Logo" class="brand-logo">
            <div>
                <div class="uni-name">Prime University</div>
                <div class="uni-sub"><?= h($university_address) ?><br><?= h($university_website) ?></div>
            </div>
        </div>
        <div class="copy-label">Student Copy</div>
    </div>

    <div class="inv-title">Fee Collection Receipt</div>

    <div class="inv-body">

        <div class="inv-meta-row">
            <div class="inv-meta-col">
                <div class="meta-line">
                    <span class="meta-label">Receipt No.</span>
                    <span class="meta-value"><?= h($voucher_number) ?></span>
                </div>
                <div class="meta-line">
                    <span class="meta-label">Date</span>
                    <span class="meta-value"><?= h($voucher_date) ?></span>
                </div>
                <?php if ($reference): ?>
                <div class="meta-line">
                    <span class="meta-label">Reference</span>
                    <span class="meta-value"><?= h($reference) ?></span>
                </div>
                <?php endif; ?>
                <div class="meta-line">
                    <span class="meta-label">Payment Method</span>
                    <span class="meta-value"><?= h($payment_method_lbl) ?></span>
                </div>
                <?php if ($transaction_number): ?>
                <div class="meta-line">
                    <span class="meta-label">Transaction #</span>
                    <span class="meta-value"><?= h($transaction_number) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="inv-meta-col">
                <div class="meta-line">
                    <span class="meta-label">Collected By</span>
                    <span class="meta-value"><?= h($collected_by) ?></span>
                </div>
                <div class="meta-line">
                    <span class="meta-label">Issued At</span>
                    <span class="meta-value"><?= h($created_at) ?></span>
                </div>
            </div>
        </div>

        <div class="payer-box">
            <div class="payer-name"><?= h($payer_name ?: '—') ?></div>
            <div class="payer-meta">
                <?php if ($payer_sid): ?>Student ID: <strong><?= h($payer_sid) ?></strong><?php endif; ?>
                <?php if ($payer_dept): ?> &nbsp;|&nbsp; Department: <strong><?= h($payer_dept) ?></strong><?php endif; ?>
                <?php if ($payer_prog): ?><br>Program: <strong><?= h($payer_prog) ?></strong><?php endif; ?>
                <?php if ($payer_phone): ?> &nbsp;|&nbsp; Mobile: <?= h($payer_phone) ?><?php endif; ?>
                <?php if ($payer_email): ?> &nbsp;|&nbsp; Email: <?= h($payer_email) ?><?php endif; ?>
            </div>
        </div>

        <table class="fee-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fee Description</th>
                    <th class="text-right">Amount (<?= h($currency) ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fee_rows as $i => $row): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <?= h($row['fee_type_lbl']) ?>
                        <?php if ($row['semester_lbl']): ?>
                            <span style="font-size:10px;color:#555;"> – <?= h($row['semester_lbl']) ?></span>
                        <?php endif; ?>
                        <?php if ($row['month_lbl']): ?>
                            <span style="font-size:10px;color:#555;"> / <?= h($row['month_lbl']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right"><?= h(number_format((float)$row['amount'], 2)) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($narration): ?>
                <tr>
                    <td colspan="3" style="font-size:10px;color:#6b7280;padding:4px 10px 6px;"><?= h($narration) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2"><strong>Total Amount Received</strong></td>
                    <td class="text-right">
                        <span class="amount-highlight"><?= h($currency) ?> <?= h($voucher_amount) ?></span>
                    </td>
                </tr>
            </tfoot>
        </table>

        <div class="sig-row">
            <div class="sig-box"><?= h($invoice_signature_name) ?><br><span style="font-size:9px;">Collected By</span></div>
        </div>

    </div>

    <div class="inv-footer">
        This is a computer-generated receipt. Please retain it for your records. &nbsp;|&nbsp; Prime University, <?= h($university_address) ?>
    </div>

</div><!-- /invoice-copy -->
</div><!-- /print-wrapper -->

<script>
const DONE_URL = <?= json_encode($done_url) ?>;
function doneInvoice() {
    if (window.opener && !window.opener.closed) {
        window.opener.focus();
        window.close();
        return;
    }
    window.location.href = DONE_URL;
}
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 400);
});
window.addEventListener('afterprint', function () { doneInvoice(); });
</script>
</body>
</html>
