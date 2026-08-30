<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../accounting/helpers.php';
require_once __DIR__ . '/../vc-approval/helpers.php';

$id  = (int)($_GET['id'] ?? 0);

// Make sure the OLD ERP Registration Fee (proof) columns exist before the
// package row is loaded, so the cross-check card can display them.
sfp_ensure_old_erp_reg_columns();

$pkg = sfp_get_package($id);

if (!$pkg) {
    flash_set('error', 'Student account not found.');
    redirect(APP_URL . '/student-accounts/index.php');
}

$page_title    = 'Student Account – ' . $pkg['student_name'];
$semester_fees = sfp_get_semester_fees($id);

// OLD ERP proof images attached to this student (via bulk proof upload)
$old_erp_proofs = sfp_get_old_erp_proofs((int)$pkg['student_id']);

// Active scholarship policies (with tiers) for the Add Scholarship modal
$sc_policies = sfp_get_active_sc_policies_with_tiers();

// All individual scholarships for this package, keyed by sf_id
$all_scholarships = sfp_get_all_semester_scholarships($id);

// Pending VC approval requests for this package (not yet applied)
$pending_vc_approvals = [];
try {
    $pva_stmt = db()->prepare(
        "SELECT r.*, req.full_name AS requested_by_name,
                stf.stored_name AS doc_stored_name, stf.original_name AS doc_original_name,
                sf.semester_number, sf.semester_label
         FROM vc_scholarship_approvals r
         JOIN users req ON req.id = r.requested_by
         LEFT JOIN sfp_semester_fees sf ON sf.id = r.sf_id
         LEFT JOIN student_files stf ON stf.id = r.support_doc_id
         WHERE r.package_id = ? AND r.status = 'pending'
         ORDER BY r.created_at ASC"
    );
    $pva_stmt->execute([$id]);
    $pending_vc_approvals = $pva_stmt->fetchAll();
} catch (Throwable $e) {
    // Table may not exist yet; silently ignore
}

// Per-semester fixed / English portions (for display in semester table)
$sem_fixed_portion   = sfp_semester_fixed_portion($pkg);
$sem_english_portion = sfp_semester_english_portion($pkg);

// Registration fee remains snapshotted on the package (not global cf_settings)
// Form fee and ID card fee prefer the snapshotted package total, then fall back to shared defaults.
$reg_fee_per_sem     = (float)($pkg['reg_fee_per_semester'] ?? 0.0);
$form_id_fee         = acc_package_form_id_fee($pkg);
$split_form_id_fee   = acc_split_form_id_fee($form_id_fee);
$form_fee_one_time   = (float)$split_form_id_fee['form_fee'];
$id_card_fee_one_time = (float)$split_form_id_fee['id_card_fee'];
// One-time Project Fee snapshotted on the package (0.00 unless assigned, e.g. batch 261)
$project_fee_one_time = acc_package_project_fee($pkg);
// One-time Bi-Tri Shift Merge fee: absorbs the Fixed Institutional Fees removed
// by the bulk-edit Target Monthly Total rebalance for students who moved from
// bi-semester to trimester (0.00 for everyone else)
$bitri_shift_fee_one_time = (float)($pkg['bi_tri_shift_fee'] ?? 0.0);

$pending_requests_by_id          = [];
$pending_projection_by_sem       = [];
$pending_projection_by_request   = [];
foreach ($pending_vc_approvals as $pva) {
    $pending_requests_by_id[(int)$pva['id']] = $pva;
}

if (!empty($pending_vc_approvals) && !empty($semester_fees)) {
    $pending_sorted = $pending_vc_approvals;
    usort($pending_sorted, static function ($a, $b): int {
        $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($ta === $tb) {
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        }
        return $ta <=> $tb;
    });

    foreach ($semester_fees as $sf_row) {
        $sf_id = (int)$sf_row['id'];
        $run_tuition = (float)($sf_row['tuition_payable'] ?? 0);
        $run_fixed   = max(0.0, $sem_fixed_portion - (float)($sf_row['fixed_discount_amount'] ?? 0));
        $run_english = max(0.0, $sem_english_portion - (float)($sf_row['english_discount_amount'] ?? 0));
        $sem_request_ids = [];

        foreach ($pending_sorted as $pva) {
            $apply_to_all = (int)($pva['apply_to_all'] ?? 0) === 1;
            $target_sf_id = (int)($pva['sf_id'] ?? 0);
            if (!$apply_to_all && $target_sf_id !== $sf_id) {
                continue;
            }

            $request_id = (int)$pva['id'];
            $sem_request_ids[$request_id] = true;

            $type = $pva['discount_type'] ?? 'percentage';
            $pct  = (float)($pva['discount_pct'] ?? 0);
            $fixed_amount = (float)($pva['fixed_amount'] ?? 0);

            $tuition_disc = $type === 'fixed'
                ? round(min($run_tuition, $fixed_amount), 2)
                : round(min($run_tuition, $run_tuition * $pct / 100), 2);
            $run_tuition = max(0.0, $run_tuition - $tuition_disc);

            $fixed_disc = 0.0;
            if ($type === 'percentage' && !empty($pva['applies_to_fixed']) && $run_fixed > 0) {
                $fixed_disc = round(min($run_fixed, $run_fixed * $pct / 100), 2);
                $run_fixed = max(0.0, $run_fixed - $fixed_disc);
            }

            $english_disc = 0.0;
            if ($type === 'percentage' && !empty($pva['applies_to_english']) && $run_english > 0) {
                $english_disc = round(min($run_english, $run_english * $pct / 100), 2);
                $run_english = max(0.0, $run_english - $english_disc);
            }

            if (!isset($pending_projection_by_request[$request_id])) {
                $pending_projection_by_request[$request_id] = [
                    'tuition' => 0.0,
                    'fixed' => 0.0,
                    'english' => 0.0,
                    'total' => 0.0,
                    'by_sem' => [],
                ];
            }
            if (!isset($pending_projection_by_request[$request_id]['by_sem'][$sf_id])) {
                $pending_projection_by_request[$request_id]['by_sem'][$sf_id] = 0.0;
            }
            $this_request_total = ($tuition_disc + $fixed_disc + $english_disc);
            $pending_projection_by_request[$request_id]['tuition'] += $tuition_disc;
            $pending_projection_by_request[$request_id]['fixed'] += $fixed_disc;
            $pending_projection_by_request[$request_id]['english'] += $english_disc;
            $pending_projection_by_request[$request_id]['total'] += $this_request_total;
            $pending_projection_by_request[$request_id]['by_sem'][$sf_id] += $this_request_total;
        }

        $pending_projection_by_sem[$sf_id] = [
            'tuition_payable' => $run_tuition,
            'fixed_payable' => $run_fixed,
            'english_payable' => $run_english,
            'request_ids' => array_keys($sem_request_ids),
        ];
    }
}

// Also fetch current global values for comparison (optional display)
$cf_settings_global  = db()->query('SELECT reg_fee_per_semester, form_id_fee, start_month FROM cf_settings WHERE id = 1')->fetch();

$payment_start = acc_package_payment_start($pkg, $semester_fees);
$start_month   = (int)($payment_start['month'] ?? 0);
if ($start_month < 1 || $start_month > 12) {
    $start_month = $cf_settings_global
        ? (int)($cf_settings_global['start_month'] ?? CF_DEFAULT_START_MONTH)
        : CF_DEFAULT_START_MONTH;
}

// Semester drop context for this student (drives the "Semester Drop" banner /
// month badges so paused months are not shown as a normal due).
$sd_student_id = (int)($pkg['student_id'] ?? 0);
$sd_start_year = (int)($payment_start['year'] ?? date('Y'));
$sd_drop_now   = ($sd_student_id > 0 && function_exists('sd_student_on_drop_now'))
    ? sd_student_on_drop_now($sd_student_id) : null;
$sd_dropout    = ($sd_student_id > 0 && function_exists('sd_active_dropout_for_student'))
    ? sd_active_dropout_for_student($sd_student_id) : null;

// Semester 1 reg fee is now shown in the registration column together with all other semesters.
$total_reg_fees      = $reg_fee_per_sem * count($semester_fees);

// Admission Day Payment = base admission fee only (reg fee and form/ID card fee are counted separately)
$admission_fee       = (float)($pkg['admission_fees'] ?? 0);

// Totals (including pending VC scholarship projections)
$total_tuition_payable = 0.0;
$total_fixed_all       = 0.0;
$total_english_all     = 0.0;
foreach ($semester_fees as $sf) {
    $sf_id = (int)$sf['id'];
    $proj = $pending_projection_by_sem[$sf_id] ?? null;
    $sem_tuition_payable = $proj
        ? (float)$proj['tuition_payable']
        : (float)$sf['tuition_payable'];
    $sem_fixed_payable = $proj
        ? (float)$proj['fixed_payable']
        : max(0.0, $sem_fixed_portion - (float)($sf['fixed_discount_amount'] ?? 0));
    $sem_english_payable = $proj
        ? (float)$proj['english_payable']
        : max(0.0, $sem_english_portion - (float)($sf['english_discount_amount'] ?? 0));

    // Fixed and merit packages are billed identically per semester: the payable
    // tuition is the Base Tuition / Semester. The Fixed Monthly Payment is static
    // and is never used in any total.
    $total_tuition_payable += $sem_tuition_payable;
    $total_fixed_all       += $sem_fixed_payable;
    $total_english_all     += $sem_english_payable;
}
$total_cost = $total_tuition_payable + $total_fixed_all + $total_english_all + $total_reg_fees + $admission_fee + $form_id_fee + $project_fee_one_time + $bitri_shift_fee_one_time;

// ── OLD ERP payable cross-check (proof screenshot vs Grand Total, ±50 BDT) ────
$old_erp_payable = (isset($pkg['old_erp_payable_amount']) && $pkg['old_erp_payable_amount'] !== null)
    ? (float)$pkg['old_erp_payable_amount']
    : null;
// OLD ERP payable excludes the one-time Form, ID Card and Project fees
$old_erp_check = sfp_old_erp_check($old_erp_payable, $total_cost, $project_fee_one_time, $form_id_fee);
// OLD ERP Monthly Payment cross-check (±SFP_OLD_ERP_MONTHLY_TOLERANCE BDT)
$old_erp_monthly = (isset($pkg['old_erp_monthly_amount']) && $pkg['old_erp_monthly_amount'] !== null)
    ? (float)$pkg['old_erp_monthly_amount'] : null;
