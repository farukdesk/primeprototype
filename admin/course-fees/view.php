<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees');

$id   = (int)($_GET['id'] ?? 0);
$prog = cf_get_program($id);
if (!$prog) { flash_set('error', 'Fee structure not found.'); redirect(APP_URL . '/course-fees/index.php'); }

$page_title = 'Fee Structure – ' . cf_program_label($prog);
$db         = db();

$fixed_fees = cf_get_fixed_fees($id);
$settings   = cf_get_settings();
$currency   = $settings['currency'] ?? 'BDT';

// ── Calculate totals ──────────────────────────────────────────────────────────
$one_time_total    = 0;
$per_semester_total = 0;
foreach ($fixed_fees as $f) {
    if ($f['fee_type'] === 'one_time') {
        $one_time_total += (int)$f['amount'];
    } else {
        $per_semester_total += (int)$f['amount'];
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">
            <i class="fas fa-calculator me-2 text-warning"></i>
            <?= h(cf_program_label($prog)) ?>
            <?= cf_degree_badge($prog['degree_type']) ?>
        </h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-fees/index.php">Course Fees</a></li>
            <li class="breadcrumb-item active">View</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <?php if (cf_can_edit()): ?>
        <a href="<?= APP_URL ?>/course-fees/edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-pencil me-1"></i> Edit
        </a>
        <?php endif; ?>
        <?php if (cf_can_delete()): ?>
        <a href="<?= APP_URL ?>/course-fees/delete.php?id=<?= $id ?>" class="btn btn-danger btn-sm"
           onclick="return confirm('Delete this fee structure?')">
            <i class="fas fa-trash me-1"></i> Delete
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/course-fees/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<?= flash_show() ?>

<div class="row g-4">
    <!-- Details -->
    <div class="col-lg-8">
        <!-- Summary Card -->
        <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#1a2e5a,#2563eb);color:#fff;border-radius:16px;">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col">
                        <div class="small opacity-75 mb-1">
                            <?= h($prog['dept_name'] ?? 'Department N/A') ?> &nbsp;·&nbsp; <?= h(cf_degree_label($prog['degree_type'])) ?>
                            <?php if ($prog['duration_years']): ?>&nbsp;·&nbsp; <?= h($prog['duration_years']) ?> yrs<?php endif; ?>
                            <?php if ($prog['total_credits']): ?>&nbsp;·&nbsp; <?= (int)$prog['total_credits'] ?> credits<?php endif; ?>
                        </div>
                        <h2 class="mb-0 fw-bold"><?= h($prog['program_name'] ?: ($prog['dept_name'] ?: '—')) ?></h2>
                    </div>
                    <div class="col-auto text-end">
                        <div class="small opacity-75">Per Credit Hour</div>
                        <div style="font-size:2rem;font-weight:900;"><?= cf_money((int)$prog['credit_fee'], $currency) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fixed Fees Table -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header fw-semibold py-3">
                <i class="fas fa-list-ul me-2 text-info"></i>Additional & Fixed Fees
            </div>
            <?php if (empty($fixed_fees)): ?>
            <div class="card-body text-center text-muted py-4">No additional fees configured.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-4">Fee Name</th>
                            <th>Amount</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fixed_fees as $f): ?>
                    <tr>
                        <td class="px-4 fw-semibold"><?= h($f['fee_name']) ?></td>
                        <td><?= cf_money((int)$f['amount'], $currency) ?></td>
                        <td>
                            <?php if ($f['fee_type'] === 'one_time'): ?>
                                <span class="badge bg-secondary">One-Time</span>
                            <?php else: ?>
                                <span class="badge bg-info text-dark">Per Semester</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-semibold">
                        <tr>
                            <td class="px-4">One-Time Total</td>
                            <td colspan="2"><?= cf_money($one_time_total, $currency) ?></td>
                        </tr>
                        <tr>
                            <td class="px-4">Per-Semester Total</td>
                            <td colspan="2"><?= cf_money($per_semester_total, $currency) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header fw-semibold py-3"><i class="fas fa-info-circle me-2"></i>Details</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted" style="width:40%">Status</th>
                        <td><?= $prog['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td></tr>
                    <tr><th class="text-muted">Sort Order</th><td><?= (int)$prog['sort_order'] ?></td></tr>
                    <tr><th class="text-muted">Degree</th><td><?= cf_degree_badge($prog['degree_type']) ?></td></tr>
                    <tr><th class="text-muted">Credits</th><td><?= $prog['total_credits'] ? (int)$prog['total_credits'] . ' credits' : '—' ?></td></tr>
                    <tr><th class="text-muted">Duration</th><td><?= $prog['duration_years'] ? h($prog['duration_years']) . ' years' : '—' ?></td></tr>
                    <tr><th class="text-muted">Credit Fee</th><td class="fw-bold"><?= cf_money((int)$prog['credit_fee'], $currency) ?></td></tr>
                    <tr><th class="text-muted">Created</th><td><?= date('d M Y', strtotime($prog['created_at'])) ?></td></tr>
                    <tr><th class="text-muted">Updated</th><td><?= date('d M Y', strtotime($prog['updated_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold py-3"><i class="fas fa-link me-2"></i>Public Page</div>
            <div class="card-body">
                <a href="<?= SITE_URL ?>/course-fees-calculator.php" target="_blank" class="btn btn-outline-warning w-100">
                    <i class="fas fa-external-link-alt me-1"></i> View Calculator
                </a>
                <p class="small text-muted mt-2 mb-0">This record appears on the public calculator if marked Active.</p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
