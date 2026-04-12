<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('medical-center');

$page_title = 'Medical Center Dashboard';
$db = db();

$today_appointments    = (int)$db->query("SELECT COUNT(*) FROM mc_appointments WHERE appointment_date = CURDATE()")->fetchColumn();
$pending_appointments  = (int)$db->query("SELECT COUNT(*) FROM mc_appointments WHERE status = 'pending'")->fetchColumn();
$low_stock_medicines   = (int)$db->query("SELECT COUNT(*) FROM mc_medicines WHERE quantity_in_stock <= reorder_level AND is_active = 1")->fetchColumn();
$monthly_prescriptions = (int)$db->query("SELECT COUNT(*) FROM mc_prescriptions WHERE MONTH(prescription_date) = MONTH(CURDATE()) AND YEAR(prescription_date) = YEAR(CURDATE())")->fetchColumn();

$recent_appointments = $db->query("SELECT * FROM mc_appointments ORDER BY created_at DESC LIMIT 10")->fetchAll();

$upcoming = $db->query(
    "SELECT * FROM mc_appointments
     WHERE appointment_date >= CURDATE()
       AND appointment_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
       AND status IN ('pending','confirmed')
     ORDER BY appointment_date, appointment_time LIMIT 5"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-hospital me-2" style="color:#20b2aa"></i>Medical Center</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Medical Center</li>
        </ol></nav>
    </div>
    <?php if (mc_can_create()): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/medical-center/prescription-create.php" class="btn btn-primary btn-sm">
            <i class="fas fa-file-medical me-1"></i> New Prescription
        </a>
    </div>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#e8f4fd"><i class="fas fa-calendar-day fa-lg" style="color:#0f4c75"></i></div>
                <div>
                    <div class="h4 mb-0 fw-bold"><?= $today_appointments ?></div>
                    <div class="text-muted small">Today's Appointments</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#fff8e1"><i class="fas fa-clock fa-lg text-warning"></i></div>
                <div>
                    <div class="h4 mb-0 fw-bold"><?= $pending_appointments ?></div>
                    <div class="text-muted small">Pending Appointments</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#fce4ec"><i class="fas fa-pills fa-lg text-danger"></i></div>
                <div>
                    <div class="h4 mb-0 fw-bold"><?= $low_stock_medicines ?></div>
                    <div class="text-muted small">Low Stock Medicines</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#e8f5e9"><i class="fas fa-file-medical fa-lg text-success"></i></div>
                <div>
                    <div class="h4 mb-0 fw-bold"><?= $monthly_prescriptions ?></div>
                    <div class="text-muted small">Prescriptions This Month</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Recent Appointments -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center py-3">
                <span class="fw-semibold"><i class="fas fa-calendar-check me-2 text-primary"></i>Recent Appointments</span>
                <a href="<?= APP_URL ?>/medical-center/appointments.php" class="btn btn-outline-primary btn-sm">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Token</th>
                            <th>Patient</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_appointments): ?>
                        <?php foreach ($recent_appointments as $a): ?>
                        <tr>
                            <td><code><?= h($a['token_number'] ?: '—') ?></code></td>
                            <td>
                                <div class="fw-semibold"><?= h($a['patient_name']) ?></div>
                                <div class="small text-muted"><?= h($a['contact_number']) ?></div>
                            </td>
                            <td><?= mc_patient_badge($a['patient_type']) ?></td>
                            <td>
                                <div><?= date('d M Y', strtotime($a['appointment_date'])) ?></div>
                                <div class="small text-muted"><?= date('h:i A', strtotime($a['appointment_time'])) ?></div>
                            </td>
                            <td><?= mc_status_badge($a['status']) ?></td>
                            <td>
                                <a href="<?= APP_URL ?>/medical-center/appointment-view.php?id=<?= $a['id'] ?>" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No appointments yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header py-3">
                <span class="fw-semibold"><i class="fas fa-calendar-alt me-2 text-success"></i>Upcoming (7 days)</span>
            </div>
            <div class="list-group list-group-flush">
                <?php if ($upcoming): ?>
                <?php foreach ($upcoming as $a): ?>
                <a href="<?= APP_URL ?>/medical-center/appointment-view.php?id=<?= $a['id'] ?>" class="list-group-item list-group-item-action py-2">
                    <div class="d-flex justify-content-between">
                        <span class="fw-semibold small"><?= h($a['patient_name']) ?></span>
                        <?= mc_status_badge($a['status']) ?>
                    </div>
                    <div class="text-muted small"><?= date('d M', strtotime($a['appointment_date'])) ?> at <?= date('h:i A', strtotime($a['appointment_time'])) ?></div>
                </a>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="list-group-item text-center text-muted py-3">No upcoming appointments.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header py-3">
                <span class="fw-semibold"><i class="fas fa-link me-2"></i>Quick Links</span>
            </div>
            <div class="list-group list-group-flush">
                <a href="<?= APP_URL ?>/medical-center/appointments.php" class="list-group-item list-group-item-action"><i class="fas fa-calendar-check me-2 text-primary"></i>All Appointments</a>
                <a href="<?= APP_URL ?>/medical-center/prescriptions.php" class="list-group-item list-group-item-action"><i class="fas fa-file-medical me-2 text-success"></i>Prescriptions</a>
                <a href="<?= APP_URL ?>/medical-center/medicines.php" class="list-group-item list-group-item-action"><i class="fas fa-pills me-2 text-danger"></i>Medicine Stock</a>
                <?php if (mc_can_edit()): ?>
                <a href="<?= APP_URL ?>/medical-center/settings.php" class="list-group-item list-group-item-action"><i class="fas fa-cog me-2 text-secondary"></i>Settings</a>
                <?php endif; ?>
                <a href="<?= SITE_URL ?>/medical-center.php" target="_blank" class="list-group-item list-group-item-action"><i class="fas fa-external-link-alt me-2 text-info"></i>Public Page</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
