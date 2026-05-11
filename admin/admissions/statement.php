<?php
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

$tuition_total    = $tuition_sem * max($total_semesters, 0);
$reg_total        = $reg_fee_sem * max($total_semesters, 0);
$grand_total      = $admission_fee + $reg_total + $tuition_total + $fixed_total + $english_total + $form_id_fee;

$fixed_per_sem    = $total_semesters > 0 ? round($fixed_total / $total_semesters, 2) : 0.0;
$english_per_sem  = $total_semesters > 0 ? round($english_total / $total_semesters, 2) : 0.0;
$regular_sem_pay  = $tuition_sem + $reg_fee_sem + $fixed_per_sem + $english_per_sem;
$admission_payable = $admission_fee + $reg_fee_sem + $form_id_fee;
$monthly_after_admission = $total_months > 0 ? round(($tuition_total + $fixed_total + $english_total) / $total_months, 2) : 0.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admission Financial Statement – <?= h($app['app_number']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #1f2937; background: #f3f4f6; margin: 0; }
        .topbar { position: fixed; inset: 0 0 auto 0; background: #1f2937; color: #fff; padding: 10px 16px; z-index: 10; }
        .topbar button, .topbar a { border: 0; background: #16a34a; color: #fff; padding: 6px 12px; border-radius: 4px; text-decoration: none; margin-right: 8px; }
        .topbar a { background: #6b7280; }
        .sheet { width: 820px; margin: 70px auto 30px; background: #fff; padding: 26px 30px; box-shadow: 0 2px 12px rgba(0,0,0,.1); }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        .meta { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 8px 16px; margin: 14px 0 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #d1d5db; padding: 8px; }
        th { background: #e5e7eb; text-align: left; }
        td.amt { text-align: right; }
        .total { font-weight: 700; background: #ecfdf5; }
        .note { margin-top: 18px; font-size: 11px; color: #4b5563; }
        @media print {
            .topbar { display: none; }
            body { background: #fff; }
            .sheet { margin: 0; width: auto; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <button onclick="window.print()">Print Statement</button>
        <a href="javascript:window.close()">Close</a>
        <span><?= h($app['app_number']) ?> — <?= h($app['student_name']) ?></span>
    </div>

    <div class="sheet">
        <h1>Prime University</h1>
        <div class="muted">Admission Financial Statement</div>

        <div class="meta">
            <div><strong>Application No:</strong> <?= h($app['app_number']) ?></div>
            <div><strong>Applicant:</strong> <?= h($app['student_name']) ?></div>
            <div><strong>Department:</strong> <?= h($app['dept_name'] ?? '—') ?></div>
            <div><strong>Program:</strong> <?= h($app['program_name'] ?? '—') ?></div>
            <div><strong>Assigned Package:</strong> <?= h($app['financial_package_name'] ?? 'Not assigned') ?></div>
            <div><strong>Date:</strong> <?= h(date('d M Y')) ?></div>
        </div>

        <?php if (!$has_package): ?>
            <div style="padding:10px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;">
                No financial package is assigned for this application yet.
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Fee Head</th><th class="amt">Amount (BDT)</th></tr>
                </thead>
                <tbody>
                    <tr><td>Admission Fee</td><td class="amt"><?= number_format($admission_fee, 2) ?></td></tr>
                    <tr><td>Registration Fee (<?= $total_semesters ?> semesters × <?= number_format($reg_fee_sem, 2) ?>)</td><td class="amt"><?= number_format($reg_total, 2) ?></td></tr>
                    <tr><td>Tuition Fee (<?= $total_semesters ?> semesters × <?= number_format($tuition_sem, 2) ?>)</td><td class="amt"><?= number_format($tuition_total, 2) ?></td></tr>
                    <tr><td>Institutional Fees (Total)</td><td class="amt"><?= number_format($fixed_total, 2) ?></td></tr>
                    <tr><td>English Course Fee (Total)</td><td class="amt"><?= number_format($english_total, 2) ?></td></tr>
                    <tr><td>Form &amp; ID Fee</td><td class="amt"><?= number_format($form_id_fee, 2) ?></td></tr>
                    <tr class="total"><td>Grand Total</td><td class="amt"><?= number_format($grand_total, 2) ?></td></tr>
                </tbody>
            </table>

            <table>
                <thead>
                    <tr><th>Payable Schedule</th><th class="amt">Amount (BDT)</th></tr>
                </thead>
                <tbody>
                    <tr><td>At Admission (Admission + 1st Registration + Form & ID Fee)</td><td class="amt"><?= number_format($admission_payable, 2) ?></td></tr>
                    <tr><td>Regular Semester Payable (Estimated)</td><td class="amt"><?= number_format($regular_sem_pay, 2) ?></td></tr>
                    <tr><td>Monthly Installment After Admission (Estimated)</td><td class="amt"><?= number_format($monthly_after_admission, 2) ?></td></tr>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="note">
            This statement is generated from the financial package snapshot saved with this admission application.
        </div>
    </div>
</body>
</html>
