<?php
/**
 * VC Approval – Approval queue index
 * Accessible to: Vice Chancellor group, super-admins
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('vc-approval');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/config.php';

$tab      = in_array($_GET['tab'] ?? '', ['pending','approved','rejected','revoked','all'])
            ? ($_GET['tab'] ?? 'pending') : 'pending';
$requests = vca_list($tab);

$page_title = 'VC Scholarship Approval';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">
            <i class="fas fa-user-check me-2 text-primary"></i>VC Scholarship Approval
        </h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">VC Approval</li>
        </ol></nav>
    </div>
</div>

<?= flash_show() ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
    <?php
    $tabs = [
        'pending'  => ['label' => 'Pending',  'icon' => 'fas fa-clock',        'color' => 'text-warning'],
        'approved' => ['label' => 'Approved', 'icon' => 'fas fa-check-circle', 'color' => 'text-success'],
        'rejected' => ['label' => 'Rejected', 'icon' => 'fas fa-times-circle', 'color' => 'text-danger'],
        'revoked'  => ['label' => 'Revoked',  'icon' => 'fas fa-undo',         'color' => 'text-secondary'],
        'all'      => ['label' => 'All',      'icon' => 'fas fa-list',         'color' => ''],
    ];
    foreach ($tabs as $key => $t):
    ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === $key ? 'active' : '' ?>"
           href="?tab=<?= $key ?>">
            <i class="<?= $t['icon'] ?> me-1 <?= $t['color'] ?>"></i><?= $t['label'] ?>
            <?php if ($key === 'pending' && ($pCount = vca_get_pending_count()) > 0): ?>
            <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem;"><?= $pCount ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
        <p class="text-muted px-4 py-4 mb-0">
            <i class="fas fa-info-circle me-1"></i>No <?= h($tab) ?> scholarship requests found.
        </p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.875rem;">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Student</th>
                        <th>Scholarship</th>
                        <th>Amount / Discount</th>
                        <th>Scope</th>
                        <th>Note</th>
                        <th>Doc</th>
                        <th>Requested By</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <td class="text-muted fw-semibold"><?= (int)$r['id'] ?></td>
                    <td>
                        <a href="<?= APP_URL ?>/student-accounts/view.php?id=<?= (int)$r['package_id'] ?>"
                           class="fw-semibold text-decoration-none">
                            <?= h($r['student_name']) ?>
                        </a>
                        <div class="text-muted" style="font-size:.75rem;"><?= h($r['student_sid']) ?></div>
                        <?php if ($r['program_name']): ?>
                        <div class="text-muted" style="font-size:.72rem;"><?= h($r['program_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fw-semibold"><?= h($r['label']) ?></span>
                        <?php if ($r['is_from_policy']): ?>
                        <span class="badge bg-info text-dark ms-1" style="font-size:.65rem;">Policy</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['discount_type'] === 'fixed'): ?>
                            <span class="text-danger fw-semibold">BDT <?= number_format((float)$r['fixed_amount'], 2) ?></span>
                            <div class="text-muted" style="font-size:.72rem;">Fixed amount</div>
                        <?php else: ?>
                            <span class="text-danger fw-semibold"><?= number_format((float)$r['discount_pct'], 2) ?>%</span>
                            <div class="text-muted" style="font-size:.72rem;">Percentage</div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem;">
                        <?php if ($r['apply_to_all']): ?>
                            <span class="badge bg-primary">All Semesters</span>
                        <?php else: ?>
                            Sem #<?= (int)$r['semester_number'] ?>
                            <?php if ($r['semester_label']): ?>
                            <div class="text-muted" style="font-size:.72rem;"><?= h($r['semester_label']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($r['applies_to_fixed']): ?>
                        <span class="badge bg-warning text-dark" style="font-size:.6rem;">+Fixed</span>
                        <?php endif; ?>
                        <?php if ($r['applies_to_english']): ?>
                        <span class="badge bg-info text-dark" style="font-size:.6rem;">+ENG</span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:200px;">
                        <?= $r['sc_note'] ? '<span class="text-muted" style="font-size:.8rem;">' . h($r['sc_note']) . '</span>' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td>
                        <?php if ($r['doc_stored_name']): ?>
                        <a href="<?= UPLOAD_URL ?>/students/files/<?= rawurlencode($r['doc_stored_name']) ?>"
                           target="_blank"
                           title="<?= h($r['doc_original_name'] ?? 'View document') ?>"
                           class="btn btn-outline-secondary btn-sm"
                           style="font-size:.75rem;padding:2px 8px;">
                            <i class="fas fa-paperclip me-1"></i>View
                        </a>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem;">
                        <?= h($r['requested_by_name']) ?>
                        <div class="text-muted" style="font-size:.72rem;"><?= date('d M Y', strtotime($r['created_at'])) ?></div>
                    </td>
                    <td>
                        <?= vca_status_badge($r['status']) ?>
                        <?php if ($r['status'] === 'approved' && $r['reviewed_by_name']): ?>
                        <div class="text-muted" style="font-size:.72rem;">
                            by <?= h($r['reviewed_by_name']) ?><br>
                            <?= date('d M Y', strtotime($r['reviewed_at'])) ?>
                        </div>
                        <?php elseif ($r['status'] === 'rejected'): ?>
                        <div class="text-muted" style="font-size:.72rem;">
                            <?php if ($r['review_note']): ?>
                            <em><?= h($r['review_note']) ?></em><br>
                            <?php endif; ?>
                            <?= $r['reviewed_by_name'] ? 'by ' . h($r['reviewed_by_name']) : '' ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['status'] === 'pending' && vca_can_review()): ?>
                        <!-- Approve button -->
                        <button type="button"
                                class="btn btn-success btn-sm mb-1"
                                style="font-size:.75rem;"
                                data-bs-toggle="modal"
                                data-bs-target="#approveModal"
                                data-id="<?= (int)$r['id'] ?>"
                                data-label="<?= h($r['label']) ?>"
                                data-student="<?= h($r['student_name']) ?>">
                            <i class="fas fa-check me-1"></i>Approve
                        </button>
                        <!-- Reject button -->
                        <button type="button"
                                class="btn btn-danger btn-sm mb-1"
                                style="font-size:.75rem;"
                                data-bs-toggle="modal"
                                data-bs-target="#rejectModal"
                                data-id="<?= (int)$r['id'] ?>"
                                data-label="<?= h($r['label']) ?>"
                                data-student="<?= h($r['student_name']) ?>">
                            <i class="fas fa-times me-1"></i>Reject
                        </button>
                        <?php elseif ($r['status'] === 'approved' && vca_can_revoke()): ?>
                        <button type="button"
                                class="btn btn-outline-secondary btn-sm"
                                style="font-size:.75rem;"
                                data-bs-toggle="modal"
                                data-bs-target="#revokeModal"
                                data-id="<?= (int)$r['id'] ?>"
                                data-label="<?= h($r['label']) ?>"
                                data-student="<?= h($r['student_name']) ?>">
                            <i class="fas fa-undo me-1"></i>Revoke
                        </button>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:.75rem;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     APPROVE MODAL
═══════════════════════════════════════════════════════════ -->
<?php if (vca_can_review()): ?>
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= APP_URL ?>/vc-approval/approve.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="approve-id" value="">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-check-circle me-2"></i>Approve Scholarship
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        You are about to <strong class="text-success">approve</strong> the scholarship
                        <strong id="approve-label"></strong> for student
                        <strong id="approve-student"></strong>.
                    </p>
                    <p class="text-muted small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Once approved, the scholarship will be applied to the student's account
                        and <strong>cannot be undone without a Super Administrator</strong>.
                    </p>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Approval Note <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea name="review_note" class="form-control" rows="3"
                                  placeholder="Add a note about this approval (optional)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Confirm Approval
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     REJECT MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= APP_URL ?>/vc-approval/reject.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="reject-id" value="">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-times-circle me-2"></i>Reject Scholarship
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        You are about to <strong class="text-danger">reject</strong> the scholarship
                        <strong id="reject-label"></strong> for student
                        <strong id="reject-student"></strong>.
                    </p>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea name="review_note" class="form-control" rows="3"
                                  placeholder="Please provide a reason for rejecting this scholarship request"
                                  required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-1"></i>Confirm Rejection
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     REVOKE MODAL (super-admin only)
═══════════════════════════════════════════════════════════ -->
<?php if (vca_can_revoke()): ?>
<div class="modal fade" id="revokeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= APP_URL ?>/vc-approval/revoke.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="revoke-id" value="">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-undo me-2"></i>Revoke Approved Scholarship
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Warning:</strong> Revoking will remove the applied scholarship from the
                        student's account and recalculate their fees. This action is logged.
                    </div>
                    <p class="mb-3">
                        Revoke scholarship <strong id="revoke-label"></strong> for
                        <strong id="revoke-student"></strong>?
                    </p>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Reason for Revocation <span class="text-danger">*</span></label>
                        <textarea name="revoke_reason" class="form-control" rows="3"
                                  placeholder="Explain why this approved scholarship is being revoked"
                                  required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-outline-danger">
                        <i class="fas fa-undo me-1"></i>Revoke Scholarship
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Populate modal fields from data-* attributes
document.addEventListener('DOMContentLoaded', function () {
    var approveModal = document.getElementById('approveModal');
    if (approveModal) {
        approveModal.addEventListener('show.bs.modal', function (e) {
            var btn = e.relatedTarget;
            document.getElementById('approve-id').value       = btn.dataset.id;
            document.getElementById('approve-label').textContent   = '"' + btn.dataset.label + '"';
            document.getElementById('approve-student').textContent = btn.dataset.student;
        });
    }

    var rejectModal = document.getElementById('rejectModal');
    if (rejectModal) {
        rejectModal.addEventListener('show.bs.modal', function (e) {
            var btn = e.relatedTarget;
            document.getElementById('reject-id').value       = btn.dataset.id;
            document.getElementById('reject-label').textContent   = '"' + btn.dataset.label + '"';
            document.getElementById('reject-student').textContent = btn.dataset.student;
        });
    }

    var revokeModal = document.getElementById('revokeModal');
    if (revokeModal) {
        revokeModal.addEventListener('show.bs.modal', function (e) {
            var btn = e.relatedTarget;
            document.getElementById('revoke-id').value       = btn.dataset.id;
            document.getElementById('revoke-label').textContent   = '"' + btn.dataset.label + '"';
            document.getElementById('revoke-student').textContent = btn.dataset.student;
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
