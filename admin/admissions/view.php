<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions');
require_once __DIR__ . '/helpers.php';

$id           = (int)($_GET['id'] ?? 0);
$app          = adm_get($id);
$acad_records = adm_get_academic_records($id);

$page_title = 'Application – ' . $app['app_number'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-file-alt me-2 text-primary"></i><?= h($app['app_number']) ?></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/index.php">Admissions</a></li>
            <li class="breadcrumb-item active"><?= h($app['app_number']) ?></li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (adm_can_edit()): ?>
        <a href="<?= APP_URL ?>/admissions/edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit me-1"></i> Edit</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admissions/statement.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-success btn-sm"><i class="fas fa-file-invoice-dollar me-1"></i> Statement</a>
        <a href="<?= APP_URL ?>/admissions/print.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-info btn-sm"><i class="fas fa-print me-1"></i> Print</a>
        <?php if (adm_can_delete()): ?>
        <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmDelete(<?= $id ?>, '<?= h(addslashes($app['app_number'])) ?>')">
            <i class="fas fa-trash me-1"></i> Delete
        </button>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admissions/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>
</div>

<?php flash_show(); ?>

<div class="row g-4">
    <!-- Left column -->
    <div class="col-12 col-xl-8">

        <!-- Application Info -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-file-alt me-2 text-primary"></i>Application Info</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-4"><div class="text-muted small">Application Number</div><div class="fw-semibold"><?= h($app['app_number']) ?></div></div>
                    <div class="col-6 col-md-4"><div class="text-muted small">Status</div><div><?= adm_status_badge($app['status']) ?></div></div>
                    <div class="col-6 col-md-4"><div class="text-muted small">Created</div><div><?= h(date('d M Y, g:i A', strtotime($app['created_at']))) ?></div></div>
                    <div class="col-6 col-md-4"><div class="text-muted small">Department</div><div><?= h($app['dept_name'] ?? '—') ?></div></div>
                    <div class="col-6 col-md-4"><div class="text-muted small">Program</div><div><?= h($app['program_name'] ?? '—') ?></div></div>
                    <div class="col-6 col-md-2"><div class="text-muted small">Year</div><div><?= h($app['year'] ?? '—') ?></div></div>
                    <div class="col-6 col-md-2"><div class="text-muted small">Semester</div><div><?= h($app['semester'] ?? '—') ?></div></div>
                </div>
            </div>
        </div>

        <!-- Financial Package -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-coins me-2 text-success"></i>Financial Package</div>
            <div class="card-body">
                <?php if (!empty($app['financial_package_name'])): ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6"><div class="text-muted small">Package</div><div><?= h($app['financial_package_name']) ?></div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Semesters</div><div><?= h($app['financial_total_semesters'] ?? '—') ?></div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Months</div><div><?= h($app['financial_total_months'] ?? '—') ?></div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Tuition / Semester</div><div><?= number_format((float)($app['financial_tuition_per_semester'] ?? 0), 2) ?> BDT</div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Admission Fee</div><div><?= number_format((float)($app['financial_admission_fee'] ?? 0), 2) ?> BDT</div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Registration / Semester</div><div><?= number_format((float)($app['financial_registration_fee_per_semester'] ?? 0), 2) ?> BDT</div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Form & ID Fee</div><div><?= number_format((float)($app['financial_form_id_fee'] ?? 0), 2) ?> BDT</div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Institutional Fees</div><div><?= number_format((float)($app['financial_fixed_institutional_fees'] ?? 0), 2) ?> BDT</div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">English Course Fee</div><div><?= number_format((float)($app['financial_english_course_fee'] ?? 0), 2) ?> BDT</div></div>
                </div>
                <?php else: ?>
                <div class="text-muted">No financial package assigned yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Scholarship -->
        <?php if (!empty($app['financial_package_name']) && adm_can_edit()): ?>
        <?php
        $sc_amount = (float)($app['scholarship_amount'] ?? 0);
        $sc_label  = (string)($app['scholarship_label']  ?? '');
        ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="fas fa-graduation-cap me-2 text-warning"></i>Scholarship</span>
                <button type="button" class="btn btn-warning btn-sm"
                        data-bs-toggle="modal" data-bs-target="#scModal"
                        data-label="<?= h($sc_label) ?>"
                        data-amount="<?= h(number_format($sc_amount, 2)) ?>">
                    <i class="fas fa-<?= $sc_amount > 0 ? 'edit' : 'plus' ?> me-1"></i>
                    <?= $sc_amount > 0 ? 'Edit Scholarship' : 'Add Scholarship' ?>
                </button>
            </div>
            <div class="card-body">
                <?php if ($sc_amount > 0): ?>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold"><?= h($sc_label) ?></div>
                        <div class="text-success fw-semibold">BDT <?= number_format($sc_amount, 2) ?> discount on first semester</div>
                    </div>
                    <form method="post" action="<?= APP_URL ?>/admissions/remove-scholarship.php"
                          onsubmit="return confirm('Remove scholarship from this application?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button class="btn btn-outline-danger btn-sm">
                            <i class="fas fa-times me-1"></i>Remove
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <div class="text-muted small">No scholarship assigned yet.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Personal Info -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-user me-2 text-success"></i>Student Personal Information</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6"><div class="text-muted small">Student Name</div><div><?= h($app['student_name']) ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Father's Name</div><div><?= h($app['father_name'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Mother's Name</div><div><?= h($app['mother_name'] ?? '—') ?></div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Sex</div><div><?= h($app['sex'] ?? '—') ?></div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Date of Birth</div><div><?= $app['date_of_birth'] ? h(date('d M Y', strtotime($app['date_of_birth']))) : '—' ?></div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Nationality</div><div><?= h($app['nationality'] ?? '—') ?></div></div>
                    <div class="col-6 col-md-3"><div class="text-muted small">Blood Group</div><div><?= h($app['blood_group'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Place of Birth</div><div><?= h($app['place_of_birth'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Religion</div><div><?= h($app['religion'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">NID / Birth Cert No</div><div><?= h($app['nid_birth_cert'] ?? '—') ?></div></div>
                </div>
            </div>
        </div>

        <!-- Address -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-map-marker-alt me-2 text-warning"></i>Address</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12"><strong class="small text-muted text-uppercase">Present Address</strong></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Line 1</div><div><?= h($app['present_address_1'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Line 2</div><div><?= h($app['present_address_2'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Contact</div><div><?= h($app['present_contact'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Email</div><div><?= h($app['present_email'] ?? '—') ?></div></div>
                    <div class="col-12"><hr class="my-1"><strong class="small text-muted text-uppercase">Permanent Address</strong></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Line 1</div><div><?= h($app['permanent_address_1'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Line 2</div><div><?= h($app['permanent_address_2'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Contact</div><div><?= h($app['permanent_contact'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Email</div><div><?= h($app['permanent_email'] ?? '—') ?></div></div>
                </div>
            </div>
        </div>

        <!-- Academic Qualifications -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-graduation-cap me-2 text-info"></i>Academic Qualifications</div>
            <?php if ($acad_records): ?>
            <div class="table-responsive">
                <table class="table table-bordered mb-0 small">
                    <thead class="table-light">
                        <tr><th>Exam</th><th>Session</th><th>Group</th><th>Board/University</th><th>Year</th><th>Division/Grade</th><th>Marks/CGPA</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($acad_records as $ar): ?>
                        <tr>
                            <td><?= h($ar['exam_name'] ?? '') ?></td>
                            <td><?= h($ar['session'] ?? '') ?></td>
                            <td><?= h($ar['group_name'] ?? '') ?></td>
                            <td><?= h($ar['board_university'] ?? '') ?></td>
                            <td><?= h($ar['year_of_passing'] ?? '') ?></td>
                            <td><?= h($ar['division_grade'] ?? '') ?></td>
                            <td><?= h($ar['total_marks_cgpa'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="card-body text-muted">No academic records.</div>
            <?php endif; ?>
        </div>

        <!-- Experience -->
        <?php if ($app['experience']): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-briefcase me-2 text-secondary"></i>Experience</div>
            <div class="card-body"><?= nl2br(h($app['experience'])) ?></div>
        </div>
        <?php endif; ?>

        <!-- Guardian Particulars -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-users me-2" style="color:#6f42c1"></i>Guardian Particulars</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6"><div class="text-muted small">Name</div><div><?= h($app['guardian_name'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Profession</div><div><?= h($app['guardian_profession'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Address Line 1</div><div><?= h($app['guardian_address_1'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Address Line 2</div><div><?= h($app['guardian_address_2'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Phone</div><div><?= h($app['guardian_phone'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Email</div><div><?= h($app['guardian_email'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Relationship</div><div><?= h($app['guardian_relationship'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Monthly Average Income</div><div><?= h($app['guardian_monthly_income'] ?? '—') ?></div></div>
                </div>
            </div>
        </div>

        <!-- Local Guardian -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-home me-2" style="color:#20c997"></i>Local Guardian</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6"><div class="text-muted small">Name</div><div><?= h($app['local_guardian_name'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Contact</div><div><?= h($app['local_guardian_contact'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Address Line 1</div><div><?= h($app['local_guardian_address_1'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Address Line 2</div><div><?= h($app['local_guardian_address_2'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Address Line 3</div><div><?= h($app['local_guardian_address_3'] ?? '—') ?></div></div>
                </div>
            </div>
        </div>

        <!-- Reference -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-user-tie me-2 text-dark"></i>Reference</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6"><div class="text-muted small">Name</div><div><?= h($app['reference_name'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Contact</div><div><?= h($app['reference_contact'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Address Line 1</div><div><?= h($app['reference_address_1'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Address Line 2</div><div><?= h($app['reference_address_2'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Address Line 3</div><div><?= h($app['reference_address_3'] ?? '—') ?></div></div>
                </div>
            </div>
        </div>

        <!-- Additional Questions -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-question-circle me-2 text-danger"></i>Additional Questions</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-12"><div class="text-muted small">Expelled from any institution?</div><div><?= h($app['expelled_answer'] ?? 'No') ?></div></div>
                    <?php if (($app['expelled_answer'] ?? '') === 'Yes'): ?>
                    <div class="col-12"><div class="text-muted small">Details</div><div><?= h($app['expelled_detail'] ?? '—') ?></div></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- For Office Use Only -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-stamp me-2 text-secondary"></i>For Office Use Only</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6"><div class="text-muted small">Program</div><div><?= h($app['office_program'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-6"><div class="text-muted small">Student ID No</div><div><?= h($app['office_student_id'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Batch No</div><div><?= h($app['office_batch_no'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Decision</div><div><?= h($app['office_decision'] ?? '—') ?></div></div>
                    <div class="col-12 col-md-4"><div class="text-muted small">Checked By</div><div><?= h($app['office_checked_by'] ?? '—') ?></div></div>
                </div>
            </div>
        </div>

    </div><!-- /Left column -->

    <!-- Right column: Photo -->
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm mb-4 sticky-top" style="top:80px">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-camera me-2 text-info"></i>Applicant Photo</div>
            <div class="card-body text-center">
                <?php if ($app['photo']): ?>
                <img src="<?= UPLOAD_URL . '/' . ADM_PHOTO_SUBDIR . '/' . h($app['photo']) ?>"
                     class="img-thumbnail" style="max-width:200px;max-height:250px" alt="Applicant Photo">
                <?php else: ?>
                <div class="border rounded d-flex align-items-center justify-content-center bg-light mx-auto" style="width:160px;height:200px">
                    <i class="fas fa-user fa-3x text-muted"></i>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<?php if (adm_can_delete()): ?>
<form id="deleteForm" method="POST" action="<?= APP_URL ?>/admissions/delete.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="deleteId">
</form>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">Delete Application</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete application <strong id="deleteLabel"></strong>? This action cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" style="border-radius:8px;" onclick="document.getElementById('deleteForm').submit()">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>
<script>
function confirmDelete(id, label) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteLabel').textContent = label;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
<?php endif; ?>

<?php if (!empty($app['financial_package_name']) && adm_can_edit()): ?>
<!-- ══════════════════════════════════════════════════════════
     ADD / EDIT SCHOLARSHIP MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="scModal" tabindex="-1" aria-labelledby="scModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= APP_URL ?>/admissions/set-scholarship.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="scModalLabel">
                        <i class="fas fa-graduation-cap me-2"></i>Add / Edit Scholarship
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        The scholarship amount will be shown as a discount on the first semester in the payment statement.
                    </p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Scholarship Label <span class="text-danger">*</span></label>
                        <input type="text" name="scholarship_label" id="sc-label" class="form-control"
                               placeholder="e.g. Merit Scholarship, Freedom Fighter, Sports Award" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Scholarship Amount (BDT) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">BDT</span>
                            <input type="number" name="scholarship_amount" id="sc-amount" class="form-control"
                                   step="0.01" min="0.01" placeholder="e.g. 5000" required>
                        </div>
                        <div class="form-text">Fixed discount applied to the first semester fees.</div>
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
// Pre-fill scholarship modal with existing values when opened
document.getElementById('scModal').addEventListener('show.bs.modal', function(event) {
    const btn    = event.relatedTarget;
    const label  = btn ? (btn.dataset.label  || '') : '';
    const amount = btn ? (btn.dataset.amount || '') : '';
    document.getElementById('sc-label').value  = label;
    document.getElementById('sc-amount').value = amount;
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