$erp_expected_monthly = sfp_expected_monthly_total(
    $pkg,
    !empty($semester_fees) ? (float)$semester_fees[0]['tuition_payable'] : 0.0
);
$erp_monthly_check = sfp_old_erp_monthly_check($old_erp_monthly, $erp_expected_monthly);
$erp_basis_labels = [
    'base_minus_project' => 'Grand Total − Form & ID Card − Project Fee',
    'base_minus_1000'    => 'Grand Total − Form & ID Card − 1,000 BDT (project fee cross-check)',
    'base'               => 'Grand Total − Form & ID Card fees',
];
// Newest image proof to feed the client-side OCR auto-check
$erp_ocr_proof_url = null;
foreach ($old_erp_proofs as $erp_proof_row) {
    if (strncmp((string)($erp_proof_row['mime_type'] ?? ''), 'image/', 6) === 0) {
        $erp_ocr_proof_url = UPLOAD_URL . '/students/files/' . rawurlencode($erp_proof_row['stored_name']);
        break;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">
            <i class="fas fa-file-invoice-dollar me-2 text-success"></i>
            Student Account – <?= h($pkg['student_name']) ?>
        </h1>
        <div class="mt-1">
            <span class="badge bg-success" title="This fee package is a locked accounting snapshot. Editing the course/program fees — and any CSV re-import — will not change these figures.">
                <i class="fas fa-lock me-1"></i>Protected accounting record
            </span>
        </div>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-accounts/index.php">Student Accounts</a></li>
            <li class="breadcrumb-item active"><?= h($pkg['student_name']) ?></li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/students/view.php?id=<?= $pkg['student_id'] ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-user-graduate me-1"></i> Student Profile
        </a>
        <a href="<?= APP_URL ?>/student-accounts/statement.php?id=<?= $id ?>"
           class="btn btn-outline-success btn-sm" target="_blank">
            <i class="fas fa-file-invoice me-1"></i> Download Statement
        </a>
        <?php if (sfp_can_delete()): ?>
        <form method="post" action="<?= APP_URL ?>/student-accounts/delete.php"
              onsubmit="return confirm('Delete this student account? All semester fee records will be lost.');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-outline-danger btn-sm">
                <i class="fas fa-trash me-1"></i> Delete
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<?php if ($sd_drop_now): ?>
<div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
    <i class="fas fa-pause-circle fa-lg"></i>
    <div>
        <strong>Semester Drop active.</strong>
        This student is on a <?= h(sd_type_label($sd_drop_now['semester_type'])) ?> break from
        <strong><?= h(date('d M Y', strtotime($sd_drop_now['drop_start']))) ?></strong> to
        <strong><?= h(date('d M Y', strtotime($sd_drop_now['drop_end']))) ?></strong>.
        Monthly tuition for these months is <em>not counted as a due</em>.
        <?php if (function_exists('sd_can_view') && sd_can_view()): ?>
        <a href="<?= APP_URL ?>/semester-drop/index.php?q=<?= rawurlencode((string)$pkg['student_sid']) ?>" class="alert-link">View drops</a>.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($sd_dropout): ?>
<div class="alert alert-dark d-flex align-items-center gap-2" role="alert">
    <i class="fas fa-user-slash fa-lg"></i>
    <div>
        <strong>Official dropout – account frozen.</strong>
        This student officially dropped out on
        <strong><?= h(date('d M Y', strtotime($sd_dropout['drop_start']))) ?></strong>.
        From that date the account is <em>frozen and no longer counted as a due</em> in any financial fact.
        <?php if (function_exists('sd_can_view') && sd_can_view()): ?>
        <a href="<?= APP_URL ?>/semester-drop/index.php?kind=dropout&q=<?= rawurlencode((string)$pkg['student_sid']) ?>" class="alert-link">View dropout</a>.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

     Formula: Standard Tuition (Full) + Fixed Institutional Fees (total) + English Course Fee (total)
═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-primary">
            <div class="card-body">
                <div class="text-muted small mb-1">Standard Tuition (Full)</div>
                <div class="fw-bold fs-5"><?= sfp_money((float)$pkg['standard_tuition_full']) ?></div>
                <div class="text-muted" style="font-size:.75rem;">
                    <?= sfp_money((float)$pkg['tuition_per_semester']) ?> &times; <?= (int)$pkg['total_semesters'] ?> semesters (base rate)
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-warning">
            <div class="card-body">
                <div class="text-muted small mb-1">Fixed Institutional Fees</div>
                <div class="fw-bold fs-5"><?= sfp_money((float)$pkg['fixed_institutional_fees']) ?></div>
                <div class="text-muted" style="font-size:.75rem;">Total for <?= (int)$pkg['total_months'] ?> months</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-info">
            <div class="card-body">
                <div class="text-muted small mb-1">English Course Fee</div>
                <div class="fw-bold fs-5"><?= sfp_money((float)$pkg['english_course_fee']) ?></div>
                <div class="text-muted" style="font-size:.75rem;">Total for programme</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-success">
            <div class="card-body">
                <div class="text-muted small mb-1">Est. Total Payable</div>
                <div class="fw-bold fs-5"><?= sfp_money($total_cost) ?></div>
                <div class="text-muted" style="font-size:.75rem;">After scholarship deductions</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- ── Fee Constants Snapshot ── -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-clipboard-list me-2 text-muted"></i>Fee Constants Snapshot
                </h6>
            </div>
            <div class="card-body px-4">
                <?php
                $is_fixed_payment = (($pkg['payment_type'] ?? 'merit') === 'fixed');
                $constants = [
                    'Programme'                  => $pkg['program_name'],
                    'Payment Type'               => $is_fixed_payment ? 'Fixed' : 'Merit based',
                    'Total Semesters'            => $pkg['total_semesters'],
                    'Total Months'               => $pkg['total_months'],
                    'Months / Semester'          => number_format((float)$pkg['months_per_semester'], 2),
                    'Standard Tuition (Full)'    => sfp_money((float)$pkg['standard_tuition_full']),
                    'Base Tuition / Semester'    => sfp_money((float)$pkg['tuition_per_semester']),
                    'Registration Fee / Semester' => sfp_money($reg_fee_per_sem),
                    'Admission Fee (one-time)'        => sfp_money((float)($pkg['admission_fees'] ?? 0)),
                    'Form Fee (one-time)'        => sfp_money($form_fee_one_time),
                    'ID Card Fee (one-time)'     => sfp_money($id_card_fee_one_time),
                    'Project Fee (one-time)'     => sfp_money($project_fee_one_time),
                    'Bi-Tri Shift Merge (one-time)' => sfp_money($bitri_shift_fee_one_time),
                    'Fixed Institutional Fees'   => sfp_money((float)$pkg['fixed_institutional_fees']),
                    'English Course Fee'         => sfp_money((float)$pkg['english_course_fee']),
                ];
                if ($is_fixed_payment) {
                    $constants['Fixed Monthly Payment'] = sfp_money((float)($pkg['monthly_payment'] ?? 0));
                }
                if ($pkg['safety_net_cap']) {
                    $constants['Safety Net Cap']          = sfp_money((float)$pkg['safety_net_cap']);
                    $constants['Safety Net / Semester']   = sfp_money((float)$pkg['safety_net_per_semester']);
                    $constants['Attendance Requirement']  = $pkg['attendance_requirement'] . '%';
                    $constants['Safety Net GPA Threshold'] = number_format((float)$pkg['safety_net_gpa_threshold'], 2);
                }
                foreach ($constants as $label => $val):
                ?>
                <div class="d-flex mb-2 gap-2">
                    <div style="min-width:210px;font-size:.8rem;color:#6b7280;font-weight:600;"><?= $label ?></div>
                    <div style="font-size:.875rem;"><?= h((string)$val) ?></div>
                </div>
                <?php endforeach; ?>

                <!-- Payment Start Month (snapshotted) -->
                <hr class="my-2">
                <?php
                $month_names = [
                    1=>'January',2=>'February',3=>'March',4=>'April',
                    5=>'May',6=>'June',7=>'July',8=>'August',
                    9=>'September',10=>'October',11=>'November',12=>'December',
                ];
                $pkg_total_semesters = (int)($pkg['total_semesters'] ?? 0);
                $is_bi_pkg = $pkg_total_semesters > 0 && $pkg_total_semesters <= SFP_MAX_BI_SEMESTER_COUNT;
                $snap_month = $is_bi_pkg
                    ? (int)($pkg['bi_semester_start_month']  ?? 0)
                    : (int)($pkg['tri_semester_start_month'] ?? 0);
                $snap_source = 'snapshotted';
                if ($snap_month < 1 || $snap_month > 12) {
                    $snap_month  = $start_month; // resolved via acc_package_payment_start
                    $snap_source = 'live programme';
                }
                // $snap_month is guaranteed valid (1-12) from acc_package_payment_start fallback
                $snap_label = isset($month_names[$snap_month]) ? $month_names[$snap_month] : '—';
                ?>
                <div class="d-flex mb-2 gap-2 align-items-start">
                    <div style="min-width:210px;font-size:.8rem;color:#6b7280;font-weight:600;">Payment Start Month</div>
                    <div style="font-size:.875rem;">
                        <?= h($snap_label) ?>
                        <?php if ($snap_source === 'live programme'): ?>
                        <span class="badge bg-warning text-dark ms-1" title="No start month is snapshotted on this package — using the current programme value. Run student-package-start-month-v2.sql to backfill.">live</span>
                        <?php else: ?>
                        <span class="badge bg-success ms-1" title="Start month is snapshotted and will not change when the course fee is edited.">locked</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($pkg['note']): ?>
                <hr class="my-2">
                <div class="text-muted" style="font-size:.8rem;"><strong>Note:</strong> <?= h($pkg['note']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Student Info ── -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-user-graduate me-2 text-muted"></i>Student
                </h6>
            </div>
            <div class="card-body px-4">
                <?php
                $sinfo = [
                    'Name'            => $pkg['student_name'],
                    'Student ID'      => $pkg['student_sid'],
                    'Admitted'        => $pkg['admitted_semester'],
                    'Status'          => $pkg['student_status'],
                    'Package Assigned' => date('d M Y, H:i', strtotime($pkg['created_at'])),
                    'Assigned By'     => $pkg['assigned_by_name'] ?? '—',
                ];
                foreach ($sinfo as $label => $val):
                ?>
                <div class="d-flex mb-2 gap-2">
                    <div style="min-width:150px;font-size:.8rem;color:#6b7280;font-weight:600;"><?= $label ?></div>
                    <div style="font-size:.875rem;"><?= h((string)$val) ?></div>
                </div>
                <?php endforeach; ?>

                <?php if (!empty($old_erp_proofs)): ?>
                <hr class="my-2">
                <div class="d-flex mb-2 gap-2 align-items-start">
                    <div style="min-width:150px;font-size:.8rem;color:#6b7280;font-weight:600;">OLD ERP Proof</div>
                    <div style="font-size:.875rem;">
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($old_erp_proofs as $proof):
                                    $proof_url = UPLOAD_URL . '/students/files/' . rawurlencode($proof['stored_name']);
                                    $is_image  = strncmp((string)($proof['mime_type'] ?? ''), 'image/', 6) === 0;
                                ?>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <?php if ($is_image): ?>
                                    <a href="<?= h($proof_url) ?>" target="_blank" rel="noopener">
                                        <img src="<?= h($proof_url) ?>" alt="OLD ERP Proof"
                                             style="height:48px;width:48px;object-fit:cover;border-radius:.375rem;border:1px solid #dee2e6;">
                                    </a>
                                    <?php endif; ?>
                                    <a href="<?= h($proof_url) ?>" target="_blank" rel="noopener"
                                       class="btn btn-outline-success btn-sm">
                                        <i class="fas fa-eye me-1"></i>View
                                    </a>
                                    <a href="<?= h($proof_url) ?>"
                                       download="<?= h($proof['original_name']) ?>"
                                       class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-download me-1"></i>Download
                                    </a>
                                    <span class="text-muted" style="font-size:.75rem;">
                                        <?= h($proof['original_name']) ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SEMESTER FEES TABLE
═══════════════════════════════════════════════════════════ -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-table me-2 text-muted"></i>Semester-wise Fee Breakdown
        </h6>
        <span class="badge bg-secondary"><?= count($semester_fees) ?> semesters</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.875rem;">
                <thead>
                    <tr>
                        <th style="width:45px;">#</th>
                        <th>Semester</th>
                        <th class="text-end">Tuition Fee</th>
                        <th>Scholarships</th>
                        <th class="text-end">Tuition Payable</th>
                        <th class="text-end">Fixed Fees<br><small class="fw-normal text-muted">(per semester)</small></th>
                        <th class="text-end">English Fee<br><small class="fw-normal text-muted">(per semester)</small></th>
                        <th class="text-end">Registration Fee<br><small class="fw-normal text-muted">(per semester)</small></th>
                        <th class="text-end fw-bold">Total Payable</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $grand_tuition_payable = 0.0;
                $grand_fixed           = 0.0;
                $grand_english         = 0.0;
                $grand_total           = 0.0;
                foreach ($semester_fees as $sf):
                    $sf_id_row       = (int)$sf['id'];
                    $tuition_fee_row = (float)$sf['tuition_fee'];
                    $projection      = $pending_projection_by_sem[$sf_id_row] ?? null;
                    $tuition_payable = $projection
                        ? (float)$projection['tuition_payable']
                        : (float)$sf['tuition_payable'];
                    $fixed_amt       = $projection
                        ? (float)$projection['fixed_payable']
                        : max(0.0, $sem_fixed_portion  - (float)($sf['fixed_discount_amount']   ?? 0));
                    $english_amt     = $projection
                        ? (float)$projection['english_payable']
                        : max(0.0, $sem_english_portion - (float)($sf['english_discount_amount'] ?? 0));
                    // Registration fee is shown for all semesters
                    $sem_reg         = $reg_fee_per_sem;
                    $total_sem       = $tuition_payable + $fixed_amt + $english_amt + $sem_reg;
                    $grand_tuition_payable += $tuition_payable;
                    $grand_fixed           += $fixed_amt;
                    $grand_english         += $english_amt;
                    $grand_total           += $total_sem;
                    $sem_scholarships = $all_scholarships[$sf_id_row] ?? [];
                    $pending_sem_request_ids = $projection['request_ids'] ?? [];
                ?>
                <tr>
                    <td class="fw-semibold text-muted"><?= (int)$sf['semester_number'] ?></td>
                    <td>
                        <span class="fw-semibold"><?= h($sf['semester_label'] ?: '—') ?></span>
                        <?php if (sfp_can_edit()): ?>
                        <button type="button"
                                class="btn btn-link btn-sm p-0 ms-1 text-muted set-label-btn"
                                style="font-size:.7rem;"
                                data-sf-id="<?= $sf_id_row ?>"
                                data-sem-num="<?= (int)$sf['semester_number'] ?>"
                                data-current="<?= h($sf['semester_label'] ?? '') ?>"
                                title="Set semester label">
                            <i class="fas fa-pen"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <span class="text-muted"><?= sfp_money($tuition_fee_row) ?></span>
                        <?php if (sfp_can_edit()): ?>
                        <button type="button"
                                class="btn btn-link btn-sm p-0 ms-1 text-muted edit-tuition-btn"
                                style="font-size:.7rem;"
                                data-sf-id="<?= $sf_id_row ?>"
                                data-sem-num="<?= $sf['semester_number'] ?>"
                                data-tuition="<?= $tuition_fee_row ?>"
                                title="Edit tuition fee">
                            <i class="fas fa-pen"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($sem_scholarships)): ?>
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <?php foreach ($sem_scholarships as $sc): ?>
                            <span class="badge rounded-pill bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25"
                                  style="font-size:.72rem;font-weight:500;">
                                <?= h($sc['label']) ?>&nbsp;(<?php
                                    if (($sc['discount_type'] ?? 'percentage') === 'fixed'):
                                        echo 'BDT ' . number_format((float)($sc['fixed_amount'] ?? $sc['amount']), 2);
                                    else:
                                        echo number_format((float)$sc['discount_pct'], 1) . '%';
                                    endif;
                                ?>)
                                <?php if ((int)$sc['applies_to_fixed']): ?>
                                <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;vertical-align:middle;">+Fixed</span>
                                <?php endif; ?>
                                <?php if ((int)$sc['applies_to_english']): ?>
                                <span class="badge bg-info text-dark ms-1" style="font-size:.6rem;vertical-align:middle;">+ENG</span>
                                <?php endif; ?>
                                <?php if ($sc['doc_stored_name']): ?>
                                <a href="<?= UPLOAD_URL ?>/students/files/<?= rawurlencode($sc['doc_stored_name']) ?>"
                                   target="_blank"
                                   class="ms-1 text-danger text-opacity-75"
                                   title="Supporting doc: <?= h($sc['doc_original_name']) ?>"
                                   style="font-size:.7rem;">
                                    <i class="fas fa-paperclip"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (sfp_can_edit()): ?>
                                <form method="post" action="<?= APP_URL ?>/student-accounts/delete-scholarship.php"
                                      class="d-inline"
                                      onsubmit="return confirm('Remove scholarship \'<?= h(addslashes($sc['label'])) ?>\'?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="scholarship_id" value="<?= $sc['id'] ?>">
                                    <input type="hidden" name="package_id" value="<?= $id ?>">
                                    <button type="submit" class="btn p-0 border-0 bg-transparent text-danger ms-1"
                                            style="font-size:.65rem;line-height:1;vertical-align:middle;"
                                            title="Remove this scholarship">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </span>
                            <?php endforeach; ?>
                            <?php foreach ($pending_sem_request_ids as $pending_id):
                                $pending_sc = $pending_requests_by_id[$pending_id] ?? null;
                                if (!$pending_sc) continue;
                                $pending_total = (float)($pending_projection_by_request[$pending_id]['by_sem'][$sf_id_row] ?? 0);
                            ?>
                            <span class="badge rounded-pill bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25"
                                  style="font-size:.72rem;font-weight:500;">
                                <?= h($pending_sc['label']) ?>&nbsp;(Pending)
                                <span class="ms-1">−<?= number_format($pending_total, 2) ?></span>
                            </span>
                            <?php endforeach; ?>
                            <?php if (sfp_can_edit()): ?>
                            <button type="button"
                                    class="btn btn-outline-warning btn-sm add-sc-btn"
                                    style="font-size:.7rem;padding:1px 6px;"
                                    data-sf-id="<?= $sf_id_row ?>"
                                    data-sem-num="<?= $sf['semester_number'] ?>"
                                    data-sem-label="<?= h($sf['semester_label'] ?? 'Semester ' . $sf['semester_number']) ?>"
                                    data-tuition="<?= $tuition_fee_row ?>"
                                    title="Add another scholarship">
                                <i class="fas fa-plus"></i>
                            </button>
                            <?php if ((float)$sf['scholarship_amount'] > 0): ?>
                            <form method="post" action="<?= APP_URL ?>/student-accounts/remove-scholarship.php"
                                  class="d-inline"
                                  onsubmit="return confirm('Remove ALL scholarships from this semester?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="sf_id" value="<?= $sf_id_row ?>">
                                <input type="hidden" name="package_id" value="<?= $id ?>">
                                <button class="btn btn-outline-secondary btn-sm" style="font-size:.7rem;padding:1px 6px;" title="Clear all scholarships">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <?php if (!empty($pending_sem_request_ids)): ?>
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <?php foreach ($pending_sem_request_ids as $pending_id):
                                $pending_sc = $pending_requests_by_id[$pending_id] ?? null;
                                if (!$pending_sc) continue;
                                $pending_total = (float)($pending_projection_by_request[$pending_id]['by_sem'][$sf_id_row] ?? 0);
                            ?>
                            <span class="badge rounded-pill bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25"
                                  style="font-size:.72rem;font-weight:500;">
                                <?= h($pending_sc['label']) ?>&nbsp;(Pending)
                                <span class="ms-1">−<?= number_format($pending_total, 2) ?></span>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                        <?php if (sfp_can_edit()): ?>
                        <button type="button"
                                class="btn btn-outline-warning btn-sm add-sc-btn ms-1"
                                style="font-size:.7rem;padding:1px 6px;"
                                data-sf-id="<?= $sf_id_row ?>"
                                data-sem-num="<?= $sf['semester_number'] ?>"
                                data-sem-label="<?= h($sf['semester_label'] ?? 'Semester ' . $sf['semester_number']) ?>"
                                data-tuition="<?= $tuition_fee_row ?>"
                                title="Add scholarship">
                            <i class="fas fa-plus me-1"></i><span style="font-size:.7rem;">Add</span>
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold"><?= sfp_money($tuition_payable) ?></td>
                    <td class="text-end"><?= sfp_money($fixed_amt) ?></td>
                    <td class="text-end"><?= sfp_money($english_amt) ?></td>
                    <td class="text-end">
                        <?php if ($sem_reg > 0): ?>
                            <?= sfp_money($sem_reg) ?>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:.75rem;" title="Paid on admission day">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-bold text-success"><?= sfp_money($total_sem) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">Totals →</td>
                        <td class="text-end"><?= sfp_money($grand_tuition_payable) ?></td>
                        <td class="text-end"><?= sfp_money($grand_fixed) ?></td>
                        <td class="text-end"><?= sfp_money($grand_english) ?></td>
                        <td class="text-end"><?= sfp_money($total_reg_fees) ?></td>
                        <td class="text-end text-success fs-6"><?= sfp_money($grand_total) ?></td>
                    </tr>
                    <tr class="table-warning">
                        <td colspan="8" class="text-end">Admission Fee (one-time) →</td>
                        <td class="text-end text-warning-emphasis fs-6"><?= sfp_money($admission_fee) ?></td>
                    </tr>
                    <tr class="table-warning">
                        <td colspan="8" class="text-end">Form Fee (one-time) →</td>
                        <td class="text-end text-warning-emphasis fs-6"><?= sfp_money($form_fee_one_time) ?></td>
                    </tr>
                    <tr class="table-warning">
                        <td colspan="8" class="text-end">ID Card Fee (one-time) →</td>
                        <td class="text-end text-warning-emphasis fs-6"><?= sfp_money($id_card_fee_one_time) ?></td>
                    </tr>
                    <?php if ($project_fee_one_time > 0): ?>
                    <tr class="table-warning">
                        <td colspan="8" class="text-end">Project Fee (one-time) →</td>
                        <td class="text-end text-warning-emphasis fs-6"><?= sfp_money($project_fee_one_time) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($bitri_shift_fee_one_time > 0): ?>
                    <tr class="table-warning">
                        <td colspan="8" class="text-end"
                            title="One-time fee absorbing the Fixed Institutional Fees removed when this student moved from bi-semester to trimester (Target Monthly Total rebalance). Keeps the Grand Total unchanged.">
                            Bi-Tri Shift Merge (one-time) →
                        </td>
                        <td class="text-end text-warning-emphasis fs-6"><?= sfp_money($bitri_shift_fee_one_time) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="table-success">
                        <td colspan="8" class="text-end fw-bold">Grand Total (incl. Admission, Form & ID Card<?= $project_fee_one_time > 0 ? ' & Project' : '' ?><?= $bitri_shift_fee_one_time > 0 ? ' & Bi-Tri Shift' : '' ?> Fees) →</td>
                        <td class="text-end fw-bold text-success fs-5"><?= sfp_money($grand_total + $admission_fee + $form_id_fee + $project_fee_one_time + $bitri_shift_fee_one_time) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     OLD ERP PAYABLE CROSS-CHECK
═══════════════════════════════════════════════════════ -->
<div class="card mt-4 <?= ($old_erp_check !== null && !$old_erp_check['matched']) ? 'border-danger' : '' ?>" id="erp-check-card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-scale-balanced me-2 text-muted"></i>OLD ERP Payable Cross-Check
            <span class="text-muted fw-normal ms-2" style="font-size:.75rem;">Grand Total vs old-ERP proof · tolerance ±<?= number_format(SFP_OLD_ERP_TOLERANCE, 0) ?> BDT</span>
        </h6>
        <span id="erp-check-badge">
            <?php if ($old_erp_check === null): ?>
            <span class="badge bg-secondary"><i class="fas fa-hourglass-half me-1"></i>Not checked yet</span>
            <?php elseif ($old_erp_check['matched']): ?>
            <span class="badge bg-success"><i class="fas fa-check me-1"></i>Match (Δ <?= number_format($old_erp_check['best_diff'], 2) ?> BDT)</span>
            <?php else: ?>
            <span class="badge bg-danger"><i class="fas fa-triangle-exclamation me-1"></i>MISMATCH (Δ <?= number_format($old_erp_check['best_diff'], 2) ?> BDT)</span>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body px-4">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="text-muted small mb-1">OLD ERP Payable Amount (proof)</div>
                <div class="fw-bold fs-5" id="erp-payable-display"><?= $old_erp_payable !== null ? sfp_money($old_erp_payable) : '—' ?></div>
                <div class="text-muted" style="font-size:.75rem;" id="erp-source-note"><?php
                    if ($old_erp_payable !== null) {
                        echo (($pkg['old_erp_payable_source'] ?? '') === 'manual') ? 'Entered manually' : 'Read automatically (OCR)';
                        if (!empty($pkg['old_erp_checked_at'])) {
                            echo ' · ' . h(date('d M Y, H:i', strtotime($pkg['old_erp_checked_at'])));
                        }
                    } else {
                        echo 'Not read yet';
                    }
                ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small mb-1">Grand Total (incl. one-time fees)</div>
                <div class="fw-bold fs-5"><?= sfp_money($total_cost) ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small mb-1">Expected OLD ERP Payable
                    <span class="d-block" style="font-size:.7rem;">Grand − Form &amp; ID Card<?= $project_fee_one_time > 0 ? ' − Project Fee' : ' − 1,000 (project fee)' ?></span>
                </div>
                <div class="fw-bold fs-5"><?= sfp_money($total_cost - $form_id_fee - ($project_fee_one_time > 0 ? $project_fee_one_time : SFP_OLD_ERP_STANDARD_PROJECT_FEE)) ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small mb-1">Matched against</div>
                <div style="font-size:.875rem;" id="erp-basis">
                    <?= $old_erp_check !== null ? h($erp_basis_labels[$old_erp_check['basis']] ?? $old_erp_check['basis']) : '—' ?>
                </div>
            </div>
        </div>

        <hr class="my-3">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="text-muted small mb-1">OLD ERP Monthly Payment (proof)</div>
                <div class="fw-bold fs-5" id="erp-monthly-display"><?= $old_erp_monthly !== null ? sfp_money($old_erp_monthly) : '—' ?></div>
                <div class="text-muted" style="font-size:.75rem;" id="erp-monthly-note"><?= $old_erp_monthly !== null ? 'Read from proof / entered' : 'Not read yet' ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small mb-1">Expected Monthly Total
                    <span class="d-block" style="font-size:.7rem;">Semester 1 · tuition + fixed + English per month</span>
                </div>
                <div class="fw-bold fs-5"><?= sfp_money($erp_expected_monthly) ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small mb-1">Monthly cross-check (±<?= number_format(SFP_OLD_ERP_MONTHLY_TOLERANCE, 0) ?> BDT)</div>
                <div id="erp-monthly-badge">
                    <?php if ($erp_monthly_check === null): ?>
                    <span class="badge bg-secondary"><i class="fas fa-hourglass-half me-1"></i>Not checked yet</span>
                    <?php elseif ($erp_monthly_check['matched']): ?>
                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>Match (Δ <?= number_format(abs($erp_monthly_check['diff']), 2) ?> BDT)</span>
                    <?php else: ?>
                    <span class="badge bg-danger"><i class="fas fa-triangle-exclamation me-1"></i>MISMATCH (Δ <?= number_format(abs($erp_monthly_check['diff']), 2) ?> BDT)</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <hr class="my-3">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="text-muted small mb-1">Registration Fee – Received (proof)</div>
                <div class="fw-bold fs-5" id="erp-reg-received-display"><?php
                    $erp_reg_rcv = $pkg['old_erp_reg_received_amount'] ?? null;
                    $erp_reg_pay = $pkg['old_erp_reg_payable_amount'] ?? null;
                    echo $erp_reg_rcv !== null ? sfp_money((float)$erp_reg_rcv) : '—';
                ?></div>
                <div class="text-muted" style="font-size:.75rem;" id="erp-reg-note"><?php
                    if ($erp_reg_rcv !== null) {
                        echo (($pkg['old_erp_reg_source'] ?? '') === 'manual') ? 'Entered manually' : 'Read automatically (OCR)';
                    } else {
                        echo 'Not read yet';
                    }
                ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small mb-1">Registration Fee – Payable (proof)</div>
                <div class="fw-bold fs-5" id="erp-reg-payable-display"><?= $erp_reg_pay !== null ? sfp_money((float)$erp_reg_pay) : '—' ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small mb-1">Registration Fee – Due (proof)</div>
                <div class="fw-bold fs-5" id="erp-reg-due-display"><?= ($erp_reg_rcv !== null && $erp_reg_pay !== null) ? sfp_money(max(0.0, (float)$erp_reg_pay - (float)$erp_reg_rcv)) : '—' ?></div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small mb-1">Used by the Old ERP Totals Merge</div>
                <div class="text-muted" style="font-size:.8rem;">
                    Only the <strong>Received</strong> amount is marked paid as registration on merge —
                    the rest of the registration fees stay as <strong>dues</strong> and the money is
                    merged into the monthly payments instead.
                </div>
            </div>
        </div>

        <div id="erp-ocr-status" class="small text-muted mt-3"></div>

        <div class="d-flex gap-2 mt-2 flex-wrap align-items-center">
            <?php if ($erp_ocr_proof_url !== null): ?>
            <button type="button" class="btn btn-outline-primary btn-sm" id="erp-run-ocr">
                <i class="fas fa-wand-magic-sparkles me-1"></i>Re-read from Proof (OCR)
            </button>
            <?php else: ?>
            <span class="text-muted small"><i class="fas fa-circle-info me-1"></i>No OLD ERP proof image attached – the auto-check cannot run. Upload one via Bulk OLD ERP Proof Upload.</span>
            <?php endif; ?>
            <?php if (sfp_can_edit()): ?>
            <div class="input-group input-group-sm" style="max-width:300px;">
                <input type="number" step="0.01" min="0" class="form-control" id="erp-manual-amount"
                       placeholder="Payable amount (manual override)">
                <button type="button" class="btn btn-outline-secondary" id="erp-manual-save">Save</button>
            </div>
            <div class="input-group input-group-sm" style="max-width:300px;">
                <input type="number" step="0.01" min="0" class="form-control" id="erp-manual-monthly"
                       placeholder="Monthly payment (manual override)">
                <button type="button" class="btn btn-outline-secondary" id="erp-manual-monthly-save">Save</button>
            </div>
            <div class="input-group input-group-sm" style="max-width:320px;">
                <input type="number" step="0.01" min="0" class="form-control" id="erp-manual-reg"
                       placeholder="Registration received (manual override)">
                <button type="button" class="btn btn-outline-secondary" id="erp-manual-reg-save">Save</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var CFG = {
        packageId:     <?= (int)$id ?>,
        grandTotal:    <?= json_encode(round($total_cost, 2)) ?>,
        projectFee:    <?= json_encode(round($project_fee_one_time, 2)) ?>,
        formIdFee:     <?= json_encode(round($form_id_fee, 2)) ?>,
        tolerance:     <?= json_encode((float)SFP_OLD_ERP_TOLERANCE) ?>,
        stdProjectFee: <?= json_encode((float)SFP_OLD_ERP_STANDARD_PROJECT_FEE) ?>,
        expectedMonthly:  <?= json_encode(round($erp_expected_monthly, 2)) ?>,
        monthlyTolerance: <?= json_encode((float)SFP_OLD_ERP_MONTHLY_TOLERANCE) ?>,
        stored:        <?= json_encode($old_erp_payable) ?>,
        regStored:     <?= json_encode(($pkg['old_erp_reg_received_amount'] ?? null) !== null ? (float)$pkg['old_erp_reg_received_amount'] : null) ?>,
        proofUrl:      <?= json_encode($erp_ocr_proof_url) ?>,
        proofUrls:     <?= json_encode(array_values(array_map(
                              static fn($p) => UPLOAD_URL . '/students/files/' . rawurlencode((string)$p['stored_name']),
                              array_filter($old_erp_proofs, static fn($p) => strncmp((string)($p['mime_type'] ?? ''), 'image/', 6) === 0)
                          ))) ?>,
        saveUrl:       <?= json_encode(APP_URL . '/student-accounts/save-erp-payable.php') ?>,
        csrfField:     <?= json_encode(CSRF_TOKEN_NAME) ?>,
        csrfToken:     <?= json_encode(csrf_token()) ?>
    };

    function $id(i) { return document.getElementById(i); }

    function setStatus(msg, danger) {
        var el = $id('erp-ocr-status');
        if (!el) return;
        el.textContent = msg || '';
        el.className = 'small mt-3 ' + (danger ? 'text-danger' : 'text-muted');
    }

    // Same match rules as sfp_old_erp_check() in helpers.php:
    // the OLD ERP payable excludes the Form, ID Card and Project fees.
    function evaluate(payable) {
        var base = CFG.grandTotal - CFG.formIdFee;
        var cands = [];
        if (CFG.projectFee > 0) {
            cands.push({ k: 'Grand Total − Form & ID Card − Project Fee', v: base - CFG.projectFee });
        }
        cands.push({ k: 'Grand Total − Form & ID Card − 1,000 BDT (project fee cross-check)', v: base - CFG.stdProjectFee });
        cands.push({ k: 'Grand Total − Form & ID Card fees', v: base });
        var best = null;
        cands.forEach(function (c) {
            var d = Math.abs(c.v - payable);
            if (!best || d < best.d) best = { k: c.k, d: d };
        });
        return { matched: best.d <= CFG.tolerance, diff: best.d, basis: best.k };
    }

    function fmt(n) {
        return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' BDT';
    }

    function applyResult(payable, sourceNote) {
        var res = evaluate(payable);
        $id('erp-payable-display').textContent = fmt(payable);
        $id('erp-basis').textContent = res.basis;
        if (sourceNote) $id('erp-source-note').textContent = sourceNote;
        var badge = $id('erp-check-badge');
        var card  = $id('erp-check-card');
        if (res.matched) {
            badge.innerHTML = '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Match (Δ ' + res.diff.toFixed(2) + ' BDT)</span>';
            card.classList.remove('border-danger');
        } else {
            badge.innerHTML = '<span class="badge bg-danger"><i class="fas fa-triangle-exclamation me-1"></i>MISMATCH (Δ ' + res.diff.toFixed(2) + ' BDT)</span>';
            card.classList.add('border-danger');
        }
    }

    function applyMonthlyResult(monthly, sourceNote) {
        var d = $id('erp-monthly-display');
        if (d) d.textContent = fmt(monthly);
        var n = $id('erp-monthly-note');
        if (n && sourceNote) n.textContent = sourceNote;
        var b = $id('erp-monthly-badge');
        if (b) {
            var diff = Math.abs(monthly - CFG.expectedMonthly);
            b.innerHTML = diff <= CFG.monthlyTolerance
                ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Match (Δ ' + diff.toFixed(2) + ' BDT)</span>'
                : '<span class="badge bg-danger"><i class="fas fa-triangle-exclamation me-1"></i>MISMATCH (Δ ' + diff.toFixed(2) + ' BDT)</span>';
        }
    }

    function applyRegResult(payable, received, sourceNote) {
        var r = $id('erp-reg-received-display');
        if (r) r.textContent = fmt(received);
        var p = $id('erp-reg-payable-display');
        if (p && payable !== null && payable !== undefined) p.textContent = fmt(payable);
        var d = $id('erp-reg-due-display');
        if (d && payable !== null && payable !== undefined) d.textContent = fmt(Math.max(0, payable - received));
        var n = $id('erp-reg-note');
        if (n && sourceNote) n.textContent = sourceNote;
    }

    function save(amount, source, monthly, reg, cb) {
        var fd = new FormData();
        fd.append(CFG.csrfField, CFG.csrfToken);
        fd.append('package_id', CFG.packageId);
        fd.append('amount', amount);
        if (monthly !== null && monthly !== undefined) fd.append('monthly', monthly);
        if (reg) {
            fd.append('reg_payable',  reg.payable);
            fd.append('reg_received', reg.received);
        }
        fd.append('source', source);
        fetch(CFG.saveUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) { cb(!!resp.success, resp); })
            .catch(function (e) { cb(false, { error: String(e) }); });
    }

    // Monthly-only screenshots: store the Monthly Payment without touching
    // the (absent) Payable Amount.
    function saveMonthlyOnly(monthly) {
        var fd = new FormData();
        fd.append(CFG.csrfField, CFG.csrfToken);
        fd.append('package_id', CFG.packageId);
        fd.append('monthly', monthly);
        fd.append('source', 'ocr');
        fetch(CFG.saveUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.success) applyMonthlyResult(monthly, 'Read automatically (OCR) · just now');
                setStatus(resp.success
                    ? 'No "Payable Amount" found, but the Monthly Payment (' + fmt(monthly) + ') was read and saved.'
                    : 'Monthly Payment read but saving failed.', !resp.success);
            })
            .catch(function () { setStatus('Monthly Payment read but saving failed.', true); });
    }

    // Find the number on the "Payable Amount" line of the OCR text.
    var PAYABLE_LABELS = [/pay\s*able\s*amount/i, /pay\s*able/i];
    // "Monthly Payment" is read separately and stored as the OLD ERP monthly
    // amount (cross-checked on the Student Accounts list).
    var MONTHLY_LABELS = [/monthly\s*pay\s*ment/i, /monthly\s*fees?/i];

    // Read the first number AFTER the label on the line, so other columns the
    // OCR merges into the same line (Total / Paid / Due amounts) are ignored
    // instead of accidentally picking the biggest number on the line.
    function amountAfterLabel(line, labelRe) {
        var m = labelRe.exec(line);
        if (!m) return null;
        var candidates = line.slice(m.index + m[0].length).match(/-?[\d,]+(?:\.\d+)?/g);
        if (!candidates) {
            // Rare OCR column swap: the amount sits before the label
            var before = line.slice(0, m.index).match(/-?[\d,]+(?:\.\d+)?/g);
            if (before) candidates = [before[before.length - 1]];
        }
        if (!candidates) return null;
        for (var i = 0; i < candidates.length; i++) {
            var v = parseFloat(candidates[i].replace(/,/g, ''));
            if (!isNaN(v) && v > 0) return v;
        }
        return null;
    }

    function parsePayable(text) {
        var lines = String(text).split(/\n/);
        for (var l = 0; l < PAYABLE_LABELS.length; l++) {
            for (var i = 0; i < lines.length; i++) {
                var val = amountAfterLabel(lines[i], PAYABLE_LABELS[l]);
                if (val !== null) return val;
            }
        }
        var m = String(text).match(/pay\s*able[^0-9\-]*(-?[\d,]+(?:\.\d+)?)/i);
        return m ? parseFloat(m[1].replace(/,/g, '')) : null;
    }

    function parseMonthly(text) {
        var lines = String(text).split(/\n/);
        for (var l = 0; l < MONTHLY_LABELS.length; l++) {
            for (var i = 0; i < lines.length; i++) {
                var val = amountAfterLabel(lines[i], MONTHLY_LABELS[l]);
                if (val !== null) return val;
            }
        }
        return null;
    }

    // "Registration Fee" row(s) in the proof's Student Ledger table:
    //   Head of A/C | Payable Amount | Payment Date | Receipt No | Received Amount [| Due Amount]
    // The table headers are identified FIRST and every number is mapped to its
    // field by column position, so Payment Dates and Receipt Nos are never
    // mistaken for amounts. Comma-separated monetary values are preserved
    // ("1,000" = 1000, "8,000" = 8000, "53,919" = 53919). Tolerant of common
    // OCR misreads: fuzzy label match (e.g. "Registralion Fee"), digit fixes
    // (O→0, l/I→1) and amounts wrapped onto the next line. Every reading is
    // validated against the visible row (Received ≤ Payable; when a Due column
    // exists, Due = Payable − Received ±5 BDT) — ambiguous rows are returned
    // as unread and flagged for manual verification instead of silently
    // producing an incorrect financial calculation.
    // Re-Registration / Convocation rows are excluded; multiple registration
    // rows (one per semester) are summed.
    var REG_LABEL = /\breg\S{0,12}?\s*fees?/i;

    // Ledger column headers (fuzzy, OCR-tolerant) used to detect the layout.
    var REG_HEADER_COLS = [
        { key: 'payable',  re: /pay\s*[ao]b[l1i]e/i },
        { key: 'date',     re: /pay\s*ment\s*da[tl]e/i },
        { key: 'receipt',  re: /rece[il1]pt\s*n/i },
        { key: 'received', re: /rece[il1]ved/i },
        { key: 'due',      re: /due\s*am/i }
    ];

    // Step 1 – identify the table headers: find the ledger header line and
    // return the recognised column keys in visual (left-to-right) order.
    function regHeaderOrder(lines) {
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            if (!/head\s*of/i.test(line)
                && !(/pay\s*[ao]b[l1i]e/i.test(line) && /rece[il1]/i.test(line))) continue;
            var found = [];
            for (var c = 0; c < REG_HEADER_COLS.length; c++) {
                var m = REG_HEADER_COLS[c].re.exec(line);
                if (m) found.push({ key: REG_HEADER_COLS[c].key, pos: m.index });
            }
            if (found.length >= 2) {
                found.sort(function (a, b) { return a.pos - b.pos; });
                var keys = [], seen = {};
                for (var k = 0; k < found.length; k++) {
                    if (!seen[found[k].key]) { seen[found[k].key] = true; keys.push(found[k].key); }
                }
                return keys;
            }
        }
        return null;
    }

    // Payment Dates (dd-mm-yyyy / dd.mm.yyyy / dd/mm/yyyy, after digit fixes)
    var REG_DATE = /^\d{1,2}[-.\/]\d{1,2}[-.\/]\d{2,4}$/;

    function regTokens(fragment) {
        // Hyphens and slashes stay INSIDE a token so a Payment Date remains
        // one token and is discarded whole – "20-09-2023" can never leak a
        // stray "20" into the amounts.
        var toks = String(fragment).match(/[0-9OolI|][0-9OolI|,.\/-]*/g) || [];
        var out = [];
        for (var i = 0; i < toks.length; i++) {
            if (!/[0-9]/.test(toks[i])) continue;            // must contain a real digit
            var t = toks[i].replace(/[Oo]/g, '0').replace(/[lI|]/g, '1').replace(/[,.\/-]+$/, '');
            if (REG_DATE.test(t)) { out.push({ kind: 'date', value: null }); continue; }
            if (/[\/-]/.test(t)) continue;                   // unreadable date-like fragment
            // Monetary formatting: comma-grouped ("1,000") or decimals (".00").
            // The comma is a thousands separator and is preserved correctly.
            var money = /\d,\d{3}(?:\D|$)/.test(t) || /\.\d{1,2}$/.test(t);
            var v = parseFloat(t.replace(/,/g, ''));
            if (isNaN(v) || v < 0) continue;
            out.push({ kind: money ? 'money' : 'int', value: v });
        }
        return out;
    }

    // Step 2 – map the row's numbers to Payable / Received by column position.
    // Returns null when the values cannot be validated against the row, so the
    // caller flags the account for manual entry instead of guessing.
    function pickRegPair(tokens, order) {
        var vals = [];
        for (var i = 0; i < tokens.length; i++) {
            if (tokens[i].kind !== 'date') vals.push(tokens[i]);   // Payment Dates dropped
        }
        if (vals.length < 2) return null;
        var nums = [];
        for (var n = 0; n < vals.length; n++) nums.push(vals[n].value);

        var payable, received, due = null;
        var recIdx = order ? order.indexOf('received') : -1;

        if (recIdx !== -1) {
            // Header-aware: Payable Amount is the FIRST amount and Received
            // Amount the LAST column amount (or second-to-last when a Due
            // column follows it). Receipt No tokens in between are ignored.
            payable = nums[0];
            var dueIdx = order.indexOf('due');
            if (dueIdx !== -1 && dueIdx > recIdx && nums.length >= 3) {
                received = nums[nums.length - 2];
                due      = nums[nums.length - 1];
            } else {
                received = nums[nums.length - 1];
            }
        } else {
            // No readable header: legacy Payable | Received | Due layout.
            for (var a = 0; a + 2 < nums.length; a++) {      // reconciled triple first
                if (Math.abs(nums[a] - nums[a + 1] - nums[a + 2]) <= 5) {
                    return { payable: nums[a], received: nums[a + 1] };
                }
            }
            payable  = nums[0];
            received = nums[nums.length - 1];
        }

        // Step 3 – validate against the visible row before using the values.
        if (payable !== undefined && received !== undefined
            && payable > 0 && received >= 0 && received <= payable
            && (due === null || Math.abs(payable - received - due) <= 5)) {
            return { payable: payable, received: received };
        }

        // Column mixup (e.g. a Receipt No read as an amount): retry with the
        // money-formatted tokens only ("1,000" / "8,000.00"), never bare ints.
        var money = [];
        for (var b = 0; b < vals.length; b++) {
            if (vals[b].kind === 'money') money.push(vals[b].value);
        }
        for (var c2 = 0; c2 + 2 < money.length; c2++) {
            if (Math.abs(money[c2] - money[c2 + 1] - money[c2 + 2]) <= 5) {
                return { payable: money[c2], received: money[c2 + 1] };
            }
        }
        if (money.length >= 2 && money[0] > 0 && money[0] >= money[money.length - 1]) {
            return { payable: money[0], received: money[money.length - 1] };
        }
        return null;   // ambiguous – flagged for manual verification
    }

    function parseRegRow(text) {
        var lines = String(text).split(/\n/);
        var order = regHeaderOrder(lines);   // identify the table headers first
        var payable = 0, received = 0, found = false;
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var m = REG_LABEL.exec(line);
            if (!m) continue;
            if (/re\s*-?\s*regis|convocation|replacement/i.test(line)) continue;
            var toks = regTokens(line.slice(m.index + m[0].length));
            var amountCount = 0;
            for (var t = 0; t < toks.length; t++) {
                if (toks[t].kind !== 'date') amountCount++;
            }
            if (amountCount < 2 && i + 1 < lines.length && !REG_LABEL.test(lines[i + 1])) {
                toks = toks.concat(regTokens(lines[i + 1]));   // amounts wrapped to the next line
            }
            var pick = pickRegPair(toks, order);
            if (!pick) continue;
            payable  += pick.payable;
            received += pick.received;
            found = true;
        }
        return found
            ? { payable: Math.round(payable * 100) / 100, received: Math.round(received * 100) / 100 }
            : null;
    }

    function loadTesseract(cb) {
        if (window.Tesseract) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
        s.onload = cb;
        s.onerror = function () { setStatus('Could not load the OCR library (CDN unreachable). Enter the amount manually.', true); };
        document.head.appendChild(s);
    }

    function runOcr() {
        var urls = (CFG.proofUrls && CFG.proofUrls.length) ? CFG.proofUrls : (CFG.proofUrl ? [CFG.proofUrl] : []);
        if (!urls.length) return;
        setStatus('Reading the OLD ERP proof…');
        loadTesseract(function () {
            var val = null, mval = null, reg = null, idx = 0;

            // Read EVERY proof image (the transaction history with the
            // Registration Fee row is often a separate screenshot) until
            // all three readings are found.
            function step() {
                if (idx >= urls.length || (val !== null && mval !== null && reg !== null)) {
                    finish();
                    return;
                }
                var url = urls[idx++];
                window.Tesseract.recognize(url, 'eng', {
                    logger: function (m) {
                        if (m.status === 'recognizing text') {
                            setStatus('Reading proof image ' + idx + ' of ' + urls.length + '… ' + Math.round(m.progress * 100) + '%');
                        }
                    }
                }).then(function (res) {
                    var text = (res && res.data && res.data.text) || '';
                    if (val  === null) val  = parsePayable(text);
                    if (mval === null) mval = parseMonthly(text);
                    if (reg  === null) reg  = parseRegRow(text);
                    step();
                }).catch(function () { step(); });
            }

            function finish() {
                if (val === null && mval === null && reg === null) {
                    setStatus('Could not read the proof image(s). Enter the amounts manually below.', true);
                    return;
                }
                if (val === null) {
                    // No Payable Amount found — save whatever was read
                    // (Monthly Payment and/or the Registration Fee row).
                    var fd = new FormData();
                    fd.append(CFG.csrfField, CFG.csrfToken);
                    fd.append('package_id', CFG.packageId);
                    if (mval !== null) fd.append('monthly', mval);
                    if (reg) {
                        fd.append('reg_payable',  reg.payable);
                        fd.append('reg_received', reg.received);
                    }
                    fd.append('source', 'ocr');
                    fetch(CFG.saveUrl, { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (resp) {
                            if (!resp.success) { setStatus('Values read but saving failed.', true); return; }
                            if (mval !== null) applyMonthlyResult(mval, 'Read automatically (OCR) · just now');
                            if (reg && !resp.reg_skipped) applyRegResult(reg.payable, reg.received, 'Read automatically (OCR) · just now');
                            setStatus('No "Payable Amount" found, but '
                                + (mval !== null ? 'the Monthly Payment' : '')
                                + (mval !== null && reg ? ' and ' : '')
                                + (reg ? 'the Registration Fee row' : '')
                                + ' was read and saved.');
                        })
                        .catch(function () { setStatus('Values read but saving failed.', true); });
                    return;
                }
                save(val, 'ocr', mval, reg, function (ok, resp) {
                    if (!ok) {
                        setStatus('OCR read ' + fmt(val) + ' but saving failed: ' + (resp.error || 'unknown error'), true);
                        applyResult(val, 'Read automatically (OCR) · not saved');
                        return;
                    }
                    if (mval !== null) applyMonthlyResult(mval, 'Read automatically (OCR) · just now');
                    if (reg !== null && !resp.reg_skipped) applyRegResult(reg.payable, reg.received, 'Read automatically (OCR) · just now');
                    if (resp.skipped) {
                        setStatus('OCR read ' + fmt(val) + ', but a manually entered value is kept (' + fmt(resp.amount) + ').');
                        return;
                    }
                    applyResult(val, 'Read automatically (OCR) · just now');
                    setStatus(reg
                        ? 'Payable Amount and the Registration Fee row were read from the proof and saved.'
                        : 'Payable Amount read and saved — but NO Registration Fee row could be read from the proof image(s). Enter the registration received amount manually below if the proof shows one.', !reg);
                });
            }

            step();
        });
    }

    var runBtn = $id('erp-run-ocr');
    if (runBtn) runBtn.addEventListener('click', runOcr);

    var manualBtn = $id('erp-manual-save');
    if (manualBtn) {
        manualBtn.addEventListener('click', function () {
            var input = $id('erp-manual-amount');
            var v = parseFloat(input.value);
            if (isNaN(v) || v < 0) { setStatus('Enter a valid payable amount first.', true); return; }
            save(v, 'manual', null, null, function (ok, resp) {
                if (!ok) { setStatus('Saving failed: ' + (resp.error || 'unknown error'), true); return; }
                setStatus('Manual payable amount saved.');
                applyResult(v, 'Entered manually · just now');
                input.value = '';
            });
        });
    }

    var manualMonthlyBtn = $id('erp-manual-monthly-save');
    if (manualMonthlyBtn) {
        manualMonthlyBtn.addEventListener('click', function () {
            var input = $id('erp-manual-monthly');
            var v = parseFloat(input.value);
            if (isNaN(v) || v < 0) { setStatus('Enter a valid monthly payment first.', true); return; }
            var fd = new FormData();
            fd.append(CFG.csrfField, CFG.csrfToken);
            fd.append('package_id', CFG.packageId);
            fd.append('monthly', v);
            fd.append('source', 'manual');
            fetch(CFG.saveUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (!resp.success) { setStatus('Saving failed: ' + (resp.error || 'unknown error'), true); return; }
                    setStatus('Manual monthly payment saved.');
                    applyMonthlyResult(v, 'Entered manually · just now');
                    input.value = '';
                })
                .catch(function (e) { setStatus('Saving failed: ' + e, true); });
        });
    }

    var manualRegBtn = $id('erp-manual-reg-save');
    if (manualRegBtn) {
        manualRegBtn.addEventListener('click', function () {
            var input = $id('erp-manual-reg');
            var v = parseFloat(input.value);
            if (isNaN(v) || v < 0) { setStatus('Enter a valid registration received amount first.', true); return; }
            var fd = new FormData();
            fd.append(CFG.csrfField, CFG.csrfToken);
            fd.append('package_id', CFG.packageId);
            fd.append('reg_received', v);
            fd.append('source', 'manual');
            fetch(CFG.saveUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    if (!resp.success) { setStatus('Saving failed: ' + (resp.error || 'unknown error'), true); return; }
                    setStatus('Manual registration received amount saved — the Totals Merge will mark only this much registration as paid.');
                    applyRegResult(null, v, 'Entered manually · just now');
                    input.value = '';
                })
                .catch(function (e) { setStatus('Saving failed: ' + e, true); });
        });
    }

    // Auto-check: run when the Payable Amount OR the Registration Fee reading
    // is still missing (backfills accounts checked before the registration
    // reading existed).
    if ((CFG.proofUrl || (CFG.proofUrls && CFG.proofUrls.length))
        && (CFG.stored === null || CFG.regStored === null)) {
        runOcr();
    }
})();
</script>

