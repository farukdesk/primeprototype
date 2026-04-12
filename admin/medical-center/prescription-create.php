<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

if (!mc_can_create()) {
    flash_set('error', 'Permission denied.');
    redirect(APP_URL . '/medical-center/prescriptions.php');
}

$page_title = 'New Prescription';
$db     = db();
$errors = [];

// Pre-fill from appointment if provided
$apt_id = (int)($_GET['apt_id'] ?? $_POST['appointment_id'] ?? 0);
$apt    = null;
if ($apt_id > 0) {
    $s = $db->prepare('SELECT * FROM mc_appointments WHERE id = ?');
    $s->execute([$apt_id]);
    $apt = $s->fetch() ?: null;
}

// Load medicines for autocomplete
$medicine_list = $db->query("SELECT name, generic_name FROM mc_medicines WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $patient_name    = trim($_POST['patient_name']    ?? '');
    $patient_type    = $_POST['patient_type']         ?? 'student';
    $patient_id_no   = trim($_POST['patient_id_no']   ?? '');
    $department      = trim($_POST['department']      ?? '');
    $age             = trim($_POST['age']             ?? '');
    $gender          = $_POST['gender']               ?? '';
    $contact_number  = trim($_POST['contact_number']  ?? '');
    $diagnosis       = trim($_POST['diagnosis']       ?? '');
    $advice          = trim($_POST['advice']          ?? '');
    $follow_up_date  = trim($_POST['follow_up_date']  ?? '') ?: null;
    $prescription_date = trim($_POST['prescription_date'] ?? date('Y-m-d'));
    $appointment_id  = (int)($_POST['appointment_id'] ?? 0) ?: null;

    // Medicines JSON
    $med_names     = $_POST['med_name']     ?? [];
    $med_dosages   = $_POST['med_dosage']   ?? [];
    $med_durations = $_POST['med_duration'] ?? [];
    $med_qtys      = $_POST['med_qty']      ?? [];
    $medicines     = [];
    foreach ($med_names as $idx => $mname) {
        $mname = trim($mname);
        if ($mname === '') continue;
        $medicines[] = [
            'name'     => $mname,
            'dosage'   => trim($med_dosages[$idx]   ?? ''),
            'duration' => trim($med_durations[$idx] ?? ''),
            'qty'      => trim($med_qtys[$idx]      ?? ''),
        ];
    }

    if ($patient_name === '') $errors[] = 'Patient name is required.';
    if (!in_array($patient_type, ['student','faculty','staff','officer'], true)) $patient_type = 'student';
    if ($prescription_date === '' || !strtotime($prescription_date)) $errors[] = 'A valid prescription date is required.';

    if (empty($errors)) {
        $db->prepare(
            'INSERT INTO mc_prescriptions
             (appointment_id, patient_name, patient_type, patient_id_no, department, age, gender,
              contact_number, diagnosis, medicines_json, advice, follow_up_date, prescription_date, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $appointment_id,
            $patient_name,
            $patient_type,
            $patient_id_no,
            $department,
            $age,
            $gender ?: null,
            $contact_number,
            $diagnosis,
            json_encode($medicines, JSON_UNESCAPED_UNICODE),
            $advice,
            $follow_up_date,
            $prescription_date,
            auth_user()['id'] ?? null,
        ]);

        $new_id = (int)$db->lastInsertId();
        log_change('medical-center', 'CREATE', $new_id, $patient_name, null, null, null, 'Prescription created');

        flash_set('success', 'Prescription created successfully.');
        redirect(APP_URL . '/medical-center/prescription-view.php?id=' . $new_id);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-file-medical me-2 text-success"></i>New Prescription</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/index.php">Medical Center</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/prescriptions.php">Prescriptions</a></li>
            <li class="breadcrumb-item active">New</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/medical-center/prescriptions.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post" id="rxForm">
    <?= csrf_field() ?>
    <?php if ($apt_id): ?>
    <input type="hidden" name="appointment_id" value="<?= $apt_id ?>">
    <?php endif; ?>

    <div class="row g-3">
        <!-- Patient Info -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header py-3">
                    <span class="fw-semibold"><i class="fas fa-user me-2 text-primary"></i>Patient Information</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Patient Name <span class="text-danger">*</span></label>
                            <input type="text" name="patient_name" class="form-control"
                                   value="<?= h($_POST['patient_name'] ?? $apt['patient_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Patient Type</label>
                            <select name="patient_type" class="form-select">
                                <?php foreach (['student','faculty','staff','officer'] as $t): ?>
                                <option value="<?= $t ?>" <?= ($_POST['patient_type'] ?? $apt['patient_type'] ?? 'student') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">ID / Student No.</label>
                            <input type="text" name="patient_id_no" class="form-control"
                                   value="<?= h($_POST['patient_id_no'] ?? $apt['patient_id_no'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Department</label>
                            <input type="text" name="department" class="form-control"
                                   value="<?= h($_POST['department'] ?? $apt['department'] ?? '') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Age</label>
                            <input type="text" name="age" class="form-control" placeholder="e.g. 22"
                                   value="<?= h($_POST['age'] ?? '') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="">— Select —</option>
                                <option value="male"   <?= ($_POST['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= ($_POST['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                                <option value="other"  <?= ($_POST['gender'] ?? '') === 'other'  ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Contact Number</label>
                            <input type="text" name="contact_number" class="form-control"
                                   value="<?= h($_POST['contact_number'] ?? $apt['contact_number'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Diagnosis -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header py-3">
                    <span class="fw-semibold"><i class="fas fa-stethoscope me-2 text-danger"></i>Diagnosis &amp; Medicines</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Diagnosis</label>
                        <textarea name="diagnosis" rows="3" class="form-control"
                                  placeholder="Enter diagnosis / clinical findings…"><?= h($_POST['diagnosis'] ?? '') ?></textarea>
                    </div>

                    <!-- Dynamic Medicine Rows -->
                    <label class="form-label fw-semibold">Medicines</label>
                    <div id="medRows">
                        <div class="row g-2 align-items-end med-row mb-2">
                            <div class="col-md-4">
                                <input type="text" name="med_name[]" class="form-control form-control-sm"
                                       placeholder="Medicine name" list="med-list">
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="med_dosage[]" class="form-control form-control-sm"
                                       placeholder="Dosage (e.g. 500mg TDS)">
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="med_duration[]" class="form-control form-control-sm"
                                       placeholder="Duration (e.g. 5 days)">
                            </div>
                            <div class="col-md-1">
                                <input type="text" name="med_qty[]" class="form-control form-control-sm"
                                       placeholder="Qty">
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-outline-danger btn-sm remove-row" title="Remove">
                                    <i class="fas fa-minus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <datalist id="med-list">
                        <?php foreach ($medicine_list as $m): ?>
                        <option value="<?= h($m['name']) ?>"><?= h($m['generic_name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <button type="button" id="addMedRow" class="btn btn-outline-success btn-sm mt-2">
                        <i class="fas fa-plus me-1"></i> Add Medicine
                    </button>
                </div>
            </div>

            <!-- Advice -->
            <div class="card border-0 shadow-sm">
                <div class="card-header py-3">
                    <span class="fw-semibold"><i class="fas fa-notes-medical me-2 text-info"></i>Advice &amp; Follow-up</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Advice</label>
                        <textarea name="advice" rows="3" class="form-control"
                                  placeholder="Patient advice, lifestyle changes, dietary instructions…"><?= h($_POST['advice'] ?? '') ?></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Follow-up Date</label>
                            <input type="date" name="follow_up_date" class="form-control"
                                   value="<?= h($_POST['follow_up_date'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Prescription Date <span class="text-danger">*</span></label>
                            <input type="date" name="prescription_date" class="form-control" required
                                   value="<?= h($_POST['prescription_date'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <?php if ($apt): ?>
            <div class="card border-0 shadow-sm border-start border-4 border-primary mb-3">
                <div class="card-body">
                    <div class="small text-muted mb-1">Linked Appointment</div>
                    <div class="fw-bold"><?= h($apt['patient_name']) ?></div>
                    <div class="small text-muted"><?= date('d M Y', strtotime($apt['appointment_date'])) ?> · <?= date('h:i A', strtotime($apt['appointment_time'])) ?></div>
                    <div class="mt-1"><?= mc_status_badge($apt['status']) ?></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        <i class="fas fa-save me-1"></i> Save Prescription
                    </button>
                    <a href="<?= APP_URL ?>/medical-center/prescriptions.php" class="btn btn-outline-secondary w-100">
                        Cancel
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.getElementById('addMedRow').addEventListener('click', function () {
    const container = document.getElementById('medRows');
    const row = container.querySelector('.med-row').cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    container.appendChild(row);
});

document.getElementById('medRows').addEventListener('click', function (e) {
    if (e.target.closest('.remove-row')) {
        const rows = this.querySelectorAll('.med-row');
        if (rows.length > 1) e.target.closest('.med-row').remove();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
