<?php
/**
 * Student Accounts – Financial Statement of Payment
 * Standalone print page (no admin layout).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
require_access('student-accounts');

$id  = (int)($_GET['id'] ?? 0);
$pkg = sfp_get_package($id);

if (!$pkg) {
    flash_set('error', 'Student account not found.');
    redirect(APP_URL . '/student-accounts/index.php');
}

// ── Fetch full student record ─────────────────────────────────────────────────
$student_stmt = db()->prepare(
    'SELECT s.*,
            d.name          AS dept_name,
            d.code          AS dept_short,
            sb.name         AS batch_name
     FROM students s
     LEFT JOIN dept_departments d  ON d.id = s.dept_id
     LEFT JOIN student_batches sb ON sb.id = s.batch_id
     WHERE s.id = ?'
);
$student_stmt->execute([$pkg['student_id']]);
$student = $student_stmt->fetch();

// ── Fetch cf_settings (reg fee, form_id_fee) ─────────────────────────────────
$cf = db()->query('SELECT * FROM cf_settings WHERE id = 1')->fetch();

// ── Fetch first-semester fee row & scholarships ───────────────────────────────
$sf_stmt = db()->prepare(
    'SELECT sf.*
     FROM sfp_semester_fees sf
     WHERE sf.package_id = ? AND sf.semester_number = 1
     LIMIT 1'
);
$sf_stmt->execute([$id]);
$sf1 = $sf_stmt->fetch();

$scholarships_1st = [];
if ($sf1) {
    $sc_stmt = db()->prepare(
        'SELECT ss.*
         FROM sfp_semester_scholarships ss
         WHERE ss.sf_id = ?
         ORDER BY ss.created_at ASC'
    );
    $sc_stmt->execute([$sf1['id']]);
    $scholarships_1st = $sc_stmt->fetchAll();
}

// ── Fee calculations ──────────────────────────────────────────────────────────

// Fees breakdown (totals for the whole programme)
$admission_fee     = (float)($pkg['admission_fees'] ?? 0);        // one-time, paid at admission
$reg_fee_total     = ($cf ? (float)$cf['reg_fee_per_semester'] : 0) * (float)$pkg['total_semesters'];
$reg_fee_1st_sem   = $cf ? (float)$cf['reg_fee_per_semester'] : 0;
$form_id_fee       = $cf ? (float)$cf['form_id_fee'] : 0;
$english_fee_total = (float)$pkg['english_course_fee'];
$tuition_total     = (float)$pkg['standard_tuition_full'];
$fixed_total       = (float)$pkg['fixed_institutional_fees'];

// "So total" = sum of all the five fee lines
$grand_total_fees  = $admission_fee + $reg_fee_total + $english_fee_total + $tuition_total + $fixed_total;

// Per-semester fixed/English portions
$months = (float)$pkg['total_months'];
$mps    = (float)$pkg['months_per_semester'];
$fixed_per_sem_gross   = ($months > 0) ? round($fixed_total / $months * $mps, 2) : 0.0;
$english_per_sem_gross = ($months > 0) ? round($english_fee_total / $months * $mps, 2) : 0.0;

// Regular semester payable per semester (all four components)
$regular_payable_per_sem = (float)$pkg['tuition_per_semester'] + $fixed_per_sem_gross + $english_per_sem_gross + $reg_fee_1st_sem;

// First-semester scholarship & discount
$first_sem_scholarship_pct    = $sf1 ? (float)$sf1['scholarship_discount_pct'] : 0.0;
$first_sem_scholarship_amount = $sf1 ? (float)$sf1['scholarship_amount']       : 0.0;
$first_sem_fixed_discount     = $sf1 ? (float)($sf1['fixed_discount_amount']   ?? 0) : 0.0;
$first_sem_english_discount   = $sf1 ? (float)($sf1['english_discount_amount'] ?? 0) : 0.0;

$total_discount_first_sem     = $first_sem_scholarship_amount + $first_sem_fixed_discount + $first_sem_english_discount;
$first_sem_tuition_payable    = $sf1 ? (float)$sf1['tuition_payable'] : (float)$pkg['tuition_per_semester'];

$first_sem_fixed_payable   = max(0.0, $fixed_per_sem_gross   - $first_sem_fixed_discount);
$first_sem_english_payable = max(0.0, $english_per_sem_gross - $first_sem_english_discount);

$total_payable_first_sem = $first_sem_tuition_payable + $first_sem_fixed_payable + $first_sem_english_payable + $reg_fee_1st_sem;

// Payment made at admission
$admission_payment_admission = $admission_fee;
$admission_payment_reg       = $reg_fee_1st_sem;
// form + ID card are bundled in form_id_fee; split evenly for display
$admission_form_fee = (int)floor($form_id_fee / 2);
$admission_id_fee   = $form_id_fee - $admission_form_fee;
$total_paid_at_admission = $admission_payment_admission + $admission_payment_reg + $form_id_fee;

// Duration of payment
$payment_months = (int)$pkg['total_months'];

// Semester type detection
$total_semesters = (int)$pkg['total_semesters'];

// Monthly installment base = tuition payable + fixed payable + english payable (registration fee excluded)
$monthly_installment_base = $first_sem_tuition_payable + $first_sem_fixed_payable + $first_sem_english_payable;
$monthly_installment      = ($mps > 0) ? (int)round($monthly_installment_base / $mps) : 0;

// Semester type label for display
$sem_type_months_label = ($total_semesters <= SFP_MAX_BI_SEMESTER_COUNT)
    ? 'Bi-Semester – 6 months'
    : 'Trimester – 4 months';

$date_today   = date('d F Y');
$page_title   = 'Statement of Payment – ' . $pkg['student_name'];
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

        /* ── Screen toolbar ── */
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

        /* ── Statement page ── */
        .statement-page {
            background: #fff;
            width: 794px;
            min-height: 1123px;
            padding: 14px 24px 12px;
            margin: 0 auto 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }

        /* ── University header ── */
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

        /* ── Student info grid ── */
        .student-info-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 11px; }
        .student-info-table td { padding: 2px 4px; border: 1px solid #ddd; vertical-align: top; }
        .student-info-table td.lbl { background: #eef2f8; color: #1e3a5f; font-weight: 700; width: 30%; }
        .student-info-table td.val { font-weight: 500; }
        .manual-field { border-bottom: 1px solid #999; display: inline-block; min-width: 100px; }

        /* ── Section heading ── */
        .sec-heading {
            font-size: 10.5px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; background: #eef2f8; color: #1e3a5f;
            padding: 3px 7px; margin: 6px 0 3px;
            border-left: 3px solid #2563eb;
        }

        /* ── Fee table ── */
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
        .fee-table td.indent { padding-left: 16px; }
        .fee-table .sc-badge {
            display: inline-block; background: #fef2f2; color: #dc2626;
            border: 1px solid #fca5a5; border-radius: 3px;
            padding: 1px 4px; font-size: 9px; font-weight: 600;
        }
        .neg { color: #dc2626; }

        /* ── Note box ── */
        .note-box {
            border: 1px solid #e5e7eb; padding: 4px 8px; margin-top: 6px;
            font-size: 10px; color: #374151; background: #fafafa;
            line-height: 1.35;
        }
        .note-box strong { color: #1e3a5f; }

        /* ── Signature section ── */
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
            body { background: #fff; }
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

<!-- ── Screen toolbar ── -->
<div class="screen-controls">
    <button onclick="window.print()">🖨 Print / Save as PDF</button>
    <a href="<?= APP_URL ?>/student-accounts/index.php" class="back-btn">← Back to Student Accounts</a>
    <span><?= h($pkg['student_sid']) ?> — <?= h($pkg['student_name']) ?></span>
</div>

<div class="print-wrapper">
<div class="statement-page">

    <!-- ── University Header ── -->
    <div class="univ-header">
        <img src="<?= LOGO_URL ?>" alt="Prime University Logo" class="logo"
             onerror="this.style.display='none'">
        <div class="univ-sub">114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh | www.primeuniversity.ac.bd</div>
    </div>

    <div class="doc-title">Statement of Payment</div>

    <!-- ── Date of Issue (top-right) ── -->
    <div class="date-issued-top">Date of Issue: <?= $date_today ?></div>

    <!-- ── Student Information ── -->
    <table class="student-info-table">
        <tr>
            <td class="lbl">Batch</td>
            <td class="val"><?= h($student['batch_name'] ?? ($student['batch'] ?? '')) ?></td>
            <td class="lbl">Student ID</td>
            <td class="val"><?= h($pkg['student_sid']) ?></td>
        </tr>
        <tr>
            <td class="lbl">Student Name</td>
            <td class="val" colspan="3"><?= h($pkg['student_name']) ?></td>
        </tr>
        <tr>
            <td class="lbl">Department</td>
            <td class="val"><?= h($student['dept_name'] ?? '') ?><?= ($student['dept_short'] ?? '') ? ' (' . h($student['dept_short']) . ')' : '' ?></td>
            <td class="lbl">Program</td>
            <td class="val"><?= h($pkg['program_name']) ?></td>
        </tr>
        <tr>
            <td class="lbl">Enrolled Semester</td>
            <td class="val" colspan="3"><?= h($pkg['admitted_semester'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="lbl">Total No. of Courses</td>
            <td class="val"><span class="manual-field" style="min-width:120px;">&nbsp;</span></td>
            <td class="lbl">Course Waive</td>
            <td class="val"><span class="manual-field" style="min-width:120px;">&nbsp;</span></td>
        </tr>
    </table>

    <!-- ══════════════════════════════════════
         SECTION 1 — FEES BREAKDOWN
    ══════════════════════════════════════ -->
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
                    <span style="font-size:9.5px;color:#6b7280;">(<?= number_format($reg_fee_1st_sem, 2) ?> × <?= $total_semesters ?> semesters)</span>
                </td>
                <td class="amt"><?= number_format($reg_fee_total, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">3</td>
                <td>English Language Fee</td>
                <td class="amt"><?= number_format($english_fee_total, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">4</td>
                <td>Tuition Fee
                    <span style="font-size:9.5px;color:#6b7280;">(<?= number_format((float)$pkg['tuition_per_semester'], 2) ?> × <?= $total_semesters ?> semesters)</span>
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
                <td class="amt"><strong><?= number_format($grand_total_fees, 2) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <!-- ══════════════════════════════════════
         SECTION 2 — REGULAR & FIRST SEMESTER
    ══════════════════════════════════════ -->
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
            <!-- Per-semester breakdown (regular / gross) -->
            <tr>
                <td class="serial">1</td>
                <td>Per Semester Tuition Fee</td>
                <td class="amt"><?= number_format((float)$pkg['tuition_per_semester'], 2) ?></td>
            </tr>
            <tr>
                <td class="serial">2</td>
                <td>Per Semester Institutional &amp; Development Fee</td>
                <td class="amt"><?= number_format($fixed_per_sem_gross, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">3</td>
                <td>Per Semester English Language Fee</td>
                <td class="amt"><?= number_format($english_per_sem_gross, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">4</td>
                <td>Per Semester Registration Fee</td>
                <td class="amt"><?= number_format($reg_fee_1st_sem, 2) ?></td>
            </tr>
            <tr class="subtotal">
                <td colspan="2"><strong>Total Regular Payable per Semester</strong></td>
                <td class="amt"><strong><?= number_format($regular_payable_per_sem, 2) ?></strong></td>
            </tr>

            <!-- Scholarship / discount applied in first semester -->
            <tr class="visual-sep"><td colspan="3"></td></tr>
            <?php
            // Pre-compute cascading scholarship steps for display
            $sc_calc         = [];
            $has_fixed_sc    = false;
            $has_english_sc  = false;
            if (!empty($scholarships_1st)) {
                $run_t = (float)$pkg['tuition_per_semester'];
                $run_f = $fixed_per_sem_gross;
                $run_e = $english_per_sem_gross;
                foreach ($scholarships_1st as $sc) {
                    $pct  = (float)$sc['discount_pct'];
                    $step = [
                        'sc'              => $sc,
                        'pct'             => $pct,
                        'applies_fixed'   => (int)$sc['applies_to_fixed'],
                        'applies_english' => (int)$sc['applies_to_english'],
                    ];
                    // Tuition
                    $t_disc          = round(min($run_t, $run_t * $pct / 100), 2);
                    $step['t_before'] = $run_t;
                    $step['t_disc']   = $t_disc;
                    $run_t           -= $t_disc;
                    $step['t_after']  = $run_t;
                    // Fixed institutional fee
                    if ($step['applies_fixed'] && $run_f > 0) {
                        $f_disc          = round(min($run_f, $run_f * $pct / 100), 2);
                        $step['f_before'] = $run_f;
                        $step['f_disc']   = $f_disc;
                        $run_f           -= $f_disc;
                        $step['f_after']  = $run_f;
                        $has_fixed_sc     = true;
                    }
                    // English course fee
                    if ($step['applies_english'] && $run_e > 0) {
                        $e_disc          = round(min($run_e, $run_e * $pct / 100), 2);
                        $step['e_before'] = $run_e;
                        $step['e_disc']   = $e_disc;
                        $run_e           -= $e_disc;
                        $step['e_after']  = $run_e;
                        $has_english_sc   = true;
                    }
                    $sc_calc[] = $step;
                }
            }
            ?>
            <?php if (!empty($sc_calc)): ?>
            <tr>
                <td class="indent" colspan="2"><strong>Scholarship(s) Applied in First Semester</strong></td>
                <td class="amt"></td>
            </tr>
            <!-- Tuition fee cascading -->
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">Tuition Fee (1st Semester)</td>
                <td class="amt"><?= number_format((float)$pkg['tuition_per_semester'], 2) ?></td>
            </tr>
            <?php foreach ($sc_calc as $i => $step):
                // Build scope label
                $scope_parts = ['Tuition Fee'];
                if ($step['applies_fixed'])   $scope_parts[] = 'Institutional &amp; Dev. Fee';
                if ($step['applies_english'])  $scope_parts[] = 'English Language Fee';
                $scope_on = count($scope_parts) > 1
                    ? 'on Overall Cost (' . implode(' + ', $scope_parts) . ')'
                    : 'on Tuition Fee only';
            ?>
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">
                    <span class="neg">−</span>
                    <span class="sc-badge"><?= h($step['sc']['label']) ?> (<?= number_format($step['pct'], 1) ?>%)</span>
                    — <?= $scope_on ?>
                </td>
                <td class="amt neg">− <?= number_format($step['t_disc'], 2) ?></td>
            </tr>
            <?php if ($i < count($sc_calc) - 1): ?>
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px; color:#6b7280; font-style:italic;">
                    Tuition balance after <?= h($step['sc']['label']) ?>
                </td>
                <td class="amt" style="color:#6b7280;"><?= number_format($step['t_after'], 2) ?></td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($has_fixed_sc): ?>
            <!-- Institutional fee cascading -->
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">Institutional &amp; Dev. Fee (1st Semester)</td>
                <td class="amt"><?= number_format($fixed_per_sem_gross, 2) ?></td>
            </tr>
            <?php foreach ($sc_calc as $i => $step):
                if (!$step['applies_fixed'] || !isset($step['f_disc'])) continue; ?>
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">
                    <span class="neg">−</span>
                    <span class="sc-badge"><?= h($step['sc']['label']) ?> (<?= number_format($step['pct'], 1) ?>%)</span>
                </td>
                <td class="amt neg">− <?= number_format($step['f_disc'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($has_english_sc): ?>
            <!-- English fee cascading -->
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">English Language Fee (1st Semester)</td>
                <td class="amt"><?= number_format($english_per_sem_gross, 2) ?></td>
            </tr>
            <?php foreach ($sc_calc as $i => $step):
                if (!$step['applies_english'] || !isset($step['e_disc'])) continue; ?>
            <tr>
                <td></td>
                <td class="indent" style="padding-left:22px;">
                    <span class="neg">−</span>
                    <span class="sc-badge"><?= h($step['sc']['label']) ?> (<?= number_format($step['pct'], 1) ?>%)</span>
                </td>
                <td class="amt neg">− <?= number_format($step['e_disc'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php else: ?>
            <tr>
                <td class="indent" colspan="2">Scholarship(s) Applied in First Semester</td>
                <td class="amt">—</td>
            </tr>
            <?php endif; ?>
            <tr>
                <td class="indent" colspan="2">Total Scholarship (First Semester)</td>
                <td class="amt neg">− <?= number_format($total_discount_first_sem, 2) ?></td>
            </tr>

            <!-- Post-scholarship breakdown for first semester -->
            <tr>
                <td class="indent" colspan="2">Total Payable Tuition Fees (After All Scholarship)</td>
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
                <td class="amt"><?= number_format($reg_fee_1st_sem, 2) ?></td>
            </tr>

            <tr class="subtotal">
                <td colspan="2"><strong>Total First Semester Payable Amount</strong></td>
                <td class="amt"><strong><?= number_format($total_payable_first_sem, 2) ?></strong></td>
            </tr>

            <!-- Monthly installment -->
            <tr class="visual-sep"><td colspan="3"></td></tr>
            <tr class="highlight">
                <td colspan="2"><strong>First Semester Monthly Payment</strong>
                    <span style="font-size:9.5px; color:#92400e; font-weight:400;">
                        (<?= number_format($first_sem_tuition_payable, 2) ?> Tuition + <?= number_format($first_sem_fixed_payable, 2) ?> Institutional &amp; Dev. Fee + <?= number_format($first_sem_english_payable, 2) ?> English Fee) ÷ <?= (int)$mps ?> months
                        &nbsp;|&nbsp; <em><?= h($sem_type_months_label) ?></em>
                    </span>
                </td>
                <td class="amt"><strong><?= number_format($monthly_installment) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <!-- ══════════════════════════════════════
         SECTION 3 — ADMISSION DAY PAYMENT
    ══════════════════════════════════════ -->
    <div class="sec-heading">Payment Made at the Time of Admission</div>
    <table class="fee-table">
        <tbody>
            <tr>
                <td class="serial">1</td>
                <td>Admission Fee</td>
                <td class="amt"><?= number_format($admission_payment_admission, 2) ?></td>
            </tr>
            <tr>
                <td class="serial">2</td>
                <td>First Semester Registration Fee</td>
                <td class="amt"><?= number_format($admission_payment_reg, 2) ?></td>
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
                <td class="amt"><strong><?= number_format($total_paid_at_admission, 2) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <!-- ── Notes ── -->
    <div class="note-box">
        <strong>Note:</strong>
        <ul style="margin: 4px 0 0 16px; padding: 0;">
            <li>Monthly payment must be made on or before the <strong>10th of each month</strong>.</li>
            <li>Registration fees for each semester must be paid before registering for the semester.</li>
            <li>Duration of payment:
                <?php
                // Programs are classified as bi-semester or trimester based on total semesters
                if ($total_semesters <= SFP_MAX_BI_SEMESTER_COUNT): ?>
                <strong><?= $total_semesters ?> semesters (Bi-Semester)</strong>, <?= $payment_months ?> months total.
                <?php else: ?>
                <strong><?= $total_semesters ?> semesters (Trimester)</strong>, <?= $payment_months ?> months total.
                <?php endif; ?>
            </li>
            <li><strong>Payments are non-refundable.</strong></li>
        </ul>
    </div>

    <!-- ── Signatures ── -->
    <div class="sig-section">
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
        <div class="sig-block">
            <div class="sig-line">Signature of Student</div>
            <div class="sig-subtitle"><?= h($pkg['student_name']) ?></div>
        </div>
    </div>

</div>
</div>

</body>
</html>