<!-- ══════════════════════════════════════════════════════════
     PENDING VC APPROVAL SCHOLARSHIPS
═══════════════════════════════════════════════════════════ -->
<?php if (!empty($pending_vc_approvals)): ?>
<div class="card mt-4 border-warning">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2 bg-warning bg-opacity-10">
        <h6 class="mb-0 fw-semibold text-warning-emphasis">
            <i class="fas fa-clock me-2"></i>Pending VC Scholarship Approvals
            <span class="badge bg-warning text-dark ms-2" style="font-size:.7rem;"><?= count($pending_vc_approvals) ?> pending</span>
        </h6>
        <?php if (can_access('vc-approval')): ?>
        <a href="<?= APP_URL ?>/vc-approval/index.php?tab=pending" class="btn btn-warning btn-sm" style="font-size:.8rem;">
            <i class="fas fa-user-check me-1"></i>Review in VC Approval
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body px-4 py-3">
        <div class="alert alert-warning mb-3 py-2" style="font-size:.85rem;">
            <i class="fas fa-info-circle me-1"></i>
            The scholarships below are <strong>awaiting Vice Chancellor approval</strong>.
            Estimated deduction amounts are calculated and reflected in the displayed totals above. Final deductions are applied after VC approval.
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.85rem;">
                <thead class="table-light">
                    <tr>
                        <th>Label</th>
                        <th>Discount</th>
                        <th>Scope</th>
                        <th class="text-end">Estimated Deduction</th>
                        <th>Note</th>
                        <th>Document</th>
                        <th>Requested By</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending_vc_approvals as $pva): ?>
                <tr>
                    <td class="fw-semibold"><?= h($pva['label']) ?></td>
                    <td>
                        <?php if ($pva['discount_type'] === 'fixed'): ?>
                            <span class="text-danger">BDT <?= number_format((float)$pva['fixed_amount'], 2) ?></span>
                        <?php else: ?>
                            <span class="text-danger"><?= number_format((float)$pva['discount_pct'], 2) ?>%</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($pva['apply_to_all']): ?>
                            <span class="badge bg-primary">All Semesters</span>
                        <?php else: ?>
                            Sem #<?= (int)$pva['semester_number'] ?>
                            <?php if ($pva['semester_label']): ?>
                            <span class="text-muted" style="font-size:.75rem;"> – <?= h($pva['semester_label']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($pva['applies_to_fixed']): ?>
                        <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;">+Fixed</span>
                        <?php endif; ?>
                        <?php if ($pva['applies_to_english']): ?>
                        <span class="badge bg-info text-dark ms-1" style="font-size:.6rem;">+ENG</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-danger fw-semibold">
                        <?php
                        $pending_row_id = (int)$pva['id'];
                        $pending_estimated = (float)($pending_projection_by_request[$pending_row_id]['total'] ?? 0.0);
                        ?>
                        <?= $pending_estimated > 0 ? ('− ' . number_format($pending_estimated, 2)) : '—' ?>
                    </td>
                    <td class="text-muted"><?= $pva['sc_note'] ? h($pva['sc_note']) : '—' ?></td>
                    <td>
                        <?php if ($pva['doc_stored_name']): ?>
                        <a href="<?= UPLOAD_URL ?>/students/files/<?= rawurlencode($pva['doc_stored_name']) ?>"
                           target="_blank" class="text-secondary" title="<?= h($pva['doc_original_name'] ?? '') ?>">
                            <i class="fas fa-paperclip"></i>
                        </a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= h($pva['requested_by_name']) ?></td>
                    <td><?= date('d M Y', strtotime($pva['created_at'])) ?></td>
                    <td><?= vca_status_badge($pva['status']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     MONTHLY BREAKDOWN – SEMESTER 1 (ACTIVE SEMESTER)
═══════════════════════════════════════════════════════════ -->
<?php
$first_sem         = !empty($semester_fees) ? $semester_fees[0] : null;
$months_per_sem    = (float)$pkg['months_per_semester'];
$num_months        = ($months_per_sem > 0) ? (int)round($months_per_sem) : 0;
$monthly_tuition   = ($num_months > 0 && $first_sem) ? ((float)$first_sem['tuition_payable'] / $months_per_sem) : 0.0;
$monthly_fixed     = (float)$pkg['monthly_fixed_fee'];
$monthly_english   = (float)$pkg['monthly_english_fee'];
$monthly_total     = $monthly_tuition + $monthly_fixed + $monthly_english;
$first_sem_label   = ($first_sem && $first_sem['semester_label']) ? $first_sem['semester_label'] : 'Semester 1';

// ── Semester drop (deferral) schedule context ───────────────────────────────
// Dropped months are not erased – they are deferred, pushing later obligations
// forward and extending the programme end. Build a shift-aware schedule for the
// active semester (obligation rows interleaved with "Semester Drop" placeholders)
// and compute the extended programme end date.
$sd_dropped_total = ($sd_student_id > 0 && function_exists('sd_dropped_months_count'))
    ? sd_dropped_months_count($sd_student_id) : 0;

$breakdown_rows = ($num_months > 0 && $first_sem && function_exists('sd_build_schedule') && $sd_student_id > 0)
    ? sd_build_schedule($sd_student_id, $start_month, $sd_start_year, $num_months, 0)
    : [];
if (empty($breakdown_rows) && $num_months > 0 && $first_sem) {
    for ($m = 1; $m <= $num_months; $m++) {
        $mi = acc_month_year_for_slot($start_month, $sd_start_year, $m - 1);
        $breakdown_rows[] = [
            'type' => 'obligation', 'slot' => $m - 1,
            'month' => (int)$mi['month'], 'year' => (int)$mi['year'], 'label' => (string)$mi['label'],
        ];
    }
}

// Extended programme end (last obligation month of the whole programme, shifted).
$total_program_months = $num_months * max(1, count($semester_fees));
$prog_end_info = null;
if ($total_program_months > 0) {
    $prog_end_info = ($sd_student_id > 0 && function_exists('sd_shifted_slot_calendar'))
        ? sd_shifted_slot_calendar($sd_student_id, $start_month, $sd_start_year, $total_program_months - 1)
        : acc_month_year_for_slot($start_month, $sd_start_year, $total_program_months - 1);
}
?>
<div class="card mt-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-calendar-alt me-2 text-muted"></i>Month-wise Breakdown
            <span class="text-muted fw-normal">– <?= h($first_sem_label) ?> <span class="badge bg-success ms-1" style="font-size:.65rem;">Active Semester</span></span>
        </h6>
        <span class="badge bg-secondary"><?= $num_months ?> months</span>
    </div>
    <div class="card-body p-0">
        <?php if (!$first_sem || $num_months < 1): ?>
        <p class="text-muted px-4 py-3 mb-0">No semester data available.</p>
        <?php else: ?>
        <?php if ($sd_dropped_total > 0 && $prog_end_info): ?>
        <div class="alert alert-warning rounded-0 mb-0 py-2 px-4" style="font-size:.8rem;">
            <i class="fas fa-pause-circle me-1"></i>
            <strong>Semester drop in effect:</strong>
            <?= (int)$sd_dropped_total ?> month<?= $sd_dropped_total === 1 ? '' : 's' ?> deferred.
            Dropped months are not waived — the tuition is pushed to the end, so the
            programme is extended to <strong><?= h($prog_end_info['label']) ?></strong>.
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.875rem;">
                <thead>
                    <tr>
                        <th style="width:45px;">#</th>
                        <th>Month</th>
                        <th class="text-end">Tuition Payable</th>
                        <th class="text-end">Fixed Fees</th>
                        <th class="text-end">English Fee</th>
                        <th class="text-end fw-bold">Monthly Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($breakdown_rows as $brow): ?>
                <?php if (($brow['type'] ?? '') === 'drop'): ?>
                <tr class="table-warning">
                    <td class="fw-semibold text-muted">—</td>
                    <td>
                        <?= h($brow['label']) ?>
                        <span class="badge bg-warning text-dark ms-1"><i class="fas fa-pause me-1"></i>Semester Drop</span>
                    </td>
                    <td class="text-end text-muted">—</td>
                    <td class="text-end text-muted">—</td>
                    <td class="text-end text-muted">—</td>
                    <td class="text-end fw-bold text-muted">Not due</td>
                </tr>
                <?php else: ?>
                <?php
                    $slot       = (int)($brow['slot'] ?? 0);
                    $disp_no    = $slot + 1; // obligation month number within semester 1
                    $month_name = sfp_get_month_name($disp_no, $start_month);
                ?>
                <tr>
                    <td class="fw-semibold text-muted"><?= $disp_no ?></td>
                    <td>
                        Month <?= $disp_no ?><?= $month_name ? ' (' . h($month_name) . ')' : '' ?>
                        <span class="text-muted ms-1" style="font-size:.72rem;">· <?= h($brow['label']) ?></span>
                    </td>
                    <td class="text-end"><?= sfp_money($monthly_tuition) ?></td>
                    <td class="text-end"><?= sfp_money($monthly_fixed) ?></td>
                    <td class="text-end"><?= sfp_money($monthly_english) ?></td>
                    <td class="text-end fw-bold text-success"><?= sfp_money($monthly_total) ?></td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="2" class="text-end">Semester 1 Total →</td>
                        <td class="text-end"><?= sfp_money($monthly_tuition * $num_months) ?></td>
                        <td class="text-end"><?= sfp_money($monthly_fixed * $num_months) ?></td>
                        <td class="text-end"><?= sfp_money($monthly_english * $num_months) ?></td>
                        <td class="text-end text-success fs-6"><?= sfp_money($monthly_total * $num_months) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="px-4 py-2 text-muted" style="font-size:.75rem;">
            <i class="fas fa-info-circle me-1"></i>
            Monthly amounts are derived by dividing the semester fees over <?= $num_months ?> months
            (<?= number_format($months_per_sem, 2) ?> months/semester).
            Fixed &amp; English fees are programme-wide constants spread equally per month.
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     ADD SCHOLARSHIP MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="addScModal" tabindex="-1" aria-labelledby="addScModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= APP_URL ?>/student-accounts/add-scholarship.php"
              enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="package_id" value="<?= $id ?>">
            <input type="hidden" name="sf_id" id="asc-sf-id" value="">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="addScModalLabel">
                        <i class="fas fa-graduation-cap me-2"></i>Add Scholarship
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3 text-muted small" id="asc-sem-info"></p>

                    <?php if (!empty($sc_policies)): ?>
                    <!-- Quick-fill from scholarship policy -->
                    <div class="mb-3 p-3 bg-light rounded border">
                        <div class="fw-semibold small mb-2 text-secondary">
                            <i class="fas fa-magic me-1"></i>Quick-fill from Policy
                            <span class="text-muted fw-normal">(optional)</span>
                        </div>
                        <select id="asc-policy-select" class="form-select form-select-sm mb-2">
                            <option value="">— Choose a scholarship policy —</option>
                            <?php foreach ($sc_policies as $spol): ?>
                            <option value="<?= $spol['id'] ?>"
                                    data-name="<?= h($spol['name']) ?>"
                                    data-tiers="<?= h(json_encode($spol['tiers'])) ?>"
                                    data-applies-to-fixed="<?= (int)($spol['applies_to_fixed'] ?? 0) ?>"
                                    data-applies-to-english="<?= (int)($spol['applies_to_english'] ?? 0) ?>">
                                <?= h($spol['name']) ?>
                                (<?= $spol['type'] === 'gpa_based' ? 'GPA-Based' : ($spol['type'] === 'flat' ? 'Flat Discount' : 'Merit-Based') ?>)
                                <?php if (!empty($spol['tiers'])): ?>
                                – <?= count($spol['tiers']) ?> tier<?= count($spol['tiers']) !== 1 ? 's' : '' ?>
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="d-none" id="asc-tier-wrap">
                            <select id="asc-tier-select" class="form-select form-select-sm">
                                <option value="">— Select a tier —</option>
                            </select>
                            <div class="form-text mt-1" id="asc-tier-info"></div>
                        </div>
                    </div>
                    <hr class="my-2">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tuition Fee (this semester)</label>
                        <input type="text" id="asc-tuition-display" class="form-control bg-light" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Scholarship Type / Label <span class="text-danger">*</span></label>
                        <input type="text" name="sc_label" id="asc-label" class="form-control"
                               placeholder="e.g. Initial Waiver, Sports Scholarship, Freedom Fighter" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Scholarship Type <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="discount_type" value="percentage"
                                       id="asc-type-pct" checked>
                                <label class="form-check-label" for="asc-type-pct">
                                    <i class="fas fa-percent me-1 text-secondary"></i>Percentage
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="discount_type" value="fixed"
                                       id="asc-type-fixed">
                                <label class="form-check-label" for="asc-type-fixed">
                                    <i class="fas fa-money-bill-wave me-1 text-secondary"></i>Fixed Amount
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3" id="asc-pct-wrap">
                        <label class="form-label fw-semibold">Discount % <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="discount_pct" id="asc-pct"
                                   class="form-control" step="0.0001" min="0.0001" max="100">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>

                    <div class="mb-3 d-none" id="asc-fixed-wrap">
                        <label class="form-label fw-semibold">Fixed Scholarship Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">BDT</span>
                            <input type="number" name="fixed_amount" id="asc-fixed-amount"
                                   class="form-control" step="0.01" min="0.01" placeholder="e.g. 5000">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Scholarship Amount (auto-calculated)</label>
                        <div class="input-group">
                            <input type="text" id="asc-amount" class="form-control bg-light" readonly>
                            <span class="input-group-text">BDT</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Note</label>
                        <textarea name="sc_note" id="asc-note" class="form-control" rows="2"
                                  placeholder="Optional note about this scholarship"></textarea>
                    </div>

                    <!-- Fee scope: which fee types this discount covers (only for percentage type) -->
                    <div class="mb-3" id="asc-scope-wrap">
                        <label class="form-label fw-semibold small">Also apply discount to:</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="applies_to_fixed" value="1"
                                       id="asc-applies-fixed">
                                <label class="form-check-label small" for="asc-applies-fixed">
                                    Fixed Institutional Fees
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="applies_to_english" value="1"
                                       id="asc-applies-english">
                                <label class="form-check-label small" for="asc-applies-english">
                                    English Course Fee
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Supporting document (required for non-policy / manual scholarships) -->
                    <div class="mb-3" id="asc-doc-wrap">
                        <label class="form-label fw-semibold">
                            Supporting Document <span class="text-danger">*</span>
                        </label>                        <input type="file" name="support_doc" id="asc-support-doc" class="form-control"
                               accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt">
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Required for non-policy scholarships. Max 20 MB.
                            Allowed: images, PDF, Word, Excel, PPT, ZIP, TXT.
                        </div>
                    </div>
                    <input type="hidden" name="is_from_policy" id="asc-is-from-policy" value="0">

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="apply_to_all" value="1"
                               id="asc-apply-all">
                        <label class="form-check-label small" for="asc-apply-all">
                            Apply this scholarship to <strong>all semesters</strong> in the package
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning text-dark">
                        <i class="fas fa-plus me-1"></i> Add Scholarship
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     EDIT TUITION MODAL
     (for semesters after the initial fixed period)
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="editTuitionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <form method="post" action="<?= APP_URL ?>/student-accounts/update-tuition.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="package_id" value="<?= $id ?>">
            <input type="hidden" name="sf_id" id="et-sf-id" value="">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size:.95rem;" id="et-title">Edit Tuition Fee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3" id="et-info"></p>
                    <label class="form-label fw-semibold small">Tuition Fee (BDT)</label>
                    <input type="number" name="tuition_fee" id="et-tuition" class="form-control"
                           min="0" step="0.01" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SET SEMESTER LABEL MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="setLabelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <form method="post" action="<?= APP_URL ?>/student-accounts/set-semester-label.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="package_id" value="<?= $id ?>">
            <input type="hidden" name="sf_id" id="lbl-sf-id" value="">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" style="font-size:.95rem;">Set Semester Label</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold small">Label (e.g. Summer 2026)</label>
                    <input type="text" name="semester_label" id="lbl-input" class="form-control"
                           placeholder="Summer 2026">
                    <div class="form-check mt-3" id="lbl-auto-fill-wrap" style="display:none;">
                        <input class="form-check-input" type="checkbox" name="auto_fill" value="1" id="lbl-auto-fill">
                        <label class="form-check-label small" for="lbl-auto-fill">
                            Auto-fill remaining semesters based on student's semester type
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// ── Add Scholarship modal ─────────────────────────────────────────────────────
document.querySelectorAll('.add-sc-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var sfId    = this.dataset.sfId;
        var semLbl  = this.dataset.semLabel || ('Semester ' + this.dataset.semNum);
        var tuition = parseFloat(this.dataset.tuition) || 0;

        document.getElementById('asc-sf-id').value          = sfId;
        document.getElementById('asc-sem-info').textContent = 'Adding to: ' + semLbl;
        document.getElementById('asc-tuition-display').value =
            tuition.toLocaleString('en-BD', {minimumFractionDigits:2});
        document.getElementById('asc-pct').value         = '';
        document.getElementById('asc-label').value        = '';
        document.getElementById('asc-note').value         = '';
        document.getElementById('asc-amount').value       = '0.00';
        var fixedAmtInput = document.getElementById('asc-fixed-amount');
        if (fixedAmtInput) fixedAmtInput.value = '';

        // Reset scholarship type to percentage
        var typePct   = document.getElementById('asc-type-pct');
        var typeFixed = document.getElementById('asc-type-fixed');
        if (typePct)   typePct.checked   = true;
        if (typeFixed) typeFixed.checked = false;
        ascSwitchType('percentage');

        // Reset policy/tier selectors
        var polSel = document.getElementById('asc-policy-select');
        if (polSel) {
            polSel.value = '';
            ascResetTierWrap();
        }
        ascUpdateDocField(); // show doc upload by default (no policy selected)
        var applyAll = document.getElementById('asc-apply-all');
        if (applyAll) applyAll.checked = false;

        var modal = new bootstrap.Modal(document.getElementById('addScModal'));
        modal.show();
        setTimeout(function(){ document.getElementById('asc-label').focus(); }, 400);
    });
});

