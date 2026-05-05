<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-fee-package');
require_once __DIR__ . '/helpers.php';

$id  = (int)($_GET['id'] ?? 0);
$pkg = sfp_get_package($id);

if (!$pkg) {
    flash_set('error', 'Fee package not found.');
    redirect(APP_URL . '/student-fee-package/index.php');
}

$page_title    = 'Fee Package – ' . $pkg['student_name'];
$semester_fees = sfp_get_semester_fees($id);

// Per-semester fixed / English portions
$sem_fixed_portion   = sfp_semester_fixed_portion($pkg);
$sem_english_portion = sfp_semester_english_portion($pkg);

// Totals
$total_tuition_payable = 0.0;
$total_fixed_all       = (float)$pkg['fixed_institutional_fees'];
$total_english_all     = (float)$pkg['english_course_fee'];
foreach ($semester_fees as $sf) {
    $total_tuition_payable += (float)$sf['tuition_payable'];
}
$total_cost = $total_tuition_payable + $total_fixed_all + $total_english_all;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">
            <i class="fas fa-file-invoice-dollar me-2 text-success"></i>
            Fee Package – <?= h($pkg['student_name']) ?>
        </h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-fee-package/index.php">Fee Packages</a></li>
            <li class="breadcrumb-item active"><?= h($pkg['student_name']) ?></li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/students/view.php?id=<?= $pkg['student_id'] ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-user-graduate me-1"></i> Student Profile
        </a>
        <?php if (sfp_can_delete()): ?>
        <form method="post" action="<?= APP_URL ?>/student-fee-package/delete.php"
              onsubmit="return confirm('Delete this fee package? All semester fee records will be lost.');">
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
═══════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-primary">
            <div class="card-body">
                <div class="text-muted small mb-1">Tuition Per Semester</div>
                <div class="fw-bold fs-5"><?= sfp_money((float)$pkg['tuition_per_semester']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-warning">
            <div class="card-body">
                <div class="text-muted small mb-1">Monthly Fixed Institutional</div>
                <div class="fw-bold fs-5"><?= sfp_money((float)$pkg['monthly_fixed_fee']) ?></div>
                <div class="text-muted" style="font-size:.75rem;">
                    <?= sfp_money((float)$pkg['fixed_institutional_fees']) ?> ÷ <?= (int)$pkg['total_months'] ?> months
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-info">
            <div class="card-body">
                <div class="text-muted small mb-1">Monthly English Fee</div>
                <div class="fw-bold fs-5"><?= sfp_money((float)$pkg['monthly_english_fee']) ?></div>
                <div class="text-muted" style="font-size:.75rem;">
                    <?= sfp_money((float)$pkg['english_course_fee']) ?> ÷ <?= (int)$pkg['total_months'] ?> months
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-success">
            <div class="card-body">
                <div class="text-muted small mb-1">Est. Total Cost (excl. admission)</div>
                <div class="fw-bold fs-5"><?= sfp_money($total_cost) ?></div>
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
                    'Tuition Per Semester'        => sfp_money((float)$pkg['tuition_per_semester']),
                    'Admission Fees (paid separately)' => sfp_money((float)$pkg['admission_fees']),
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
                        <th style="width:60px;">#</th>
                        <th>Semester</th>
                        <th class="text-end">Tuition</th>
                        <th class="text-end">Scholarship</th>
                        <th class="text-end">Tuition Payable</th>
                        <th class="text-end">Fixed Fees<br><small class="fw-normal text-muted">(for semester)</small></th>
                        <th class="text-end">English Fee<br><small class="fw-normal text-muted">(for semester)</small></th>
                        <th class="text-end fw-bold">Total Payable</th>
                        <th style="width:100px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $grand_tuition_payable = 0.0;
                $grand_fixed           = 0.0;
                $grand_english         = 0.0;
                $grand_total           = 0.0;
                foreach ($semester_fees as $sf):
                    $tuition_payable = (float)$sf['tuition_payable'];
                    $fixed_amt       = $sem_fixed_portion;
                    $english_amt     = $sem_english_portion;
                    $total_sem       = $tuition_payable + $fixed_amt + $english_amt;
                    $grand_tuition_payable += $tuition_payable;
                    $grand_fixed           += $fixed_amt;
                    $grand_english         += $english_amt;
                    $grand_total           += $total_sem;
                ?>
                <tr>
                    <td><?= (int)$sf['semester_number'] ?></td>
                    <td>
                        <?= h($sf['semester_label'] ?: '—') ?>
                        <?php if (sfp_can_edit()): ?>
                        <button type="button"
                                class="btn btn-link btn-sm p-0 ms-1 text-muted set-label-btn"
                                style="font-size:.7rem;"
                                data-sf-id="<?= $sf['id'] ?>"
                                data-current="<?= h($sf['semester_label'] ?? '') ?>"
                                title="Set semester label">
                            <i class="fas fa-pen"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-muted"><?= sfp_money((float)$sf['tuition_fee']) ?></td>
                    <td class="text-end">
                        <?php if ((float)$sf['scholarship_discount_pct'] > 0): ?>
                        <span class="text-danger">
                            −<?= sfp_money((float)$sf['scholarship_amount']) ?>
                            <small class="text-muted">(<?= number_format((float)$sf['scholarship_discount_pct'], 2) ?>%)</small>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold"><?= sfp_money($tuition_payable) ?></td>
                    <td class="text-end"><?= sfp_money($fixed_amt) ?></td>
                    <td class="text-end"><?= sfp_money($english_amt) ?></td>
                    <td class="text-end fw-bold text-success"><?= sfp_money($total_sem) ?></td>
                    <td class="text-end">
                        <?php if (sfp_can_edit()): ?>
                        <button type="button"
                                class="btn btn-outline-warning btn-sm apply-sc-btn"
                                data-sf-id="<?= $sf['id'] ?>"
                                data-sem-num="<?= $sf['semester_number'] ?>"
                                data-sem-label="<?= h($sf['semester_label'] ?? 'Semester ' . $sf['semester_number']) ?>"
                                data-current-pct="<?= number_format((float)$sf['scholarship_discount_pct'], 2) ?>"
                                data-current-note="<?= h($sf['note'] ?? '') ?>"
                                title="Apply / Update Scholarship">
                            <i class="fas fa-percentage"></i>
                        </button>
                        <?php if ((float)$sf['scholarship_discount_pct'] > 0): ?>
                        <form method="post" action="<?= APP_URL ?>/student-fee-package/remove-scholarship.php"
                              class="d-inline"
                              onsubmit="return confirm('Remove scholarship from this semester?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="sf_id" value="<?= $sf['id'] ?>">
                            <input type="hidden" name="package_id" value="<?= $id ?>">
                            <button class="btn btn-outline-secondary btn-sm" title="Remove Scholarship">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">Totals →</td>
                        <td class="text-end"><?= sfp_money($grand_tuition_payable) ?></td>
                        <td class="text-end"><?= sfp_money($grand_fixed) ?></td>
                        <td class="text-end"><?= sfp_money($grand_english) ?></td>
                        <td class="text-end text-success fs-6"><?= sfp_money($grand_total) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     APPLY SCHOLARSHIP MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="applyScModal" tabindex="-1" aria-labelledby="applyScModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= APP_URL ?>/student-fee-package/apply-scholarship.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="package_id" value="<?= $id ?>">
            <input type="hidden" name="sf_id"      id="modal-sf-id" value="">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="applyScModalLabel">
                        <i class="fas fa-percentage me-2"></i>Apply Scholarship
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3 text-muted small" id="modal-sem-info"></p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tuition Fee (this semester)</label>
                        <input type="text" id="modal-tuition-display" class="form-control bg-light" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Scholarship Discount % <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="discount_pct" id="modal-discount"
                                   class="form-control" step="0.01" min="0" max="100" required>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Scholarship Amount (auto-calculated)</label>
                        <div class="input-group">
                            <input type="text" id="modal-sc-amount" class="form-control bg-light" readonly>
                            <span class="input-group-text">BDT</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tuition Payable after Discount</label>
                        <div class="input-group">
                            <input type="text" id="modal-tuition-payable" class="form-control bg-light" readonly>
                            <span class="input-group-text">BDT</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Note</label>
                        <textarea name="sc_note" id="modal-note" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning text-dark">
                        <i class="fas fa-check me-1"></i> Apply
                    </button>
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
        <form method="post" action="<?= APP_URL ?>/student-fee-package/set-semester-label.php" novalidate>
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
// Semester fee amounts indexed by sf_id
var semFees = {};
<?php foreach ($semester_fees as $sf): ?>
semFees[<?= $sf['id'] ?>] = { tuition: <?= (float)$sf['tuition_fee'] ?> };
<?php endforeach; ?>

// ── Apply scholarship modal ───────────────────────────────────────────────────
document.querySelectorAll('.apply-sc-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var sfId   = this.dataset.sfId;
        var semLbl = this.dataset.semLabel || ('Semester ' + this.dataset.semNum);
        var pct    = this.dataset.currentPct;
        var note   = this.dataset.currentNote;
        var tuition = semFees[sfId] ? semFees[sfId].tuition : 0;

        document.getElementById('modal-sf-id').value      = sfId;
        document.getElementById('modal-sem-info').textContent = 'Applying to: ' + semLbl;
        document.getElementById('modal-tuition-display').value = tuition.toLocaleString('en-BD', {minimumFractionDigits:2});
        document.getElementById('modal-discount').value   = pct;
        document.getElementById('modal-note').value       = note;
        calcModal(tuition, parseFloat(pct) || 0);

        var modal = new bootstrap.Modal(document.getElementById('applyScModal'));
        modal.show();
    });
});

document.getElementById('modal-discount').addEventListener('input', function() {
    var sfId = document.getElementById('modal-sf-id').value;
    var tuition = semFees[sfId] ? semFees[sfId].tuition : 0;
    calcModal(tuition, parseFloat(this.value) || 0);
});

function calcModal(tuition, pct) {
    var sc  = tuition * pct / 100;
    var pay = tuition - sc;
    document.getElementById('modal-sc-amount').value      = sc.toLocaleString('en-BD', {minimumFractionDigits:2});
    document.getElementById('modal-tuition-payable').value = pay.toLocaleString('en-BD', {minimumFractionDigits:2});
}

// ── Set semester label modal ──────────────────────────────────────────────────
document.querySelectorAll('.set-label-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('lbl-sf-id').value  = this.dataset.sfId;
        document.getElementById('lbl-input').value  = this.dataset.current;
        var modal = new bootstrap.Modal(document.getElementById('setLabelModal'));
        modal.show();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
