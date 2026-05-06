<?php
/**
 * Fee Collection Invoice – Standalone print page (no admin layout).
 * Displays two copies: Student Copy & Office Copy.
 *
 * Usage: fee-invoice.php?voucher_id=123
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
require_access('accounting');

$voucher_id = (int)($_GET['voucher_id'] ?? 0);
if (!$voucher_id) {
    die('Invalid invoice.');
}

$voucher = acc_get_voucher($voucher_id);
if (!$voucher) {
    die('Voucher not found.');
}

$currency = acc_currency();

// ── Try to load student fee payment record ────────────────────────────────────
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
$sfp = $sfp_stmt->fetch();

// ── Try to load admission fee payment record ──────────────────────────────────
$adm_payment = null;
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
    $adm_payment = $adm_stmt->fetch();
}

// ── Compose display fields ────────────────────────────────────────────────────

$payer_name   = '';
$payer_sid    = '';
$payer_dept   = '';
$payer_prog   = '';
$payer_phone  = '';
$payer_email  = '';
$fee_type_lbl = 'Fee Payment';
$semester_lbl = '';
$month_lbl    = '';

if ($sfp) {
    $payer_name   = $sfp['full_name']      ?? '';
    $payer_sid    = $sfp['student_sid']    ?? '';
    $payer_dept   = $sfp['dept_name']      ?? '';
    $payer_prog   = $sfp['program_name']   ?? '';
    $payer_phone  = $sfp['phone']          ?? '';
    $payer_email  = $sfp['email']          ?? '';
    $fee_type_lbl = acc_fee_type_label($sfp['fee_type']);

    if ($sfp['semester_number']) {
        // Try to get semester label from sfp_semester_fees
        $sf_row = db()->prepare('SELECT semester_label FROM sfp_semester_fees WHERE id = ?');
        $sf_row->execute([$sfp['semester_fee_id']]);
        $sf_row = $sf_row->fetch();
        $semester_lbl = ($sf_row && $sf_row['semester_label'])
            ? $sf_row['semester_label']
            : 'Semester ' . $sfp['semester_number'];
    }
    if ($sfp['month_number']) {
        $month_lbl = 'Month ' . $sfp['month_number'];
    }
} elseif ($adm_payment) {
    $payer_name   = $adm_payment['student_name'] ?? '';
    $payer_sid    = $adm_payment['student_sid']  ?? '';
    $payer_dept   = $adm_payment['dept_name']    ?? '';
    $payer_prog   = $adm_payment['program_name'] ?? '';
    $payer_phone  = $adm_payment['phone']        ?? '';
    $payer_email  = $adm_payment['email']        ?? '';
    $fee_type_lbl = 'Admission Fee';
}

$voucher_number  = $voucher['voucher_number'] ?? '—';
$voucher_date    = date('d F Y', strtotime($voucher['voucher_date']));
$voucher_amount  = number_format((float)$voucher['total_amount'], 2);
$reference       = $voucher['reference']      ?? '';
$narration       = $voucher['narration']      ?? '';
$collected_by    = $voucher['created_by_name'] ?? '—';
$created_at      = date('d M Y, h:i A', strtotime($voucher['created_at']));

// Outstanding balance (only for student fee payments)
$outstanding_str = '';
if ($sfp) {
    $outstanding = acc_total_outstanding((int)$sfp['package_id']);
    $outstanding_str = $currency . ' ' . number_format($outstanding, 2);
}
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

        /* ── Screen controls bar ─────────────────────────────── */
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
        .screen-controls .inv-meta { font-size: 12px; opacity: .9; margin-left: auto; }

        /* ── Page wrapper ─────────────────────────────────────── */
        .print-wrapper {
            padding: 70px 20px 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0;
        }

        /* ── Single invoice copy card ─────────────────────────── */
        .invoice-copy {
            background: #fff;
            width: 740px;
            padding: 0;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }
        .invoice-copy + .invoice-copy {
            border-top: 2px dashed #aaa;
        }

        /* Header band */
        .inv-header {
            background: #1a3c5e;
            color: #fff;
            padding: 14px 24px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .inv-header .uni-name {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: .3px;
        }
        .inv-header .uni-sub {
            font-size: 10px;
            opacity: .8;
            margin-top: 2px;
        }
        .inv-header .copy-label {
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 20px;
            padding: 4px 14px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        /* Title ribbon */
        .inv-title {
            text-align: center;
            border-bottom: 2px solid #1a3c5e;
            padding: 8px 0 6px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #1a3c5e;
            background: #f0f4f8;
        }

        /* Body */
        .inv-body {
            padding: 14px 24px 16px;
        }

        /* Two-column meta row */
        .inv-meta-row {
            display: flex;
            gap: 16px;
            margin-bottom: 10px;
        }
        .inv-meta-col {
            flex: 1;
            background: #f8fafc;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 8px 12px;
        }
        .meta-line { display: flex; align-items: baseline; margin-bottom: 4px; }
        .meta-label {
            min-width: 110px;
            color: #6b7280;
            font-size: 10.5px;
            flex-shrink: 0;
        }
        .meta-value {
            font-weight: 600;
            font-size: 11.5px;
            color: #111;
        }

        /* Student / Payer box */
        .payer-box {
            border: 1px solid #1a3c5e;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 10px;
            background: #f0f6ff;
        }
        .payer-box .payer-name {
            font-size: 14px;
            font-weight: 700;
            color: #1a3c5e;
            margin-bottom: 3px;
        }
        .payer-meta { font-size: 11px; color: #555; line-height: 1.7; }

        /* Fee detail table */
        .fee-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 11.5px;
        }
        .fee-table thead tr {
            background: #1a3c5e;
            color: #fff;
        }
        .fee-table thead th {
            padding: 6px 10px;
            font-weight: 600;
            font-size: 11px;
            letter-spacing: .3px;
        }
        .fee-table tbody td {
            padding: 7px 10px;
            border-bottom: 1px solid #e9ecef;
        }
        .fee-table tfoot td {
            padding: 7px 10px;
            background: #f8fafc;
            font-weight: 700;
            font-size: 12px;
            border-top: 2px solid #1a3c5e;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* Amount highlight */
        .amount-highlight {
            font-size: 17px;
            font-weight: 700;
            color: #1a6e3c;
        }

        /* Outstanding notice (student copy only) */
        .outstanding-note {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: 4px;
            padding: 6px 12px;
            font-size: 11px;
            color: #795548;
            margin-bottom: 10px;
        }
        .outstanding-note strong { color: #c62828; }

        /* Signature row */
        .sig-row {
            display: flex;
            justify-content: space-between;
            margin-top: 16px;
            gap: 20px;
        }
        .sig-box {
            flex: 1;
            border-top: 1px solid #555;
            padding-top: 4px;
            text-align: center;
            font-size: 10px;
            color: #555;
        }

        /* Footer */
        .inv-footer {
            text-align: center;
            font-size: 9.5px;
            color: #888;
            padding: 8px 24px 10px;
            border-top: 1px solid #e9ecef;
            background: #f8fafc;
        }

        /* Dashed cut-line between the two copies */
        .cut-line {
            width: 740px;
            text-align: center;
            font-size: 10px;
            color: #aaa;
            border-top: 1.5px dashed #bbb;
            padding-top: 4px;
            margin: 0;
        }

        /* ── Print styles ─────────────────────────────────────── */
        @media print {
            body { background: #fff; }
            .screen-controls { display: none !important; }
            .print-wrapper { padding: 0; }
            .invoice-copy { box-shadow: none; width: 100%; }
            .cut-line { width: 100%; }
            @page { margin: 10mm 10mm; }
        }
    </style>
</head>
<body>

<!-- Screen control bar (hidden on print) -->
<div class="screen-controls">
    <button onclick="window.print()">&#128438; Print Invoice</button>
    <a href="javascript:window.close()" class="btn-close-inv">✕ Close</a>
    <span class="inv-meta">
        Voucher: <strong><?= h($voucher_number) ?></strong> &nbsp;|&nbsp;
        <?= h($payer_name) ?>
        <?php if ($payer_sid): ?> &nbsp;(<?= h($payer_sid) ?>)<?php endif; ?>
    </span>
</div>

<div class="print-wrapper">

<?php
// Render one invoice copy: $copy_label = 'Student Copy' | 'Office Copy', $show_outstanding = true|false
function render_copy(
    string $copy_label,
    bool   $show_outstanding,
    string $currency,
    string $voucher_number,
    string $voucher_date,
    string $voucher_amount,
    string $reference,
    string $narration,
    string $collected_by,
    string $created_at,
    string $payer_name,
    string $payer_sid,
    string $payer_dept,
    string $payer_prog,
    string $payer_phone,
    string $payer_email,
    string $fee_type_lbl,
    string $semester_lbl,
    string $month_lbl,
    string $outstanding_str
): void {
?>
<div class="invoice-copy">

    <!-- Header -->
    <div class="inv-header">
        <div>
            <div class="uni-name">Prime University</div>
            <div class="uni-sub">Mirpur-1, Dhaka, Bangladesh &nbsp;|&nbsp; primeuniversity.ac.bd</div>
        </div>
        <div class="copy-label"><?= h($copy_label) ?></div>
    </div>

    <!-- Title ribbon -->
    <div class="inv-title">Fee Collection Receipt</div>

    <div class="inv-body">

        <!-- Top meta: voucher info + collection info -->
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

        <!-- Payer info -->
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

        <!-- Fee detail table -->
        <table class="fee-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fee Description</th>
                    <?php if ($semester_lbl): ?><th>Semester</th><?php endif; ?>
                    <?php if ($month_lbl):    ?><th>Month</th><?php endif; ?>
                    <th class="text-right">Amount (<?= h($currency) ?>)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>
                        <?= h($fee_type_lbl) ?>
                        <?php if ($narration): ?>
                            <br><span style="font-size:10px;color:#6b7280;"><?= h($narration) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php if ($semester_lbl): ?><td><?= h($semester_lbl) ?></td><?php endif; ?>
                    <?php if ($month_lbl):    ?><td><?= h($month_lbl) ?></td><?php endif; ?>
                    <td class="text-right"><?= h($voucher_amount) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="<?= 2 + ($semester_lbl ? 1 : 0) + ($month_lbl ? 1 : 0) ?>">
                        <strong>Total Amount Received</strong>
                    </td>
                    <td class="text-right">
                        <span class="amount-highlight"><?= h($currency) ?> <?= h($voucher_amount) ?></span>
                    </td>
                </tr>
            </tfoot>
        </table>

        <?php if ($show_outstanding && $outstanding_str): ?>
        <div class="outstanding-note">
            ⚠ &nbsp;Outstanding balance after this payment:
            <strong><?= h($outstanding_str) ?></strong>
        </div>
        <?php endif; ?>

        <!-- Signature row -->
        <div class="sig-row">
            <div class="sig-box">Receiver's Signature</div>
            <div class="sig-box">Accounts Officer</div>
            <?php if ($copy_label === 'Office Copy'): ?>
            <div class="sig-box">Head of Accounts / Controller</div>
            <?php else: ?>
            <div class="sig-box">Student's Signature</div>
            <?php endif; ?>
        </div>

    </div><!-- /inv-body -->

    <div class="inv-footer">
        This is a computer-generated receipt. Please retain it for your records. &nbsp;|&nbsp; Prime University, Mirpur-1, Dhaka
    </div>

</div><!-- /invoice-copy -->
<?php } ?>

<?php render_copy(
    'Student Copy',
    true,  // show outstanding
    $currency, $voucher_number, $voucher_date, $voucher_amount,
    $reference, $narration, $collected_by, $created_at,
    $payer_name, $payer_sid, $payer_dept, $payer_prog, $payer_phone, $payer_email,
    $fee_type_lbl, $semester_lbl, $month_lbl, $outstanding_str
); ?>

<div class="cut-line">✂ &nbsp; Cut here &nbsp; ✂</div>

<?php render_copy(
    'Office Copy',
    false, // no outstanding on office copy
    $currency, $voucher_number, $voucher_date, $voucher_amount,
    $reference, $narration, $collected_by, $created_at,
    $payer_name, $payer_sid, $payer_dept, $payer_prog, $payer_phone, $payer_email,
    $fee_type_lbl, $semester_lbl, $month_lbl, $outstanding_str
); ?>

</div><!-- /print-wrapper -->

<script>
// Auto-open print dialog when the page loads
window.addEventListener('load', function() {
    // Small delay ensures styles are rendered before print dialog opens
    setTimeout(function() { window.print(); }, 400);
});
</script>
</body>
</html>