// ── Scholarship type switch (Percentage / Fixed Amount) ────────────────────────
function ascSwitchType(type) {
    var pctWrap    = document.getElementById('asc-pct-wrap');
    var fixedWrap  = document.getElementById('asc-fixed-wrap');
    var scopeWrap  = document.getElementById('asc-scope-wrap');
    var pctInput   = document.getElementById('asc-pct');
    var fixedInput = document.getElementById('asc-fixed-amount');
    var fixedCb    = document.getElementById('asc-applies-fixed');
    var engCb      = document.getElementById('asc-applies-english');

    if (type === 'fixed') {
        if (pctWrap)   pctWrap.classList.add('d-none');
        if (fixedWrap) fixedWrap.classList.remove('d-none');
        if (scopeWrap) scopeWrap.classList.add('d-none');
        if (pctInput)  { pctInput.removeAttribute('required'); pctInput.value = ''; }
        if (fixedInput) fixedInput.setAttribute('required', 'required');
        if (fixedCb)   fixedCb.checked = false;
        if (engCb)     engCb.checked   = false;
    } else {
        if (pctWrap)   pctWrap.classList.remove('d-none');
        if (fixedWrap) fixedWrap.classList.add('d-none');
        if (scopeWrap) scopeWrap.classList.remove('d-none');
        if (pctInput)  pctInput.setAttribute('required', 'required');
        if (fixedInput) { fixedInput.removeAttribute('required'); fixedInput.value = ''; }
    }
    ascRecalcAmount();
}

