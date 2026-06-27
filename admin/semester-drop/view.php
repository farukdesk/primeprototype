<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('semester-drop');
require_once __DIR__ . '/helpers.php';

$id   = (int)($_GET['id'] ?? 0);
$drop = sd_get_drop($id);

if (!$drop) {
    flash_set('error', 'Semester drop record not found.');
    redirect(APP_URL . '/semester-drop/index.php');
}

$page_title = 'Semester Drop – ' . $drop['student_name'];
$today      = date('Y-m-d');
$is_current = $drop['status'] === 'active'
    && $drop['drop_start'] <= $today && $today <= $drop['drop_end'];

require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.83rem;">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/semester-drop/index.php">Semester Drop</a></li>
        <li class="breadcrumb-item active"><?= h($drop['student_name']) ?></li>
    </ol>
</nav>

<?= flash_show() ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h4 mb-0">
        <i class="fas fa-pause-circle me-2 text-warning"></i><?= h($drop['student_name']) ?>
        <small class="text-muted">(<?= h($drop['student_sid']) ?>)</small>
    </h1>
    <div class="d-flex gap-2">
        <?= sd_status_badge($drop['status']) ?>
        <?php if ($is_current): ?>
        <span class="badge bg-warning text-dark"><i class="fas fa-circle me-1" style="font-size:.5rem;"></i>On break now</span>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header py-3"><h6 class="mb-0 fw-semibold">Drop Details</h6></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">Student</dt>
                    <dd class="col-sm-8">
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$drop['student_id'] ?>">
                            <?= h($drop['student_name']) ?>
                        </a> <span class="text-muted small">(<?= h($drop['student_sid']) ?>)</span>
                    </dd>

                    <dt class="col-sm-4 text-muted">Semester Type</dt>
                    <dd class="col-sm-8">
                        <span class="badge bg-info text-dark"><?= h(sd_type_label($drop['semester_type'])) ?></span>
                        <span class="text-muted small">blocks <?= (int)$drop['block_months'] ?> months</span>
                    </dd>

                    <dt class="col-sm-4 text-muted">Drop Start</dt>
                    <dd class="col-sm-8"><?= h(date('d M Y', strtotime($drop['drop_start']))) ?></dd>

                    <dt class="col-sm-4 text-muted">Drop End</dt>
                    <dd class="col-sm-8"><?= h(date('d M Y', strtotime($drop['drop_end']))) ?></dd>

                    <dt class="col-sm-4 text-muted">Reason</dt>
                    <dd class="col-sm-8"><?= $drop['reason'] ? nl2br(h($drop['reason'])) : '<span class="text-muted">—</span>' ?></dd>

                    <dt class="col-sm-4 text-muted">Recorded by</dt>
                    <dd class="col-sm-8"><?= h($drop['created_by_name'] ?? '—') ?>
                        <span class="text-muted small">on <?= h(date('d M Y, g:i a', strtotime($drop['created_at']))) ?></span>
                    </dd>

                    <?php if ($drop['status'] === 'cancelled'): ?>
                    <dt class="col-sm-4 text-muted">Cancelled by</dt>
                    <dd class="col-sm-8"><?= h($drop['cancelled_by_name'] ?? '—') ?>
                        <?php if ($drop['cancelled_at']): ?>
                        <span class="text-muted small">on <?= h(date('d M Y, g:i a', strtotime($drop['cancelled_at']))) ?></span>
                        <?php endif; ?>
                        <?php if ($drop['cancel_reason']): ?>
                        <div class="small text-muted mt-1"><?= nl2br(h($drop['cancel_reason'])) ?></div>
                        <?php endif; ?>
                    </dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header py-3"><h6 class="mb-0 fw-semibold">Evidence</h6></div>
            <div class="card-body">
                <?php if ($drop['evidence_stored_name']): ?>
                <a href="<?= sd_evidence_url($drop['evidence_stored_name']) ?>" target="_blank"
                   class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-paperclip me-1"></i>
                    <?= h($drop['evidence_original_name'] ?: 'View evidence') ?>
                </a>
                <?php else: ?>
                <p class="text-muted mb-0"><i class="fas fa-info-circle me-1"></i>No evidence attached (recorded without evidence).</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($drop['status'] === 'active' && sd_can_delete()): ?>
        <div class="card border-danger">
            <div class="card-header py-3 bg-danger-subtle"><h6 class="mb-0 fw-semibold text-danger">Cancel Drop</h6></div>
            <div class="card-body">
                <p class="small text-muted">Cancelling restores normal monthly dues for the blocked months.</p>
                <form method="post" action="<?= APP_URL ?>/semester-drop/cancel.php"
                      onsubmit="return confirm('Cancel this semester drop? Monthly dues for the blocked months will be restored.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$drop['id'] ?>">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Cancellation reason <span class="text-muted">(optional)</span></label>
                        <textarea name="cancel_reason" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <button class="btn btn-danger btn-sm"><i class="fas fa-ban me-1"></i>Cancel Drop</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
