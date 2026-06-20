<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

$page_title = 'View Appointment';
$db  = db();
$id  = (int)($_GET['id'] ?? 0);

$apt = $db->prepare('SELECT * FROM mc_appointments WHERE id = ?');
$apt->execute([$id]);
$apt = $apt->fetch();

if (!$apt) {
    flash_set('error', 'Appointment not found.');
    redirect(APP_URL . '/medical-center/appointments.php');
}

// Handle status/notes update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!mc_can_edit()) {
        flash_set('error', 'Permission denied.');
        redirect(APP_URL . '/medical-center/appointment-view.php?id=' . $id);
    }

    $new_status = $_POST['status'] ?? $apt['status'];
    $new_notes  = trim($_POST['notes'] ?? '');

    if (!in_array($new_status, ['pending','confirmed','completed','cancelled'], true)) {
        $new_status = $apt['status'];
    }

    $db->prepare('UPDATE mc_appointments SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?')
       ->execute([$new_status, $new_notes, $id]);

    log_change('medical-center', 'UPDATE', $id, $apt['patient_name'],
               'status', $apt['status'], $new_status, 'Appointment status updated');

    flash_set('success', 'Appointment updated successfully.');
    redirect(APP_URL . '/medical-center/appointment-view.php?id=' . $id);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-calendar-check me-2 text-primary"></i>Appointment Details</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/index.php">Medical Center</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/medical-center/appointments.php">Appointments</a></li>
            <li class="breadcrumb-item active">View</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/medical-center/appointments.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <?php if (mc_can_create()): ?>
        <a href="<?= APP_URL ?>/medical-center/prescription-create.php?apt_id=<?= $apt['id'] ?>" class="btn btn-success btn-sm">
            <i class="fas fa-file-medical me-1"></i> Create Prescription
        </a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Patient Information</span>
                <?= mc_status_badge($apt['status']) ?>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Token Number</dt>
                    <dd class="col-sm-8"><code class="fs-6"><?= h($apt['token_number'] ?: '—') ?></code></dd>

                    <dt class="col-sm-4 text-muted">Patient Name</dt>
                    <dd class="col-sm-8 fw-semibold"><?= h($apt['patient_name']) ?></dd>

                    <dt class="col-sm-4 text-muted">Patient Type</dt>
                    <dd class="col-sm-8"><?= mc_patient_badge($apt['patient_type']) ?></dd>

                    <dt class="col-sm-4 text-muted">ID / Student No.</dt>
                    <dd class="col-sm-8"><?= h($apt['patient_id_no'] ?: '—') ?></dd>

                    <dt class="col-sm-4 text-muted">Department</dt>
                    <dd class="col-sm-8"><?= h($apt['department'] ?: '—') ?></dd>

                    <dt class="col-sm-4 text-muted">Contact</dt>
                    <dd class="col-sm-8"><?= h($apt['contact_number']) ?></dd>

                    <dt class="col-sm-4 text-muted">Email</dt>
                    <dd class="col-sm-8"><?= h($apt['email'] ?: '—') ?></dd>

                    <dt class="col-sm-4 text-muted">Appointment Date</dt>
                    <dd class="col-sm-8"><?= date('l, d F Y', strtotime($apt['appointment_date'])) ?></dd>

                    <dt class="col-sm-4 text-muted">Appointment Time</dt>
                    <dd class="col-sm-8"><?= date('h:i A', strtotime($apt['appointment_time'])) ?></dd>

                    <dt class="col-sm-4 text-muted">Chief Complaint</dt>
                    <dd class="col-sm-8"><?= nl2br(h($apt['chief_complaint'] ?: '—')) ?></dd>

                    <dt class="col-sm-4 text-muted">Submitted</dt>
                    <dd class="col-sm-8 small text-muted"><?= date('d M Y h:i A', strtotime($apt['created_at'])) ?></dd>
                </dl>
            </div>
        </div>

        <!-- Linked Prescriptions -->
        <?php
        $linked_rx = $db->prepare('SELECT * FROM mc_prescriptions WHERE appointment_id = ? ORDER BY created_at DESC');
        $linked_rx->execute([$apt['id']]);
        $linked_rx = $linked_rx->fetchAll();
        ?>
        <?php if ($linked_rx): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header py-3">
                <span class="fw-semibold"><i class="fas fa-file-medical me-2 text-success"></i>Linked Prescriptions</span>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($linked_rx as $rx): ?>
                <a href="<?= APP_URL ?>/medical-center/prescription-view.php?id=<?= $rx['id'] ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2">
                    <div>
                        <div class="fw-semibold small">Rx #<?= $rx['id'] ?></div>
                        <div class="text-muted small"><?= date('d M Y', strtotime($rx['prescription_date'])) ?></div>
                    </div>
                    <i class="fas fa-chevron-right text-muted"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Update Form -->
    <?php if (mc_can_edit()): ?>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header py-3">
                <span class="fw-semibold"><i class="fas fa-edit me-2 text-warning"></i>Update Appointment</span>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= $apt['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Internal Notes</label>
                        <textarea name="notes" rows="5" class="form-control"
                                  placeholder="Add notes, observations…"><?= h($apt['notes'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-1"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