document.querySelectorAll('input[name="discount_type"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        ascSwitchType(this.value);
    });
});

// ── Doc field visibility: required only when no policy is selected ────────────
function ascUpdateDocField() {
    var polSel   = document.getElementById('asc-policy-select');
    var docWrap  = document.getElementById('asc-doc-wrap');
    var docInput = document.getElementById('asc-support-doc');
    var fromPol  = document.getElementById('asc-is-from-policy');
    if (!docWrap || !docInput) return;

    var policyChosen = polSel && polSel.value !== '';
    if (policyChosen) {
        docWrap.classList.add('d-none');
        docInput.removeAttribute('required');
        if (fromPol) fromPol.value = '1';
    } else {
        docWrap.classList.remove('d-none');
        docInput.setAttribute('required', 'required');
        if (fromPol) fromPol.value = '0';
    }
}

// ── Policy / tier quick-fill ──────────────────────────────────────────────────
function ascResetTierWrap() {
    var tierWrap = document.getElementById('asc-tier-wrap');
    if (!tierWrap) return;
    tierWrap.classList.add('d-none');
    var tierSel = document.getElementById('asc-tier-select');
    tierSel.innerHTML = '<option value="">— Select a tier —</option>';
    document.getElementById('asc-tier-info').textContent = '';
}

