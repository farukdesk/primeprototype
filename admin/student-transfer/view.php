<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-transfer');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../student-accounts/helpers.php'; // sfp_package_payment_count()

$id = (int)($_GET['id'] ?? 0);
$transfer = stt_get_transfer($id);
if (!$transfer) {
    flash_set('error', 'Transfer record not found.');
    redirect(APP_URL . '/student-transfer/index.php');
}

$me      = auth_user();
$is_dept = $transfer['kind'] === 'department';

// ── Handle the fee-package decision actions (department transfers only) ──────
// These mutate data, so they require create-level access even though viewing
// the record itself only requires can_view.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_dept) {
    require_access('student-transfer', 'can_create');
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'end_package') {
        $result = stt_try_end_package($id, (int)$transfer['student_id'], (int)$me['id']);
        flash_set($result['ok'] ? 'success' : 'error', $result['message']);
        redirect(APP_URL . '/student-transfer/view.php?id=' . $id);
    } elseif ($action === 'dismiss_package') {
        stt_dismiss_package($id);
        flash_set('success', 'Marked as reviewed — the existing package is unchanged.');
        redirect(APP_URL . '/student-transfer/view.php?id=' . $id);
    } elseif ($action === 'reopen_package') {
        stt_reopen_package($id);
        redirect(APP_URL . '/student-transfer/view.php?id=' . $id);
    } elseif ($action === 'goto_assign_package') {
        save_old(['student_id' => (int)$transfer['student_id'], 'student_label' => $transfer['student_name'] . ' (' . $transfer['student_sid'] . ')']);
        redirect(APP_URL . '/student-accounts/create.php');
    }
    redirect(APP_URL . '/student-transfer/view.php?id=' . $id);
}

$page_title = 'Transfer #' . $id;

