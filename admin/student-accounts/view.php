<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts');
require_once __DIR__ . '/helpers.php';

$id  = (int)($_GET['id'] ?? 0);
$pkg = sfp_get_package($id);

if (!$pkg) {
    flash_set('error', 'Student account not found.');
    redirect(APP_URL . '/student-accounts/index.php');
}

$page_title    = 'Student Account – ' . $pkg['student_name'];
$semester_fees = sfp_get_semester_fees($id);

// Active scholarship policies (with tiers) for the Add Scholarship modal
$sc_policies = sfp_get_active_sc_policies_with_tiers();

// All individual scholarships for this package, keyed by sf_id
$all_scholarships = sfp_get_all_semester_scholarships($id);

// Per-semester fixed / English portions (for display in semester table)
$sem_fixed_portion   = sfp_semester_fixed_portion($pkg);
$sem_english_portion = sfp_semester_english_portion($pkg);

// Registration fee per semester and form/ID-card fee (from cf_settings)
$cf_settings         = db()->query('SELECT reg_fee_per_semester, form_id_fee, start_month FROM cf_settings WHERE id = 1')->fetch();
$reg_fee_per_sem     = $cf_settings ? (float)$cf_settings['reg_fee_per_semester'] : 0.0;
$form_id_fee         = $cf_settings ? (float)$cf_settings['form_id_fee']           : 0.0;
$start_month         = $cf_settings ? (int)$cf_settings['start_month']             : 1;

// Semester 1 reg fee is now shown in the registration column together with all other semesters.
$total_reg_fees      = $reg_fee_per_sem * count($semester_fees);

// Admission Day Payment = base admission fee only (reg fee and form/ID card fee are counted separately)
$admission_fee       = (float)$pkg['admission_fees'];

// Totals
$total_tuition_payable   = 0.0;
$total_fixed_discounts   = 0.0;
$total_english_discounts = 0.0;
foreach ($semester_fees as $sf) {
    $total_tuition_payable   += (float)$sf['tuition_payable'];
    $total_fixed_discounts   += (float)($sf['fixed_discount_amount']   ?? 0);
    $total_english_discounts += (float)($sf['english_discount_amount'] ?? 0);
}
$total_fixed_all   = max(0.0, (float)$pkg['fixed_institutional_fees'] - $total_fixed_discounts);
$total_english_all = max(0.0, (float)$pkg['english_course_fee']       - $total_english_discounts);
$total_cost = $total_tuition_payable + $total_fixed_all + $total_english_all + $total_reg_fees + $admission_fee;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">
            <i class="fas fa-file-invoice-dollar me-2 text-success"></i>
            Student Account – <?= h($pkg['student_name']) ?>
        </h1>
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

<!-- ══════════════════════════════════════════════════════════
     PACKAGE SUMMARY CARDS
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
                $constants = [
                    'Programme'                  => $pkg['program_name'],
                    'Total Semesters'            => $pkg['total_semesters'],
                    'Total Months'               => $pkg['total_months'],
                    'Months / Semester'          => number_format((float)$pkg['months_per_semester'], 2),
                    'Standard Tuition (Full)'    => sfp_money((float)$pkg['standard_tuition_full']),
                    'Base Tuition / Semester'    => sfp_money((float)$pkg['tuition_per_semester']),
                    'Admission Fee (one-time)'        => sfp_money((float)$pkg['admission_fees']),
                    'Fixed Institutional Fees'   => sfp_money((float)$pkg['fixed_institutional_fees']),
                    'English Course Fee'         => sfp_money((float)$pkg['english_course_fee']),
                ];
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
                    $tuition_payable = (float)$sf['tuition_payable'];
                    $fixed_amt       = max(0.0, $sem_fixed_portion  - (float)($sf['fixed_discount_amount']   ?? 0));
                    $english_amt     = max(0.0, $sem_english_portion - (float)($sf['english_discount_amount'] ?? 0));
                    // Registration fee is shown for all semesters
                    $sem_reg         = $reg_fee_per_sem;
                    $total_sem       = $tuition_payable + $fixed_amt + $english_amt + $sem_reg;
                    $grand_tuition_payable += $tuition_payable;
                    $grand_fixed           += $fixed_amt;
                    $grand_english         += $english_amt;
                    $grand_total           += $total_sem;
                    $sem_scholarships = $all_scholarships[$sf_id_row] ?? [];
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
                                <?= h($sc['label']) ?>&nbsp;(<?= number_format((float)$sc['discount_pct'], 1) ?>%)
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
                        <span class="text-muted">—</span>
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
                    <tr class="table-success">
                        <td colspan="8" class="text-end fw-bold">Grand Total (incl. Admission Fee) →</td>
                        <td class="text-end fw-bold text-success fs-5"><?= sfp_money($grand_total + $admission_fee) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

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
                <?php for ($m = 1; $m <= $num_months; $m++): ?>
                <?php $month_name = sfp_get_month_name($m, $start_month); ?>
                <tr>
                    <td class="fw-semibold text-muted"><?= $m ?></td>
                    <td>Month <?= $m ?><?= $month_name ? ' (' . h($month_name) . ')' : '' ?></td>
                    <td class="text-end"><?= sfp_money($monthly_tuition) ?></td>
                    <td class="text-end"><?= sfp_money($monthly_fixed) ?></td>
                    <td class="text-end"><?= sfp_money($monthly_english) ?></td>
                    <td class="text-end fw-bold text-success"><?= sfp_money($monthly_total) ?></td>
                </tr>
                <?php endfor; ?>
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
                        <label class="form-label fw-semibold">Discount % <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="discount_pct" id="asc-pct"
                                   class="form-control" step="0.0001" min="0.0001" max="100" required>
                            <span class="input-group-text">%</span>
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

                    <!-- Fee scope: which fee types this discount covers -->
                    <div class="mb-3">
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
        document.getElementById('asc-pct').value   = '';
        document.getElementById('asc-label').value  = '';
        document.getElementById('asc-note').value   = '';
        document.getElementById('asc-amount').value = '0.00';

        // Reset new fields
        var fixedCb   = document.getElementById('asc-applies-fixed');
        var engCb     = document.getElementById('asc-applies-english');
        var docInput  = document.getElementById('asc-support-doc');
        if (fixedCb)  fixedCb.checked  = false;
        if (engCb)    engCb.checked    = false;
        if (docInput) docInput.value   = '';

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
    var pct = parseFloat(document.getElementById('asc-pct').value) || 0;
    document.getElementById('asc-amount').value =
        (tuition * pct / 100).toLocaleString('en-BD', {minimumFractionDigits:2});
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