function ascRecalcAmount() {
    var tuition = parseFloat(
        document.getElementById('asc-tuition-display').value.replace(/,/g, '')
    ) || 0;

    var typeFixed = document.getElementById('asc-type-fixed');
    if (typeFixed && typeFixed.checked) {
        var fixedVal = parseFloat(document.getElementById('asc-fixed-amount').value) || 0;
        document.getElementById('asc-amount').value =
            Math.min(fixedVal, tuition).toLocaleString('en-BD', {minimumFractionDigits:2});
    } else {
        var pct = parseFloat(document.getElementById('asc-pct').value) || 0;
        document.getElementById('asc-amount').value =
            (tuition * pct / 100).toLocaleString('en-BD', {minimumFractionDigits:2});
    }
}

var ascPolicySel = document.getElementById('asc-policy-select');
if (ascPolicySel) {
    ascPolicySel.addEventListener('change', function() {
        ascResetTierWrap();
        ascUpdateDocField();
        var opt = this.options[this.selectedIndex];
        if (!this.value) return;

        // Pre-check applies_to_fixed / applies_to_english from policy flags
        var fixedCb = document.getElementById('asc-applies-fixed');
        var engCb   = document.getElementById('asc-applies-english');
        if (fixedCb) fixedCb.checked = opt.dataset.appliesToFixed === '1';
        if (engCb)   engCb.checked   = opt.dataset.appliesToEnglish === '1';

        var tiers = [];
        try { tiers = JSON.parse(opt.dataset.tiers || '[]'); } catch(e) {}
        var polName = opt.dataset.name || '';

        if (tiers.length > 0) {
            // Show tier dropdown
            var tierWrap = document.getElementById('asc-tier-wrap');
            tierWrap.classList.remove('d-none');
            var tierSel = document.getElementById('asc-tier-select');
            tiers.forEach(function(t) {
                var lbl = t.label || ('GPA ' + t.min_gpa + '–' + t.max_gpa);
                var opt2 = document.createElement('option');
                opt2.value = t.id;
                opt2.textContent = lbl + ' (' + parseFloat(t.discount_percent).toFixed(4) + '%)';
                opt2.dataset.label   = lbl;
                opt2.dataset.polName = polName;
                opt2.dataset.pct     = t.discount_percent;
                opt2.dataset.minGpa  = t.min_gpa;
                opt2.dataset.maxGpa  = t.max_gpa;
                tierSel.appendChild(opt2);
            });
        } else {
            // No tiers – fill label with policy name only; leave discount empty
            document.getElementById('asc-label').value = polName;
            document.getElementById('asc-pct').value   = '';
            document.getElementById('asc-amount').value = '0.00';
        }
    });
}