// Fee package summary for department transfers with a snapshotted package.
$package       = null;
$payment_count = 0;
if ($is_dept && !empty($transfer['old_package_id'])) {
    $pkg_stmt = db()->prepare(
        'SELECT id, program_name, payment_type, monthly_payment, tuition_per_semester,
                total_semesters, total_months, created_at
           FROM sfp_packages WHERE id = ?'
    );
    $pkg_stmt->execute([$transfer['old_package_id']]);
    $package = $pkg_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($package) {
        $payment_count = sfp_package_payment_count((int)$package['id']);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.83rem;">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-transfer/index.php">Student Transfer</a></li>
        <li class="breadcrumb-item active">Transfer #<?= (int)$transfer['id'] ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <h1 class="h3 mb-0"><i class="fas fa-exchange-alt me-2 text-primary"></i>Transfer #<?= (int)$transfer['id'] ?></h1>
    <?= stt_kind_badge($transfer['kind']) ?>
</div>

<?= flash_show() ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-body">
                <div class="mb-3">
                    <div class="text-muted small">Student</div>
                    <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$transfer['student_id'] ?>" class="fw-semibold fs-5 text-decoration-none">
                        <?= h($transfer['student_name']) ?>
                    </a>
                    <span class="text-muted">(<?= h($transfer['student_sid']) ?>)</span>
                </div>

                <?php if ($is_dept): ?>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Department</div>
                        <div><?= h($transfer['from_dept_name'] ?? '—') ?> <i class="fas fa-arrow-right mx-1 text-muted"></i> <strong><?= h($transfer['to_dept_name'] ?? '—') ?></strong></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Program</div>
                        <div><?= h($transfer['from_program_name'] ?? '— None —') ?> <i class="fas fa-arrow-right mx-1 text-muted"></i> <strong><?= h($transfer['to_program_name'] ?? '— None —') ?></strong></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Student ID</div>
                        <?php if ($transfer['old_student_id'] !== $transfer['new_student_id']): ?>
                        <div><?= h($transfer['old_student_id']) ?> <i class="fas fa-arrow-right mx-1 text-muted"></i> <strong class="text-warning"><?= h($transfer['new_student_id']) ?></strong></div>
                        <?php else: ?>
                        <div><strong><?= h($transfer['new_student_id']) ?></strong> <span class="text-muted">(unchanged)</span></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Batch</div>
                        <div><?= h($transfer['from_batch_name'] ?? '— None —') ?> <i class="fas fa-arrow-right mx-1 text-muted"></i> <strong><?= h($transfer['to_batch_name'] ?? '—') ?></strong></div>
                    </div>
                </div>
                <?php endif; ?>

                <hr>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Reason</div>
                        <div><?= $transfer['reason'] ? nl2br(h($transfer['reason'])) : '<span class="text-muted">— None given —</span>' ?></div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-muted small">Recorded by</div>
                        <div><?= h($transfer['created_by_name'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-3">
                        <div class="text-muted small">Date</div>
                        <div><?= h(date('d M Y, h:i A', strtotime($transfer['created_at']))) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($is_dept): ?>
        <!-- ── Fee Package decision ── -->
        <div class="card">
            <div class="card-header py-3 fw-semibold">
                <i class="fas fa-file-invoice-dollar me-2 text-success"></i>Fee Package
            </div>
            <div class="card-body">
                <?php if (!$package): ?>
                    <p class="text-muted mb-3">This student had no fee package on file at the time of the transfer.</p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="goto_assign_package">
                        <button type="submit" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-plus me-1"></i>Assign a Package for <?= h($transfer['to_program_name'] ?? 'the new program') ?>
                        </button>
                    </form>

                <?php else: ?>
                    <div class="d-flex justify-content-between flex-wrap gap-3 mb-3">
                        <div>
                            <div class="fw-semibold"><?= h($package['program_name']) ?></div>
                            <div class="small text-muted">
                                <?= h(ucfirst($package['payment_type'])) ?> ·
                                <?= (int)$package['total_semesters'] ?> semester(s) · <?= (int)$package['total_months'] ?> months
                                <?php if ($package['payment_type'] === 'fixed'): ?>
                                · Tk <?= number_format((float)$package['monthly_payment'], 2) ?>/month
                                <?php endif; ?>
                            </div>
                            <div class="small text-muted">Assigned under the previous program: <strong><?= h($transfer['from_program_name'] ?? '—') ?></strong></div>
                        </div>
                        <a href="<?= APP_URL ?>/student-accounts/view.php?id=<?= (int)$package['id'] ?>" class="btn btn-outline-secondary btn-sm align-self-start">
                            <i class="fas fa-eye me-1"></i>Open in Student Accounts
                        </a>
                    </div>

                    <?php if ($transfer['package_action'] === 'none'): ?>
                        <?php if ($payment_count > 0): ?>
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            This package has <?= (int)$payment_count ?> recorded payment(s) — it cannot be removed automatically.
                            Reconcile it manually in Student Accounts, then come back here.
                        </div>
                        <?php else: ?>
                        <p class="small text-muted mb-3">This package was set up for the old program. Decide what to do with it before (optionally) assigning a new one.</p>
                        <?php endif; ?>
                        <form method="post" class="d-flex gap-2 flex-wrap">
                            <?= csrf_field() ?>
                            <button type="submit" name="action" value="end_package" class="btn btn-outline-danger btn-sm"
                                    onclick="return confirm('End this fee package now? This cannot be undone.');">
                                <i class="fas fa-ban me-1"></i>End This Package
                            </button>
                            <button type="submit" name="action" value="dismiss_package" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-check me-1"></i>Keep As-Is
                            </button>
                        </form>

                    <?php elseif ($transfer['package_action'] === 'ended'): ?>
                        <div class="alert alert-success small mb-3"><i class="fas fa-check-circle me-1"></i>This package was ended as part of the transfer.</div>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="goto_assign_package">
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-plus me-1"></i>Assign a Package for <?= h($transfer['to_program_name'] ?? 'the new program') ?>
                            </button>
                        </form>

                    <?php elseif ($transfer['package_action'] === 'kept'): ?>
                        <div class="alert alert-secondary small mb-3"><i class="fas fa-check me-1"></i>Reviewed — this package was kept unchanged.</div>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reopen_package">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Reconsider</button>
                        </form>

                    <?php elseif ($transfer['package_action'] === 'blocked_has_payments'): ?>
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>Could not be ended automatically — recorded payments exist. Reconcile manually in Student Accounts.
                        </div>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reopen_package">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Try Again</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
