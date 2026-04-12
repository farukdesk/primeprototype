<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

$page_title = 'View Prescription';
$db  = db();
$id  = (int)($_GET['id'] ?? 0);

$rx = $db->prepare('SELECT * FROM mc_prescriptions WHERE id = ?');
$rx->execute([$id]);
$rx = $rx->fetch();

if (!$rx) {
    flash_set('error', 'Prescription not found.');
    redirect(APP_URL . '/medical-center/prescriptions.php');
}

$medicines = json_decode($rx['medicines_json'] ?? '[]', true) ?: [];

// Doctor info from settings
$doctor_name     = mc_setting('doctor_name',          'Dr. Saida Ahmed');
$doctor_qual     = mc_setting('doctor_qualification', 'MBBS, MPH (NIPSOM), CCD, CCVD, FCGP');
$doctor_desg     = mc_setting('doctor_designation',   'Medical Officer');
$clinic_name     = mc_setting('clinic_name',          'Prime University Medical Center');
$clinic_loc      = mc_setting('clinic_location',      'Prime University');
$clinic_phone    = mc_setting('contact_phone',        '');

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 d-print-none">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-file-medical me-2 text-success"></i>Prescription #<?= $rx['id'] ?></h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/index.php">Medical Center</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/prescriptions.php">Prescriptions</a></li>
            <li class="breadcrumb-item active">View</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/medical-center/prescriptions.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <button onclick="window.print()" class="btn btn-primary btn-sm">
            <i class="fas fa-print me-1"></i> Print
        </button>
    </div>
</div>

<?= flash_show() ?>

<style>
@media print {
    .d-print-none { display: none !important; }
    body { background: #fff !important; }
    .card { border: none !important; box-shadow: none !important; }
    .rx-page { max-width: 100% !important; margin: 0 !important; }
}
.rx-page { max-width: 800px; margin: 0 auto; font-family: 'Times New Roman', Times, serif; }
.rx-header { border-bottom: 3px double #1a3a6b; padding-bottom: 12px; margin-bottom: 16px; }
.rx-symbol { font-size: 3rem; color: #1a3a6b; font-weight: bold; font-style: italic; }
.rx-patient-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; font-size: 0.9rem; margin-bottom: 12px; }
.rx-label { color: #555; }
.rx-body { border: 1px solid #ccc; border-radius: 4px; padding: 16px; min-height: 280px; }
.rx-med-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.rx-med-table th { border-bottom: 1px solid #aaa; padding: 4px 8px; text-align: left; font-weight: bold; }
.rx-med-table td { padding: 4px 8px; vertical-align: top; }
.rx-footer { border-top: 1px solid #ccc; margin-top: 16px; padding-top: 10px; font-size: 0.8rem; color: #555; }
</style>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <div class="rx-page">
            <!-- Header -->
            <div class="rx-header d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-bold fs-5 text-primary"><?= h($clinic_name) ?></div>
                    <div class="small text-muted"><?= h($clinic_loc) ?></div>
                    <?php if ($clinic_phone): ?><div class="small">Phone: <?= h($clinic_phone) ?></div><?php endif; ?>
                </div>
                <div class="text-end">
                    <div class="fw-bold"><?= h($doctor_name) ?></div>
                    <div class="small text-muted"><?= h($doctor_qual) ?></div>
                    <div class="small"><?= h($doctor_desg) ?></div>
                </div>
            </div>

            <!-- Patient Info -->
            <div class="rx-patient-grid mb-3">
                <div><span class="rx-label">Patient: </span><strong><?= h($rx['patient_name']) ?></strong></div>
                <div><span class="rx-label">Date: </span><?= date('d F Y', strtotime($rx['prescription_date'])) ?></div>
                <div><span class="rx-label">Age/Gender: </span><?= h(trim(($rx['age'] ?? '') . ' / ' . ucfirst($rx['gender'] ?? ''))) ?: '—' ?></div>
                <div><span class="rx-label">Type: </span><?= ucfirst(h($rx['patient_type'])) ?></div>
                <div><span class="rx-label">ID No: </span><?= h($rx['patient_id_no'] ?: '—') ?></div>
                <div><span class="rx-label">Contact: </span><?= h($rx['contact_number'] ?: '—') ?></div>
                <?php if ($rx['department']): ?>
                <div><span class="rx-label">Department: </span><?= h($rx['department']) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($rx['diagnosis']): ?>
            <div class="mb-3">
                <strong>Diagnosis:</strong>
                <div class="text-muted small"><?= nl2br(h($rx['diagnosis'])) ?></div>
            </div>
            <?php endif; ?>

            <!-- Medicines -->
            <div class="rx-body mb-3">
                <div class="rx-symbol mb-3">℞</div>
                <?php if ($medicines): ?>
                <table class="rx-med-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Medicine</th>
                            <th>Dosage</th>
                            <th>Duration</th>
                            <th>Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medicines as $mi => $med): ?>
                        <tr>
                            <td><?= $mi + 1 ?></td>
                            <td><?= h($med['name'] ?? '') ?></td>
                            <td><?= h($med['dosage'] ?? '') ?></td>
                            <td><?= h($med['duration'] ?? '') ?></td>
                            <td><?= h($med['qty'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="text-muted fst-italic">No medicines prescribed.</p>
                <?php endif; ?>
            </div>

            <!-- Advice -->
            <?php if ($rx['advice']): ?>
            <div class="mb-3">
                <strong>Advice:</strong>
                <div class="text-muted small"><?= nl2br(h($rx['advice'])) ?></div>
            </div>
            <?php endif; ?>

            <?php if ($rx['follow_up_date']): ?>
            <div class="mb-3">
                <strong>Follow-up Date:</strong> <?= date('d F Y', strtotime($rx['follow_up_date'])) ?>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="rx-footer d-flex justify-content-between align-items-end">
                <div>
                    <div class="small text-muted">Rx #<?= $rx['id'] ?> · Generated <?= date('d M Y H:i') ?></div>
                </div>
                <div class="text-end">
                    <div style="border-top:1px solid #333; padding-top:4px; min-width:150px; text-align:center;">
                        Doctor's Signature
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
