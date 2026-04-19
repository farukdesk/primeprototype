<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('cert-verifiers');

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT cvl.*,
            s.full_name  AS s_full_name,
            s.student_id AS s_student_id,
            s.status     AS s_status,
            d.name       AS dept_name,
            p.program_name
     FROM cert_verification_log cvl
     LEFT JOIN students s ON s.id = cvl.student_id
     LEFT JOIN dept_departments d ON d.id = s.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = s.program_id
     WHERE cvl.id = ?'
);
$stmt->execute([$id]);
$rec = $stmt->fetch();

if (!$rec) {
    flash_set('error', 'Record not found.');
    redirect(APP_URL . '/cert-verifiers/index.php');
}

$page_title = 'Verification Request #' . $id;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/cert-verifiers/index.php">Cert. Verifiers</a></li>
            <li class="breadcrumb-item active">Request #<?= $id ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/cert-verifiers/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <?php if (is_super_admin() || can_access('cert-verifiers', 'can_delete')): ?>
        <form method="POST" action="<?= APP_URL ?>/cert-verifiers/delete.php" class="d-inline"
              onsubmit="return confirm('Delete this record permanently?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-sm btn-outline-danger" style="border-radius:8px;">
                <i class="fas fa-trash me-1"></i> Delete
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<div class="row g-4">

    <!-- Left: Verifier details -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <?php if ($rec['verifier_type'] === 'company'): ?>
                    <i class="fas fa-building me-2 text-muted"></i>Company Verifier
                    <?php else: ?>
                    <i class="fas fa-user-graduate me-2 text-muted"></i>Student Verifier
                    <?php endif; ?>
                </h6>
            </div>
            <div class="card-body px-4 py-3">
                <dl class="row g-0 mb-0">
                    <dt class="col-5 text-muted" style="font-size:.82rem;">Name</dt>
                    <dd class="col-7 fw-medium" style="font-size:.9rem;"><?= h($rec['verifier_name']) ?></dd>

                    <dt class="col-5 text-muted" style="font-size:.82rem;">Email</dt>
                    <dd class="col-7" style="font-size:.9rem;">
                        <a href="mailto:<?= h($rec['verifier_email']) ?>"><?= h($rec['verifier_email']) ?></a>
                    </dd>

                    <dt class="col-5 text-muted" style="font-size:.82rem;">Phone</dt>
                    <dd class="col-7" style="font-size:.9rem;"><?= h($rec['verifier_phone']) ?></dd>

                    <?php if ($rec['verifier_type'] === 'company'): ?>
                    <dt class="col-5 text-muted pt-2" style="font-size:.82rem;">Company</dt>
                    <dd class="col-7 pt-2 fw-medium" style="font-size:.9rem;"><?= h($rec['company_name'] ?? '—') ?></dd>

                    <dt class="col-5 text-muted" style="font-size:.82rem;">Designation</dt>
                    <dd class="col-7" style="font-size:.9rem;"><?= h($rec['verifier_designation'] ?? '—') ?></dd>

                    <dt class="col-5 text-muted" style="font-size:.82rem;">Address</dt>
                    <dd class="col-7" style="font-size:.9rem; white-space:pre-wrap;"><?= h($rec['company_address'] ?? '—') ?></dd>
                    <?php endif; ?>

                    <dt class="col-5 text-muted pt-2" style="font-size:.82rem;">IP Address</dt>
                    <dd class="col-7 pt-2" style="font-size:.9rem;"><code><?= h($rec['ip_address'] ?? '—') ?></code></dd>

                    <dt class="col-5 text-muted" style="font-size:.82rem;">Date / Time</dt>
                    <dd class="col-7" style="font-size:.9rem;">
                        <?= date('d M Y, H:i', strtotime($rec['created_at'])) ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Right: Student lookup result -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-muted"></i>Queried Student</h6>
            </div>
            <div class="card-body px-4 py-3">
                <div class="mb-3">
                    <span class="text-muted" style="font-size:.8rem;">Student ID Entered</span>
                    <div><code style="font-size:1rem;"><?= h($rec['queried_student_id']) ?></code></div>
                </div>

                <?php if ($rec['student_found']): ?>
                <div class="p-3 rounded-3 border border-success-subtle mb-3" style="background:#f0fff4;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="fas fa-check-circle text-success"></i>
                        <strong class="text-success">Student Record Found</strong>
                    </div>
                    <dl class="row g-0 mb-0">
                        <dt class="col-5 text-muted" style="font-size:.82rem;">Full Name</dt>
                        <dd class="col-7 fw-medium" style="font-size:.9rem;"><?= h($rec['s_full_name'] ?? '—') ?></dd>

                        <dt class="col-5 text-muted" style="font-size:.82rem;">Student ID</dt>
                        <dd class="col-7" style="font-size:.9rem;"><code><?= h($rec['s_student_id'] ?? '—') ?></code></dd>

                        <dt class="col-5 text-muted" style="font-size:.82rem;">Department</dt>
                        <dd class="col-7" style="font-size:.9rem;"><?= h($rec['dept_name'] ?? '—') ?></dd>

                        <?php if ($rec['program_name']): ?>
                        <dt class="col-5 text-muted" style="font-size:.82rem;">Program</dt>
                        <dd class="col-7" style="font-size:.9rem;"><?= h($rec['program_name']) ?></dd>
                        <?php endif; ?>

                        <dt class="col-5 text-muted" style="font-size:.82rem;">Status</dt>
                        <dd class="col-7" style="font-size:.9rem;"><?= h($rec['s_status'] ?? '—') ?></dd>
                    </dl>
                    <?php if ($rec['student_id']): ?>
                    <div class="mt-2">
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$rec['student_id'] ?>"
                           class="btn btn-sm btn-outline-primary" style="border-radius:7px;font-size:.8rem;">
                            <i class="fas fa-external-link-alt me-1"></i> View Student Profile
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="p-3 rounded-3 border border-danger-subtle" style="background:#fff5f5;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-times-circle text-danger"></i>
                        <strong class="text-danger">Student Not Found</strong>
                    </div>
                    <p class="mb-0 mt-1 text-muted" style="font-size:.85rem;">
                        No student record matched the ID <code><?= h($rec['queried_student_id']) ?></code>.
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
