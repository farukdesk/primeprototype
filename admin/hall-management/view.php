<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('hall-management');
require_once __DIR__ . '/helpers.php';

$hall_id = (int)($_GET['id'] ?? 0);
$hall    = $hall_id > 0 ? hm_get_hall($hall_id) : null;
if (!$hall) {
    flash_set('error', 'Hall not found or you do not have permission to access it.');
    redirect(APP_URL . '/hall-management/index.php');
}

$page_title = 'Hall – ' . $hall['room_number'];
$columns    = hm_hall_columns($hall_id);
$can_edit   = is_super_admin() || can_access('hall-management', 'can_edit');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/hall-management/index.php">Hall Management</a></li>
            <li class="breadcrumb-item active"><?= h($hall['room_number']) ?></li>
        </ol>
    </nav>
    <?php if ($can_edit): ?>
    <a href="<?= APP_URL ?>/hall-management/edit.php?id=<?= $hall_id ?>" class="btn btn-sm btn-primary" style="border-radius:8px;">
        <i class="fas fa-edit me-1"></i> Edit Hall
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card" style="border-radius:12px;">
            <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">
                <i class="fas fa-door-open me-2 text-primary"></i>Hall Details
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0" style="font-size:.9rem;">
                    <tr><th class="text-muted" style="width:45%;">Room Number</th><td class="fw-semibold"><?= h($hall['room_number']) ?></td></tr>
                    <tr><th class="text-muted">Department</th><td><?= h($hall['dept_name']) ?></td></tr>
                    <tr><th class="text-muted">Columns</th><td><?= (int)$hall['num_columns'] ?></td></tr>
                    <tr><th class="text-muted">Rows</th><td><?= (int)$hall['num_rows'] ?></td></tr>
                    <tr><th class="text-muted">Total Seat Capacity</th><td><span class="badge bg-primary"><?= (int)$hall['total_capacity'] ?> seats</span></td></tr>
                    <tr><th class="text-muted">Status</th>
                        <td><?= (int)$hall['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td></tr>
                    <?php if (!empty($hall['notes'])): ?>
                    <tr><th class="text-muted">Notes</th><td><?= h($hall['notes']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;">
            <div class="card-header bg-white fw-semibold" style="border-radius:12px 12px 0 0;">
                <i class="fas fa-th me-2 text-primary"></i>Seat Layout
            </div>
            <div class="card-body">
                <?php if (empty($columns)): ?>
                <div class="text-center text-muted py-4">No seat columns defined for this hall.</div>
                <?php else: ?>
                <div class="text-center mb-3">
                    <span class="badge bg-dark px-4 py-2" style="font-size:.8rem;"><i class="fas fa-chalkboard me-1"></i> FRONT / BOARD</span>
                </div>
                <div class="d-flex gap-3 justify-content-center flex-wrap" style="overflow-x:auto;">
                    <?php foreach ($columns as $col): ?>
                    <div class="text-center">
                        <div class="fw-semibold mb-2" style="font-size:.8rem;color:#475569;">
                            Column <?= (int)$col['col_no'] ?>
                            <div class="text-muted" style="font-size:.7rem;"><?= (int)$col['seat_capacity'] ?> seats</div>
                        </div>
                        <div class="d-flex flex-column gap-1 align-items-center">
                            <?php for ($s = 1; $s <= (int)$col['seat_capacity']; $s++): ?>
                            <div title="Column <?= (int)$col['col_no'] ?>, Seat <?= $s ?>"
                                 style="width:34px;height:26px;border-radius:6px;background:#eef2ff;border:1px solid #c7d2fe;
                                        display:flex;align-items:center;justify-content:center;font-size:.65rem;color:#4338ca;">
                                <?= $s ?>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
