<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('policy-procedure');
require_once __DIR__ . '/helpers.php';

$page_title = 'Policy & Procedure';

$sections = db()->query(
    'SELECT * FROM policy_procedure_sections ORDER BY sort_order, id'
)->fetchAll();

$total   = count($sections);
$active  = array_sum(array_column($sections, 'is_active'));
$inactive = $total - $active;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Policy &amp; Procedure</li>
        </ol>
    </nav>
    <?php if (pp_can_create()): ?>
    <a href="<?= APP_URL ?>/policy-procedure/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Section
    </a>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#4f8ef7"><?= $total ?></div>
            <div class="text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">Total Sections</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#2ecc71"><?= $active ?></div>
            <div class="text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">Active</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center py-3">
            <div style="font-size:1.8rem;font-weight:700;color:#e74c3c"><?= $inactive ?></div>
            <div class="text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">Inactive</div>
        </div>
    </div>
</div>

<?php if ($flash = flash('success')): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i><?= h($flash) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($flash = flash('error')): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i><?= h($flash) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:50px;">#</th>
                        <th>Section Title</th>
                        <th style="width:100px;">Order</th>
                        <th style="width:100px;">Status</th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sections)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No sections found. <a href="<?= APP_URL ?>/policy-procedure/create.php">Add the first section</a>.</td></tr>
                <?php else: ?>
                    <?php foreach ($sections as $i => $s): ?>
                    <tr>
                        <td class="px-4 text-muted"><?= $i + 1 ?></td>
                        <td>
                            <strong><?= h($s['title']) ?></strong>
                            <div class="text-muted" style="font-size:.78rem;">Added <?= date('d M Y', strtotime($s['created_at'])) ?></div>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= (int)$s['sort_order'] ?></span></td>
                        <td>
                            <?php if ($s['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if (pp_can_edit()): ?>
                                <a href="<?= APP_URL ?>/policy-procedure/edit.php?id=<?= $s['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (pp_can_delete()): ?>
                                <form method="POST" action="<?= APP_URL ?>/policy-procedure/delete.php"
                                      onsubmit="return confirm('Delete this section? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<p class="text-muted mt-3" style="font-size:.8rem;"><i class="fas fa-info-circle me-1"></i>Sections are displayed on the public page in order of their <strong>Order</strong> value (lower = first).</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
