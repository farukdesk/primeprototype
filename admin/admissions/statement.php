<?php
/**
 * Admissions – Financial Statement of Payment
 * Standalone print page (no admin layout), aligned with student-accounts statement pattern.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
require_access('admissions');

$id  = (int)($_GET['id'] ?? 0);
$app = adm_get($id);

$has_package = !empty($app['financial_package_name']);

$total_semesters  = (int)($app['financial_total_semesters'] ?? 0);
$total_months     = (int)($app['financial_total_months'] ?? 0);
$tuition_sem      = (float)($app['financial_tuition_per_semester'] ?? 0);
$admission_fee    = (float)($app['financial_admission_fee'] ?? 0);
$reg_fee_sem      = (float)($app['financial_registration_fee_per_semester'] ?? 0);
$fixed_total      = (float)($app['financial_fixed_institutional_fees'] ?? 0);
$english_total    = (float)($app['financial_english_course_fee'] ?? 0);
$form_id_fee      = (float)($app['financial_form_id_fee'] ?? 0);

$tuition_total    = $tuition_sem * $total_semesters;
$reg_total        = $reg_fee_sem * $total_semesters;
$grand_total      = $admission_fee + $reg_total + $tuition_total + $fixed_total + $english_total + $form_id_fee;

$fixed_per_sem    = $total_semesters > 0 ? round($fixed_total / $total_semesters, 2) : 0.0;
$english_per_sem  = $total_semesters > 0 ? round($english_total / $total_semesters, 2) : 0.0;
$regular_sem_pay  = $tuition_sem + $reg_fee_sem + $fixed_per_sem + $english_per_sem;
$admission_payable = $admission_fee + $reg_fee_sem + $form_id_fee;
$monthly_after_admission = $total_months > 0 ? round(($tuition_total + $fixed_total + $english_total) / $total_months, 2) : 0.0;

$admission_form_fee = (int)floor($form_id_fee / 2);
$admission_id_fee   = $form_id_fee - $admission_form_fee;
$date_today         = date('d F Y');
$page_title         = 'Statement of Payment – ' . ($app['student_name'] ?? 'Admission Applicant');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.25; background: #f0f2f5; color: #222; }

        .screen-controls {
            position: fixed; top: 0; left: 0; right: 0; z-index: 999;
            background: #1e3a5f; color: #fff; padding: 8px 20px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.25);
        }
        .screen-controls button, .screen-controls a {
            background: #2563eb; color: #fff; border: none;
            padding: 5px 14px; border-radius: 5px; cursor: pointer;
            font-size: 12px; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .screen-controls a.back-btn { background: #64748b; }
        .screen-controls span { font-size: 12px; opacity: 0.85; }

        .print-wrapper { padding: 52px 16px 20px; }

        .statement-page {
            background: #fff;
            width: 794px;
            min-height: 1123px;
            padding: 14px 24px 12px;
            margin: 0 auto 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }

        .univ-header {
            text-align: center;
            border-bottom: 2px solid #1e3a5f;
            padding-bottom: 5px;
            margin-bottom: 6px;
        }
        .univ-header img.logo {
            height: 38px; margin-bottom: 2px; display: block; margin-left: auto; margin-right: auto;
        }
        .univ-name {
            font-size: 14px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .05em; color: #1e3a5f;
        }
        .univ-sub { font-size: 10px; color: #555; margin-top: 1px; }
        .fee-table tr.visual-sep td { padding: 0; height: 2px; background: #f8f8f8; border: none; }

        .doc-title {
            text-align: center; font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em;
            background: #1e3a5f; color: #fff;
            padding: 4px 0; margin-bottom: 6px;
        }

        .student-info-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 11px; }
        .student-info-table td { padding: 2px 4px; border: 1px solid #ddd; vertical-align: top; }
        .student-info-table td.lbl { background: #eef2f8; color: #1e3a5f; font-weight: 700; width: 30%; }
        .student-info-table td.val { font-weight: 500; }

        .sec-heading {
            font-size: 10.5px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; background: #eef2f8; color: #1e3a5f;
            padding: 3px 7px; margin: 6px 0 3px;
            border-left: 3px solid #2563eb;
        }

        .fee-table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 2px; }
        .fee-table th { background: #1e3a5f; color: #fff; padding: 3px 6px; text-align: left; border: 1px solid #1e3a5f; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; }
        .fee-table th.amt { text-align: right; }
        .fee-table td { border: 1px solid #ddd; padding: 2px 6px; vertical-align: middle; }
        .fee-table td.amt { text-align: right; font-weight: 500; }
        .fee-table tr.subtotal td { background: #eef2f8; font-weight: 700; }
        .fee-table tr.total-row td { background: #1e3a5f; color: #fff; font-weight: 700; font-size: 11px; }
        .fee-table tr.total-row td.amt { text-align: right; }
        .fee-table tr.highlight td { background: #fff8e1; font-weight: 600; }
        .fee-table td.serial { color: #555; width: 22px; text-align: center; }

        .note-box {
            border: 1px solid #e5e7eb; padding: 4px 8px; margin-top: 6px;
            font-size: 10px; color: #374151; background: #fafafa;
            line-height: 1.35;
        }
        .note-box strong { color: #1e3a5f; }

        .sig-section {
            margin-top: 10px;
            display: flex; justify-content: space-between; gap: 8px;
        }
        .sig-block { text-align: center; flex: 1; }
        .sig-line {
            border-top: 1px solid #555;
            margin-top: 22px; padding-top: 3px;
            font-size: 9.5px; color: #374151; font-weight: 600;
        }
        .sig-subtitle { font-size: 9px; color: #6b7280; margin-top: 2px; }

        .date-issued-top {
            text-align: right; font-size: 10px; color: #444; font-weight: 600;
            margin-bottom: 4px;
        }

        @media print {
            @page { size: A4 portrait; margin: 0; }
            .screen-controls { display: none !important; }
            body { background: #fff; line-height: 1.1; }
            .print-wrapper { padding: 0; }
            .statement-page {
                box-shadow: none; margin: 0;
                min-height: unset;
                width: 210mm;
                padding: 8mm 10mm 6mm;
            }
        }
    </style>
</head>
<body>

<div class="screen-controls">
    <button onclick="window.print()">🖨 Print / Save as PDF</button>
    <a href="<?= APP_URL ?>/admissions/index.php" class="back-btn">← Back to Admissions</a>
    <span><?= h($app['app_number']) ?> — <?= h($app['student_name']) ?></span>
</div>

<div class="print-wrapper">
<div class="statement-page">

    <div class="univ-header">
        <img src="<?= LOGO_URL ?>" alt="Prime University Logo" class="logo" onerror="this.style.display='none'">
        <div class="univ-sub">114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh | www.primeuniversity.ac.bd</div>
    </div>

    <div class="doc-title">Statement of Payment</div>
    <div class="date-issued-top">Date of Issue: <?= $date_today ?></div>

    <table class="student-info-table">
        <tr>
            <td class="lbl">Application No</td>
            <td class="val"><?= h($app['app_number']) ?></td>
            <td class="lbl">Assigned Package</td>
            <td class="val"><?= h($app['financial_package_name'] ?? 'Not assigned') ?></td>
        </tr>
        <tr>
            <td class="lbl">Applicant Name</td>
            <td class="val" colspan="3"><?= h($app['student_name']) ?></td>
        </tr>
        <tr>
            <td class="lbl">Department</td>
            <td class="val"><?= h($app['dept_name'] ?? '') ?></td>
            <td class="lbl">Program</td>
            <td class="val"><?= h($app['program_name'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="lbl">Session</td>
            <td class="val"><?= h($app['session_name'] ?? ($app['admitted_semester'] ?? '')) ?></td>
            <td class="lbl">Total Duration</td>
            <td class="val"><?= (int)$total_semesters ?> semesters, <?= (int)$total_months ?> months</td>
        </tr>
    </table>

    <?php if (!$has_package): ?>
        <div class="note-box">
            <strong>Note:</strong> No financial package is assigned for this admission application yet.
        </div>
    <?php else: ?>

    <div class="sec-heading">Fees Breakdown</div>
    <table class="fee-table">
        <thead>
            <tr>
                <th style="width:26px;">#</th>
                <th>Description</th>
                <th class="amt" style="width:130px;">Amount (BDT)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="serial">1</td>
                <td>Admission Fee</td>
                <td class="amt"><?= number_format($admission_fee, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">2</td>
                <td>Registration Fee
                    <span style="font-size:9.5px;color:#6b7280;">(<?= number_format($reg_fee_sem, 2) ?> × <?= $total_semesters ?> semesters)</span>
                </td>
                <td class="amt"><?= number_format($reg_total, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">3</td>
                <td>English Language Fee</td>
                <td class="amt"><?= number_format($english_total, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">4</td>
                <td>Tuition Fee
                    <span style="font-size:9.5px;color:#6b7280;">(<?= number_format($tuition_sem, 2) ?> × <?= $total_semesters ?> semesters)</span>
                </td>
                <td class="amt"><?= number_format($tuition_total, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">5</td>
                <td>Institutional &amp; Development Fees</td>
                <td class="amt"><?= number_format($fixed_total, 2) ?></td>
            </tr>
            <tr class="total-row">
                <td colspan="2"><strong>Total Regular Cost</strong></td>
                <td class="amt"><strong><?= number_format($grand_total, 2) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div class="sec-heading">Regular Semester &amp; First Semester Payment Details</div>
    <table class="fee-table">
        <thead>
            <tr>
                <th style="width:26px;">#</th>
                <th>Description</th>
                <th class="amt" style="width:130px;">Amount (BDT)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="serial">1</td>
                <td>Per Semester Tuition Fee</td>
                <td class="amt"><?= number_format($tuition_sem, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">2</td>
                <td>Per Semester Institutional &amp; Development Fee</td>
                <td class="amt"><?= number_format($fixed_per_sem, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">3</td>
                <td>Per Semester English Language Fee</td>
                <td class="amt"><?= number_format($english_per_sem, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">4</td>
                <td>Per Semester Registration Fee</td>
                <td class="amt"><?= number_format($reg_fee_sem, 2) ?></td>
            </tr>
            <tr class="subtotal">
                <td colspan="2"><strong>Total Regular Payable per Semester</strong></td>
                <td class="amt"><strong><?= number_format($regular_sem_pay, 2) ?></strong></td>
            </tr>
            <tr class="visual-sep"><td colspan="3"></td></tr>
            <tr>
                <td class="serial">5</td>
                <td>Total Scholarship (First Semester)</td>
                <td class="amt">—</td>
            </tr>
            <tr class="subtotal">
                <td colspan="2"><strong>Total First Semester Payable Amount</strong></td>
                <td class="amt"><strong><?= number_format($regular_sem_pay, 2) ?></strong></td>
            </tr>
            <tr class="visual-sep"><td colspan="3"></td></tr>
            <tr class="highlight">
                <td colspan="2"><strong>First Semester Monthly Payment</strong>
                    <span style="font-size:9.5px; color:#92400e; font-weight:400;">
                        (<?= number_format($tuition_total, 2) ?> Tuition + <?= number_format($fixed_total, 2) ?> Institutional &amp; Dev. Fee + <?= number_format($english_total, 2) ?> English Fee) ÷ <?= max(1, (int)$total_months) ?> months
                    </span>
                </td>
                <td class="amt"><strong><?= number_format($monthly_after_admission, 2) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div class="sec-heading">Payment Made at the Time of Admission</div>
    <table class="fee-table">
        <tbody>
            <tr>
                <td class="serial">1</td>
                <td>Admission Fee</td>
                <td class="amt"><?= number_format($admission_fee, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">2</td>
                <td>First Semester Registration Fee</td>
                <td class="amt"><?= number_format($reg_fee_sem, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">3</td>
                <td>Admission Form Fee</td>
                <td class="amt"><?= number_format($admission_form_fee, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">4</td>
                <td>ID Card Fee</td>
                <td class="amt"><?= number_format($admission_id_fee, 2) ?></td>
            </tr>
            <tr class="total-row">
                <td colspan="2"><strong>Total Amount Paid (During Admission)</strong></td>
                <td class="amt"><strong><?= number_format($admission_payable, 2) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div class="note-box">
        <strong>Note:</strong>
        <ul style="margin: 4px 0 0 16px; padding: 0;">
            <li>Monthly payment must be made on or before the <strong>10th of each month</strong>.</li>
            <li>Registration fees for each semester must be paid before registering for the semester.</li>
            <li>Duration of payment: <strong><?= (int)$total_semesters ?> semesters</strong>, <?= (int)$total_months ?> months total.</li>
            <li><strong>Payments are non-refundable.</strong></li>
        </ul>
    </div>

    <div class="sig-section">
        <div class="sig-block">
            <div class="sig-line">Signature of Student</div>
            <div class="sig-subtitle"><?= h($app['student_name']) ?></div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Admission Officer</div>
            <div class="sig-subtitle">Admission Office</div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Admission Office (In Charge)</div>
            <div class="sig-subtitle">Admission Office</div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Registrar</div>
            <div class="sig-subtitle">Office of the Registrar</div>
        </div>
    </div>

    <?php endif; ?>

</div>
</div>

</body>
</html>
