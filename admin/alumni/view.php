<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('alumni');

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare(
    'SELECT a.*, d.name AS dept_name
     FROM alumni a
     LEFT JOIN dept_departments d ON d.id = a.dept_id
     WHERE a.id = ?'
);
$st->execute([$id]);
$alumni = $st->fetch();
if (!$alumni) { flash_set('error', 'Alumni not found.'); redirect(APP_URL . '/alumni/index.php'); }

// Handle approve / reject / delete from this page
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'approve' && (is_super_admin() || can_access('alumni', 'can_edit'))) {
        db()->prepare("UPDATE alumni SET status='approved', is_active=1 WHERE id=?")->execute([$id]);
        flash_set('success', 'Alumni approved.');
        redirect(APP_URL . '/alumni/view.php?id=' . $id);
    }
    if ($action === 'reject' && (is_super_admin() || can_access('alumni', 'can_edit'))) {
        $notes = trim($_POST['admin_notes'] ?? '');
        db()->prepare("UPDATE alumni SET status='rejected', admin_notes=? WHERE id=?")
           ->execute([$notes ?: null, $id]);
        flash_set('success', 'Alumni rejected.');
        redirect(APP_URL . '/alumni/view.php?id=' . $id);
    }
    if ($action === 'delete' && (is_super_admin() || can_access('alumni', 'can_delete'))) {
        if ($alumni['photo']) {
            $p = UPLOAD_DIR . '/alumni/' . $alumni['photo'];
            if (file_exists($p)) @unlink($p);
        }
        db()->prepare('DELETE FROM alumni WHERE id=?')->execute([$id]);
        flash_set('success', 'Alumni deleted.');
        redirect(APP_URL . '/alumni/index.php');
    }
}

$page_title = 'View Alumni – ' . $alumni['name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/alumni/index.php">Alumni</a></li>
            <li class="breadcrumb-item active"><?= h($alumni['name']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if (is_super_admin() || can_access('alumni', 'can_edit')): ?>
        <a href="<?= APP_URL ?>/alumni/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/alumni/index.php" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Profile card -->
    <div class="col-lg-4">
        <div class="card text-center">
            <div class="card-body p-4">
                <?php if ($alumni['photo']): ?>
                <img src="<?= UPLOAD_URL ?>/alumni/<?= h($alumni['photo']) ?>"
                     alt="<?= h($alumni['name']) ?>"
                     style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid #002147;margin-bottom:16px;">
                <?php else: ?>
                <div style="width:120px;height:120px;border-radius:50%;background:#002147;color:#fff;
                    display:flex;align-items:center;justify-content:center;font-size:2.5rem;margin:0 auto 16px;">
                    <?= strtoupper(substr($alumni['name'], 0, 1)) ?>
                </div>
                <?php endif; ?>
                <h5 class="fw-bold mb-1" style="color:#002147;"><?= h($alumni['name']) ?></h5>
                <?php if ($alumni['position']): ?>
                <p class="mb-1 fw-medium" style="color:#D21034;"><?= h($alumni['position']) ?></p>
                <?php endif; ?>
                <?php if ($alumni['company']): ?>
                <p class="text-muted mb-2"><?= h($alumni['company']) ?></p>
                <?php endif; ?>
                <?php if ($alumni['batch']): ?>
                <span class="badge bg-primary bg-opacity-10 text-primary">Batch: <?= h($alumni['batch']) ?></span>
                <?php endif; ?>
                <div class="mt-3 d-flex justify-content-center gap-3">
                    <?php if ($alumni['linkedin_url']): ?>
                    <a href="<?= h($alumni['linkedin_url']) ?>" target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                        <i class="fab fa-linkedin me-1"></i> LinkedIn
                    </a>
                    <?php endif; ?>
                    <?php if ($alumni['fb_url']): ?>
                    <a href="<?= h($alumni['fb_url']) ?>" target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                        <i class="fab fa-facebook me-1"></i> Facebook
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Details -->
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Details</h6>
            </div>
            <div class="card-body p-4">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted fw-normal">Department</dt>
                    <dd class="col-sm-8"><?= h($alumni['dept_name'] ?? '—') ?></dd>
                    <dt class="col-sm-4 text-muted fw-normal">Student ID</dt>
                    <dd class="col-sm-8"><?= h($alumni['student_id'] ?? '—') ?></dd>
                    <dt class="col-sm-4 text-muted fw-normal">Batch</dt>
                    <dd class="col-sm-8"><?= h($alumni['batch'] ?? '—') ?></dd>
                    <dt class="col-sm-4 text-muted fw-normal">Phone</dt>
                    <dd class="col-sm-8"><?= $alumni['phone'] ? h($alumni['phone']) : '—' ?></dd>
                    <dt class="col-sm-4 text-muted fw-normal">Email</dt>
                    <dd class="col-sm-8"><?= $alumni['email'] ? '<a href="mailto:'.h($alumni['email']).'">'.h($alumni['email']).'</a>' : '—' ?></dd>
                    <dt class="col-sm-4 text-muted fw-normal">Company</dt>
                    <dd class="col-sm-8"><?= h($alumni['company'] ?? '—') ?></dd>
                    <dt class="col-sm-4 text-muted fw-normal">Position</dt>
                    <dd class="col-sm-8"><?= h($alumni['position'] ?? '—') ?></dd>
                    <dt class="col-sm-4 text-muted fw-normal">Status</dt>
                    <dd class="col-sm-8">
                        <?php $sc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger']; ?>
                        <span class="badge bg-<?= $sc[$alumni['status']] ?? 'secondary' ?>">
                            <?= ucfirst(h($alumni['status'])) ?>
                        </span>
                    </dd>
                    <dt class="col-sm-4 text-muted fw-normal">Active</dt>
                    <dd class="col-sm-8">
                        <span class="badge <?= $alumni['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                            <?= $alumni['is_active'] ? 'Yes' : 'No' ?>
                        </span>
                    </dd>
                    <dt class="col-sm-4 text-muted fw-normal">Submitted</dt>
                    <dd class="col-sm-8"><?= h($alumni['created_at']) ?></dd>
                    <?php if ($alumni['admin_notes']): ?>
                    <dt class="col-sm-4 text-muted fw-normal">Admin Notes</dt>
                    <dd class="col-sm-8 text-danger"><?= h($alumni['admin_notes']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if ($alumni['status'] === 'pending' && (is_super_admin() || can_access('alumni', 'can_edit'))): ?>
        <!-- Approval actions -->
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-tasks me-2 text-muted"></i>Approval Actions</h6>
            </div>
            <div class="card-body p-4">
                <div class="d-flex gap-3 flex-wrap">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="approve">
                        <button class="btn btn-success" style="border-radius:10px;">
                            <i class="fas fa-check me-1"></i> Approve
                        </button>
                    </form>
                    <button class="btn btn-danger" style="border-radius:10px;"
                            data-bs-toggle="collapse" data-bs-target="#rejectForm">
                        <i class="fas fa-times me-1"></i> Reject
                    </button>
                </div>
                <div class="collapse mt-3" id="rejectForm">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="reject">
                        <label class="form-label fw-medium">Rejection Reason (optional)</label>
                        <textarea name="admin_notes" class="form-control mb-2" rows="2"
                                  style="border-radius:10px;" placeholder="Reason…"></textarea>
                        <button type="submit" class="btn btn-danger" style="border-radius:10px;">
                            <i class="fas fa-times me-1"></i> Confirm Reject
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
