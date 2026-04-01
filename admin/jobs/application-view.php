<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('jobs');

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT a.*, j.title AS job_title, j.slug AS job_slug
     FROM job_applications a
     JOIN jobs j ON j.id = a.job_id
     WHERE a.id = ?'
);
$stmt->execute([$id]);
$app = $stmt->fetch();

if (!$app) {
    flash_set('error', 'Application not found.');
    redirect(APP_URL . '/jobs/applications.php');
}

$page_title = 'Application: ' . $app['full_name'];

$status_badge = match($app['status']) {
    'pending'     => 'bg-warning text-dark',
    'reviewing'   => 'bg-info text-dark',
    'shortlisted' => 'bg-success',
    'rejected'    => 'bg-danger',
    default       => 'bg-secondary',
};

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/jobs/index.php">Jobs</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/jobs/applications.php">Applications</a></li>
            <li class="breadcrumb-item active">View Application</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/jobs/applications.php?job_id=<?= $app['job_id'] ?>"
       class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Applications
    </a>
</div>

<div class="row g-4">
    <!-- Left: Application details -->
    <div class="col-lg-8">

        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-user me-2 text-muted"></i>Applicant Details</h6>
            </div>
            <div class="card-body p-4">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Full Name</dt>
                    <dd class="col-sm-8"><?= h($app['full_name']) ?></dd>

                    <dt class="col-sm-4 text-muted">Email</dt>
                    <dd class="col-sm-8"><a href="mailto:<?= h($app['email']) ?>"><?= h($app['email']) ?></a></dd>

                    <dt class="col-sm-4 text-muted">Phone</dt>
                    <dd class="col-sm-8">
                        <?= $app['phone'] ? h($app['phone']) : '<span class="text-muted">—</span>' ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">Job Applied For</dt>
                    <dd class="col-sm-8">
                        <a href="<?= APP_URL ?>/jobs/edit.php?id=<?= $app['job_id'] ?>">
                            <?= h($app['job_title']) ?>
                        </a>
                    </dd>

                    <dt class="col-sm-4 text-muted">Status</dt>
                    <dd class="col-sm-8">
                        <span class="badge <?= $status_badge ?>"><?= ucfirst(h($app['status'])) ?></span>
                    </dd>

                    <dt class="col-sm-4 text-muted">Applied At</dt>
                    <dd class="col-sm-8"><?= date('d M Y, H:i', strtotime($app['applied_at'])) ?></dd>

                    <?php if ($app['updated_at'] !== $app['applied_at']): ?>
                    <dt class="col-sm-4 text-muted">Last Updated</dt>
                    <dd class="col-sm-8"><?= date('d M Y, H:i', strtotime($app['updated_at'])) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if (!empty($app['cover_letter'])): ?>
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-file-lines me-2 text-muted"></i>Cover Letter</h6>
            </div>
            <div class="card-body p-4">
                <p class="mb-0" style="white-space:pre-wrap;line-height:1.7;"><?= nl2br(h($app['cover_letter'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($app['cv_filename'])): ?>
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-file-pdf me-2 text-muted"></i>CV / Résumé</h6>
            </div>
            <div class="card-body p-4">
                <a href="<?= UPLOAD_URL ?>/jobs/<?= h($app['cv_filename']) ?>"
                   download="<?= h($app['cv_original_name'] ?: $app['cv_filename']) ?>"
                   class="btn btn-outline-primary" style="border-radius:10px;" target="_blank" rel="noopener">
                    <i class="fas fa-download me-2"></i><?= h($app['cv_original_name'] ?: $app['cv_filename']) ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Right: Status update -->
    <div class="col-lg-4">

        <?php if (can_access('jobs', 'can_edit')): ?>
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-tag me-2 text-muted"></i>Update Status</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= APP_URL ?>/jobs/application-status.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $app['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Status</label>
                        <select name="status" class="form-select">
                            <option value="pending"     <?= $app['status'] === 'pending'     ? 'selected' : '' ?>>Pending</option>
                            <option value="reviewing"   <?= $app['status'] === 'reviewing'   ? 'selected' : '' ?>>Reviewing</option>
                            <option value="shortlisted" <?= $app['status'] === 'shortlisted' ? 'selected' : '' ?>>Shortlisted</option>
                            <option value="rejected"    <?= $app['status'] === 'rejected'    ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-check me-1"></i> Save Status
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (can_access('jobs', 'can_delete')): ?>
        <div class="card mt-3" style="border-radius:12px;">
            <div class="card-body p-4">
                <form method="POST" action="<?= APP_URL ?>/jobs/application-delete.php"
                      onsubmit="return confirm('Permanently delete this application?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $app['id'] ?>">
                    <div class="d-grid">
                        <button class="btn btn-outline-danger" style="border-radius:10px;">
                            <i class="fas fa-trash me-1"></i> Delete Application
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
