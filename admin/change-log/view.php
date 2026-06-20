<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_super_admin();
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Invalid log entry ID.');
    redirect(APP_URL . '/change-log/index.php');
}

$stmt = db()->prepare(
    'SELECT cl.*, u.full_name, u.email
     FROM change_log cl
     JOIN users u ON u.id = cl.user_id
     WHERE cl.id = ?'
);
$stmt->execute([$id]);
$log = $stmt->fetch();

if (!$log) {
    flash_set('error', 'Log entry not found.');
    redirect(APP_URL . '/change-log/index.php');
}

$page_title = 'Change Log – Entry #' . $id;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/change-log/index.php">Change Log</a></li>
            <li class="breadcrumb-item active">Entry #<?= $id ?></li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/change-log/index.php" class="btn btn-outline-secondary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-arrow-left me-1"></i> Back to Log
    </a>
</div>

<div class="row g-4">
    <!-- Main detail card -->
    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;">
            <div class="card-header d-flex align-items-center gap-3 py-3 px-4">
                <div>
                    <h5 class="mb-0 fw-semibold" style="font-size:1rem;">Log Entry #<?= $id ?></h5>
                    <div class="text-muted" style="font-size:.78rem;">
                        <?= date('l, F j, Y \a\t H:i:s', strtotime($log['created_at'])) ?>
                    </div>
                </div>
                <div class="ms-auto"><?= cl_action_badge($log['action']) ?></div>
            </div>
            <div class="card-body px-4 py-3">

                <table class="table table-borderless mb-0" style="font-size:.875rem;">
                    <tbody>
                        <tr>
                            <th class="ps-0 text-muted" style="width:160px;font-weight:600;">Module</th>
                            <td>
                                <span class="badge bg-light text-dark border"><?= h($log['module']) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th class="ps-0 text-muted" style="font-weight:600;">Action</th>
                            <td><?= cl_action_badge($log['action']) ?></td>
                        </tr>
                        <?php if ($log['record_id'] || $log['record_label']): ?>
                        <tr>
                            <th class="ps-0 text-muted" style="font-weight:600;">Record</th>
                            <td>
                                <?php if ($log['record_label']): ?>
                                    <?= h($log['record_label']) ?>
                                    <?php if ($log['record_id']): ?>
                                    <span class="text-muted ms-1" style="font-size:.78rem;">(ID: <?= (int)$log['record_id'] ?>)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">#<?= (int)$log['record_id'] ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($log['field_name']): ?>
                        <tr>
                            <th class="ps-0 text-muted" style="font-weight:600;">Field Changed</th>
                            <td><code><?= h($log['field_name']) ?></code></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($log['description']): ?>
                        <tr>
                            <th class="ps-0 text-muted" style="font-weight:600;">Description</th>
                            <td><?= h($log['description']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Value comparison -->
                <?php if ($log['old_value'] !== null || $log['new_value'] !== null): ?>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <div class="p-3 rounded" style="background:#fff5f5;border:1px solid #fecaca;">
                            <div class="fw-semibold mb-2" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:#dc2626;">
                                <i class="fas fa-minus-circle me-1"></i> Previous Value
                            </div>
                            <?php if ($log['old_value'] !== null): ?>
                            <pre class="mb-0" style="white-space:pre-wrap;word-break:break-word;font-size:.83rem;color:#7f1d1d;background:none;border:none;padding:0;"><?= h($log['old_value']) ?></pre>
                            <?php else: ?>
                            <em class="text-muted" style="font-size:.83rem;">empty</em>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                            <div class="fw-semibold mb-2" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:#16a34a;">
                                <i class="fas fa-plus-circle me-1"></i> New Value
                            </div>
                            <?php if ($log['new_value'] !== null): ?>
                            <pre class="mb-0" style="white-space:pre-wrap;word-break:break-word;font-size:.83rem;color:#14532d;background:none;border:none;padding:0;"><?= h($log['new_value']) ?></pre>
                            <?php else: ?>
                            <em class="text-muted" style="font-size:.83rem;">empty</em>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- Meta sidebar -->
    <div class="col-lg-4">

        <!-- User card -->
        <div class="card mb-3" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold" style="font-size:.875rem;">
                    <i class="fas fa-user me-2 text-muted"></i>Changed By
                </h6>
            </div>
            <div class="card-body px-4 py-3">
                <div class="fw-semibold" style="font-size:.95rem;"><?= h($log['full_name']) ?></div>
                <div class="text-muted" style="font-size:.83rem;"><?= h($log['email']) ?></div>
                <div class="mt-2">
                    <a href="<?= APP_URL ?>/users/edit.php?id=<?= (int)$log['user_id'] ?>"
                       class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.78rem;">
                        <i class="fas fa-user-edit me-1"></i> View User
                    </a>
                </div>
            </div>
        </div>

        <!-- Meta card -->
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold" style="font-size:.875rem;">
                    <i class="fas fa-info-circle me-2 text-muted"></i>Details
                </h6>
            </div>
            <div class="card-body px-4 py-3">
                <table class="table table-borderless mb-0" style="font-size:.83rem;">
                    <tr>
                        <th class="ps-0 text-muted" style="font-weight:600;width:100px;">Entry ID</th>
                        <td><?= $id ?></td>
                    </tr>
                    <tr>
                        <th class="ps-0 text-muted" style="font-weight:600;">IP Address</th>
                        <td><code style="font-size:.8rem;"><?= $log['ip_address'] ? h($log['ip_address']) : '—' ?></code></td>
                    </tr>
                    <tr>
                        <th class="ps-0 text-muted" style="font-weight:600;">Date</th>
                        <td><?= date('M d, Y', strtotime($log['created_at'])) ?></td>
                    </tr>
                    <tr>
                        <th class="ps-0 text-muted" style="font-weight:600;">Time</th>
                        <td><?= date('H:i:s', strtotime($log['created_at'])) ?></td>
                    </tr>
                </table>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
