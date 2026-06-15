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

$fixed_per_sem    = $total_semesters > 0 ? round($fixed_total   / $total_semesters, 2) : 0.0;
$english_per_sem  = $total_semesters > 0 ? round($english_total / $total_semesters, 2) : 0.0;
$regular_sem_pay  = $tuition_sem + $reg_fee_sem + $fixed_per_sem + $english_per_sem;
$admission_payable = $admission_fee + $reg_fee_sem + $form_id_fee;

// ── Scholarship ───────────────────────────────────────────────────────────────
$scholarship_amount       = (float)($app['scholarship_amount']             ?? 0);
$scholarship_label        = (string)($app['scholarship_label']              ?? '');
$scholarship_type         = (string)($app['scholarship_discount_type']      ?? 'fixed');
$scholarship_pct          = (float)($app['scholarship_discount_pct']        ?? 0);
$scholarship_scope        = (string)($app['scholarship_scope']              ?? 'first_semester');
$scholarship_applies_fixed   = (int)($app['scholarship_applies_to_fixed']   ?? 0);
$scholarship_applies_english = (int)($app['scholarship_applies_to_english'] ?? 0);
$scholarship_scope_label     = $scholarship_scope === 'all_semesters' ? 'All Semesters' : 'First Semester';
$scholarship_scope_semesters = $scholarship_scope === 'all_semesters' ? max(1, $total_semesters) : 1;

// Per-component discounts per semester
if ($scholarship_amount > 0 && $scholarship_type === 'percentage' && $scholarship_pct > 0) {
    $sc_tuition_disc = round(min($tuition_sem,   $tuition_sem   * $scholarship_pct / 100), 2);
    $sc_fixed_disc   = $scholarship_applies_fixed   ? round(min($fixed_per_sem,   $fixed_per_sem   * $scholarship_pct / 100), 2) : 0.0;
    $sc_english_disc = $scholarship_applies_english ? round(min($english_per_sem, $english_per_sem * $scholarship_pct / 100), 2) : 0.0;
} else {
    // Fixed amount: all goes against tuition per semester
    $sc_tuition_disc = min($tuition_sem, $scholarship_amount);
    $sc_fixed_disc   = 0.0;
    $sc_english_disc = 0.0;
}

$first_sem_tuition_payable = max(0.0, $tuition_sem   - $sc_tuition_disc);
$first_sem_fixed_payable   = max(0.0, $fixed_per_sem  - $sc_fixed_disc);
$first_sem_english_payable = max(0.0, $english_per_sem - $sc_english_disc);
$total_discount_first_sem  = $sc_tuition_disc + $sc_fixed_disc + $sc_english_disc;
$total_discount_selected_scope = $total_discount_first_sem * $scholarship_scope_semesters;
$total_payable_first_sem   = $first_sem_tuition_payable + $first_sem_fixed_payable + $first_sem_english_payable + $reg_fee_sem;

// Monthly installment based on first-semester actuals
$mps = $total_semesters > 0 && $total_months > 0
    ? ($total_months / $total_semesters)
    : ($total_months > 0 ? $total_months : 1);
$monthly_installment_base = $first_sem_tuition_payable + $first_sem_fixed_payable + $first_sem_english_payable;
$monthly_installment      = ($mps > 0) ? (int)round($monthly_installment_base / $mps) : 0;

// Semester type label
$sem_type_months_label = ($total_semesters <= 8)
    ? 'Bi-Semester – 6 months'
    : 'Trimester – 4 months';