var ascTierSel = document.getElementById('asc-tier-select');
if (ascTierSel) {
    ascTierSel.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        if (!this.value) return;
        var pct  = parseFloat(opt.dataset.pct) || 0;
        var lbl  = opt.dataset.label || '';
        var pol  = opt.dataset.polName || '';
        var info = 'GPA range: ' + opt.dataset.minGpa + ' – ' + opt.dataset.maxGpa;
        document.getElementById('asc-label').value = pol + (lbl ? ' – ' + lbl : '');
        document.getElementById('asc-pct').value   = pct.toFixed(4);
        document.getElementById('asc-tier-info').textContent = info;
        ascRecalcAmount();
    });
}

document.getElementById('asc-pct').addEventListener('input', ascRecalcAmount);
var ascFixedInput = document.getElementById('asc-fixed-amount');
if (ascFixedInput) ascFixedInput.addEventListener('input', ascRecalcAmount);

// ── Edit Tuition modal ────────────────────────────────────────────────────────
document.querySelectorAll('.edit-tuition-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var sfId    = this.dataset.sfId;
        var semNum  = this.dataset.semNum;
        var tuition = this.dataset.tuition;

        document.getElementById('et-sf-id').value    = sfId;
        document.getElementById('et-title').textContent = 'Edit Tuition – Semester #' + semNum;
        document.getElementById('et-info').textContent  =
            'Update the tuition fee for semester #' + semNum + '. Scholarship amounts will be recalculated.';
        document.getElementById('et-tuition').value  = tuition;

        var modal = new bootstrap.Modal(document.getElementById('editTuitionModal'));
        modal.show();
        setTimeout(function(){ document.getElementById('et-tuition').focus(); }, 400);
    });
});

// ── Set semester label modal ──────────────────────────────────────────────────
document.querySelectorAll('.set-label-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var semNum = parseInt(this.dataset.semNum) || 0;
        document.getElementById('lbl-sf-id').value = this.dataset.sfId;
        document.getElementById('lbl-input').value = this.dataset.current;
        
        // Show auto-fill checkbox only for semester 1
        var autoFillWrap = document.getElementById('lbl-auto-fill-wrap');
        var autoFillCheck = document.getElementById('lbl-auto-fill');
        if (autoFillWrap) {
            if (semNum === 1) {
                autoFillWrap.style.display = 'block';
                if (autoFillCheck) autoFillCheck.checked = true; // default checked
            } else {
                autoFillWrap.style.display = 'none';
                if (autoFillCheck) autoFillCheck.checked = false;
            }
        }
        
        var modal = new bootstrap.Modal(document.getElementById('setLabelModal'));
        modal.show();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