$admission_form_fee = round($form_id_fee / 2, 2);
$admission_id_fee   = round($form_id_fee - $admission_form_fee, 2);
$date_today         = date('d F Y');
$page_title         = 'Statement of Payment – ' . ($app['student_name'] ?? 'Admission Applicant');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?></title>
    <!-- Bootstrap 5 (modal support) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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

        .fee-table tr.visual-sep td { height: 3px; border: 0; background: transparent; padding: 0; }
        .fee-table td.indent { padding-left: 14px; }
        .fee-table td.neg, .fee-table .neg { color: #b91c1c; }
        .sc-badge { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; border-radius: 3px; padding: 0 4px; font-size: 9.5px; font-weight: 700; }

        @media print {
            @page { size: A4 portrait; margin: 0; }
            .screen-controls { display: none !important; }
            .modal { display: none !important; }
            .alert { display: none !important; }
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
    <?php if ($has_package && adm_can_edit()): ?>
    <button type="button"
            style="background:#d97706;"
            data-bs-toggle="modal" data-bs-target="#scModal"
            data-label="<?= h($scholarship_label) ?>"
            data-type="<?= h($scholarship_type) ?>"
            data-pct="<?= h(number_format($scholarship_pct, 4)) ?>"
            data-amount="<?= ($scholarship_amount > 0) ? h(number_format($scholarship_amount, 2)) : '' ?>"
            data-scope="<?= h($scholarship_scope) ?>"
            data-applies-fixed="<?= $scholarship_applies_fixed ?>"
            data-applies-english="<?= $scholarship_applies_english ?>">
        <i class="fas fa-<?= $scholarship_amount > 0 ? 'edit' : 'plus' ?>"></i>
        <?= $scholarship_amount > 0 ? 'Edit Scholarship' : 'Set Scholarship' ?>
    </button>
    <?php if ($scholarship_amount > 0): ?>
    <form method="post" action="<?= APP_URL ?>/admissions/remove-scholarship.php"
          onsubmit="return confirm('Remove scholarship from this application?');"
          style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="redirect_to" value="statement">
        <button type="submit" style="background:#dc2626;">
            <i class="fas fa-times"></i> Remove Scholarship
        </button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <span><?= h($app['app_number']) ?> — <?= h($app['student_name']) ?></span>
</div>

<div class="print-wrapper">

<?php
$_flash_html = '';
foreach (['success', 'error', 'warning', 'info'] as $_ft) {
    $_fm = flash_get($_ft);
    if ($_fm) {
        $_cls = $_ft === 'error' ? 'danger' : $_ft;
        $_flash_html .= '<div class="alert alert-' . $_cls . ' alert-dismissible fade show mb-2" role="alert">'
            . strip_tags($_fm, '<strong><em><b><i>')
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}
if ($_flash_html): ?>
<div style="max-width:794px; margin:0 auto 12px;">
    <?= $_flash_html ?>
</div>
<?php endif; ?>

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

    <div class="sec-heading">Semester Payment Details</div>
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

            <!-- Scholarship applied per selected scope -->
            <tr class="visual-sep"><td colspan="3"></td></tr>
            <?php if ($scholarship_amount > 0 && $scholarship_label !== ''): ?>
            <tr>
                <td class="indent" colspan="2"><strong>Scholarship Applied — <?= h($scholarship_scope_label) ?></strong></td>
                <td class="amt"></td>
            </tr>

            <!-- Tuition fee line -->
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">Tuition Fee (Per Semester)</td>
                <td class="amt"><?= number_format($tuition_sem, 2) ?></td>
            </tr>
            <?php if ($scholarship_type === 'percentage' && $scholarship_pct > 0): ?>
            <?php
            $sc_scope_parts = ['Tuition Fee'];
            if ($scholarship_applies_fixed)   $sc_scope_parts[] = 'Institutional &amp; Dev. Fee';
            if ($scholarship_applies_english) $sc_scope_parts[] = 'English Language Fee';
            $sc_scope_on = count($sc_scope_parts) > 1
                ? 'on Overall Cost (' . implode(' + ', $sc_scope_parts) . ')'
                : 'on Tuition Fee only';
            ?>
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">
                    <span class="neg">−</span>
                    <span class="sc-badge"><?= h($scholarship_label) ?> (<?= number_format($scholarship_pct, 1) ?>%)</span>
                    — <?= $sc_scope_on ?>
                </td>
                <td class="amt neg">− <?= number_format($sc_tuition_disc, 2) ?></td>
            </tr>
            <?php else: ?>
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">
                    <span class="neg">−</span>
                    <span class="sc-badge"><?= h($scholarship_label) ?></span>
                </td>
                <td class="amt neg">− <?= number_format($sc_tuition_disc, 2) ?></td>
            </tr>
            <?php endif; ?>

            <?php if ($sc_fixed_disc > 0): ?>
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">Institutional &amp; Dev. Fee (Per Semester)</td>
                <td class="amt"><?= number_format($fixed_per_sem, 2) ?></td>
            </tr>
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">
                    <span class="neg">−</span>
                    <span class="sc-badge"><?= h($scholarship_label) ?> (<?= number_format($scholarship_pct, 1) ?>%)</span>
                </td>
                <td class="amt neg">− <?= number_format($sc_fixed_disc, 2) ?></td>
            </tr>
            <?php endif; ?>

            <?php if ($sc_english_disc > 0): ?>
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">English Language Fee (Per Semester)</td>
                <td class="amt"><?= number_format($english_per_sem, 2) ?></td>
            </tr>
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">
                    <span class="neg">−</span>
                    <span class="sc-badge"><?= h($scholarship_label) ?> (<?= number_format($scholarship_pct, 1) ?>%)</span>
                </td>
                <td class="amt neg">− <?= number_format($sc_english_disc, 2) ?></td>
            </tr>
            <?php endif; ?>

            <?php else: ?>
            <tr>
                <td class="indent" colspan="2">Scholarship(s) Applied — <?= h($scholarship_scope_label) ?></td>
                <td class="amt">—</td>
            </tr>
            <?php endif; ?>

            <tr>
                <td class="indent" colspan="2">Total Scholarship (Per Semester)</td>
                <td class="amt neg">− <?= number_format($total_discount_first_sem, 2) ?></td>
            </tr>
            <?php if ($scholarship_scope === 'all_semesters' && $scholarship_amount > 0 && $scholarship_label !== ''): ?>
            <tr>
                <td class="indent" colspan="2">Total Scholarship (All Semesters)</td>
                <td class="amt neg">− <?= number_format($total_discount_selected_scope, 2) ?></td>
            </tr>
            <?php endif; ?>

            <!-- Post-scholarship breakdown -->
            <tr>
                <td class="indent" colspan="2">Tuition Fee After Scholarship</td>
                <td class="amt"><?= number_format($first_sem_tuition_payable, 2) ?></td>
            </tr>
            <tr>
                <td class="indent" colspan="2">Per Semester Institutional &amp; Development Fee</td>
                <td class="amt"><?= number_format($first_sem_fixed_payable, 2) ?></td>
            </tr>
            <tr>
                <td class="indent" colspan="2">Per Semester English Language Fee</td>
                <td class="amt"><?= number_format($first_sem_english_payable, 2) ?></td>
            </tr>
            <tr>
                <td class="indent" colspan="2">Per Semester Registration Fee</td>
                <td class="amt"><?= number_format($reg_fee_sem, 2) ?></td>
            </tr>

            <tr class="subtotal">
                <td colspan="2"><strong><?= $scholarship_scope === 'all_semesters' ? 'Total Payable Per Semester After Scholarship' : 'Total First Semester Payable Amount' ?></strong></td>
                <td class="amt"><strong><?= number_format($total_payable_first_sem, 2) ?></strong></td>
            </tr>

            <!-- Monthly installment -->
            <tr class="visual-sep"><td colspan="3"></td></tr>
            <tr class="highlight">
                <td colspan="2"><strong><?= $scholarship_scope === 'all_semesters' ? 'Monthly Payment Per Semester' : 'First Semester Monthly Payment' ?></strong>
                    <span style="font-size:9.5px; color:#92400e; font-weight:400;">
                        (<?= number_format($first_sem_tuition_payable, 2) ?> Tuition
                        + <?= number_format($first_sem_fixed_payable, 2) ?> Institutional &amp; Dev. Fee
                        + <?= number_format($first_sem_english_payable, 2) ?> English Fee)
                        ÷ <?= (int)round($mps) ?> months
                        &nbsp;|&nbsp; <em><?= h($sem_type_months_label) ?></em>
                    </span>
                </td>
                <td class="amt"><strong><?= number_format($monthly_installment, 2) ?></strong></td>
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

<?php if ($has_package && adm_can_edit()): ?>
<!-- ── Scholarship Modal ── -->
<div class="modal fade" id="scModal" tabindex="-1" aria-labelledby="scModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= APP_URL ?>/admissions/set-scholarship.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="redirect_to" value="statement">
            <div class="modal-content">
                <div class="modal-header" style="background:#d97706; color:#fff;">
                    <h5 class="modal-title" id="scModalLabel">
                        <i class="fas fa-graduation-cap me-2"></i>Add / Edit Scholarship
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Choose whether the scholarship applies to the first semester only or to every semester.
                    </p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tuition Fee Base (Per Semester)</label>
                        <input type="text" id="sc-tuition-display" class="form-control bg-light" readonly
                               value="<?= number_format($tuition_sem, 2) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Scholarship Label <span class="text-danger">*</span></label>
                        <input type="text" name="scholarship_label" id="sc-label" class="form-control"
                               placeholder="e.g. Merit Scholarship, Freedom Fighter, Sports Award" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Scholarship Type <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="discount_type" value="percentage"
                                       id="sc-type-pct" checked>
                                <label class="form-check-label" for="sc-type-pct">
                                    <i class="fas fa-percent me-1 text-secondary"></i>Percentage
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="discount_type" value="fixed"
                                       id="sc-type-fixed">
                                <label class="form-check-label" for="sc-type-fixed">
                                    <i class="fas fa-money-bill-wave me-1 text-secondary"></i>Fixed Amount
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Apply Scholarship To <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4 flex-wrap">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="scholarship_scope" value="first_semester"
                                       id="sc-scope-first" checked>
                                <label class="form-check-label" for="sc-scope-first">First Semester</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="scholarship_scope" value="all_semesters"
                                       id="sc-scope-all">
                                <label class="form-check-label" for="sc-scope-all">All Semesters</label>
                            </div>
                        </div>
                        <div class="form-text">For all-semester scholarships, the discount amount is applied once per semester.</div>
                    </div>

                    <div class="mb-3" id="sc-pct-wrap">
                        <label class="form-label fw-semibold">Discount % <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="discount_pct" id="sc-pct"
                                   class="form-control" step="0.0001" min="0.0001" max="100">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>

                    <div class="mb-3 d-none" id="sc-fixed-wrap">
                        <label class="form-label fw-semibold">Fixed Scholarship Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">BDT</span>
                            <input type="number" name="scholarship_amount" id="sc-fixed-amount"
                                   class="form-control" step="0.01" min="0.01" placeholder="e.g. 5000">
                        </div>
                    </div>

                    <div class="mb-3" id="sc-calc-wrap">
                        <label class="form-label fw-semibold">Scholarship Amount (auto-calculated per semester)</label>
                        <div class="input-group">
                            <input type="text" id="sc-calc-amount" class="form-control bg-light" readonly>
                            <span class="input-group-text">BDT</span>
                        </div>
                    </div>

                    <!-- Fee scope: only for percentage type -->
                    <div class="mb-3" id="sc-scope-wrap">
                        <label class="form-label fw-semibold small">Also apply discount to:</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="applies_to_fixed" value="1"
                                       id="sc-applies-fixed">
                                <label class="form-check-label small" for="sc-applies-fixed">
                                    Institutional &amp; Development Fees
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="applies_to_english" value="1"
                                       id="sc-applies-english">
                                <label class="form-check-label small" for="sc-applies-english">
                                    English Language Fee
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning text-dark">
                        <i class="fas fa-save me-1"></i> Save Scholarship
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var tuitionBase = <?= json_encode($tuition_sem) ?>;
    var fixedBase   = <?= json_encode($fixed_per_sem) ?>;
    var englishBase = <?= json_encode($english_per_sem) ?>;

    function scSwitchType(type) {
        var pctWrap    = document.getElementById('sc-pct-wrap');
        var fixedWrap  = document.getElementById('sc-fixed-wrap');
        var scopeWrap  = document.getElementById('sc-scope-wrap');
        var calcWrap   = document.getElementById('sc-calc-wrap');
        var pctInput   = document.getElementById('sc-pct');
        var fixedInput = document.getElementById('sc-fixed-amount');

        if (type === 'fixed') {
            pctWrap.classList.add('d-none');
            fixedWrap.classList.remove('d-none');
            scopeWrap.classList.add('d-none');
            calcWrap.classList.add('d-none');
            pctInput.removeAttribute('required'); pctInput.value = '';
            fixedInput.setAttribute('required', 'required');
            document.getElementById('sc-applies-fixed').checked   = false;
            document.getElementById('sc-applies-english').checked = false;
        } else {
            pctWrap.classList.remove('d-none');
            fixedWrap.classList.add('d-none');
            scopeWrap.classList.remove('d-none');
            calcWrap.classList.remove('d-none');
            pctInput.setAttribute('required', 'required');
            fixedInput.removeAttribute('required'); fixedInput.value = '';
        }
        scRecalcAmount();
    }

    function scRecalcAmount() {
        var typePct = document.getElementById('sc-type-pct');
        if (!typePct || !typePct.checked) return;
        var pct = parseFloat(document.getElementById('sc-pct').value) || 0;
        var base = tuitionBase;
        if (document.getElementById('sc-applies-fixed').checked)   base += fixedBase;
        if (document.getElementById('sc-applies-english').checked) base += englishBase;
        var amt = Math.round(base * pct / 100 * 100) / 100;
        document.getElementById('sc-calc-amount').value =
            amt.toLocaleString('en-BD', {minimumFractionDigits:2});
    }

    document.querySelectorAll('input[name="discount_type"]').forEach(function(r) {
        r.addEventListener('change', function() { scSwitchType(this.value); });
    });
    document.getElementById('sc-pct').addEventListener('input', scRecalcAmount);
    document.getElementById('sc-applies-fixed').addEventListener('change', scRecalcAmount);
    document.getElementById('sc-applies-english').addEventListener('change', scRecalcAmount);

    document.getElementById('scModal').addEventListener('show.bs.modal', function(event) {
        var btn             = event.relatedTarget;
        var label           = btn ? (btn.dataset.label          || '') : '';
        var type            = btn ? (btn.dataset.type           || 'fixed') : 'fixed';
        var pct             = btn ? (btn.dataset.pct            || '') : '';
        var amount          = btn ? (btn.dataset.amount         || '') : '';
        var scope           = btn ? (btn.dataset.scope          || 'first_semester') : 'first_semester';
        var appliesFixed    = btn ? (btn.dataset.appliesFixed   === '1') : false;
        var appliesEnglish  = btn ? (btn.dataset.appliesEnglish === '1') : false;

        document.getElementById('sc-label').value = label;
        document.getElementById('sc-scope-first').checked = (scope !== 'all_semesters');
        document.getElementById('sc-scope-all').checked   = (scope === 'all_semesters');
        document.getElementById('sc-applies-fixed').checked   = appliesFixed;
        document.getElementById('sc-applies-english').checked = appliesEnglish;

        if (type === 'percentage') {
            document.getElementById('sc-type-pct').checked   = true;
            document.getElementById('sc-type-fixed').checked = false;
            scSwitchType('percentage');
            document.getElementById('sc-pct').value = pct;
            scRecalcAmount();
        } else {
            document.getElementById('sc-type-pct').checked   = false;
            document.getElementById('sc-type-fixed').checked = true;
            scSwitchType('fixed');
            document.getElementById('sc-fixed-amount').value = amount;
        }
    });
})();
</script>
<?php endif; ?>

<!-- Bootstrap JS (required for modal) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
