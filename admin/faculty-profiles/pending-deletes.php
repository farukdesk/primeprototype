<?php
/**
 * Faculty File Delete Requests – Review page.
 * Super admins can approve (executes deletion) or reject pending delete requests.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_super_admin();
require_once __DIR__ . '/fp-helpers.php';

$page_title = 'Faculty File Delete Requests';

// ── Handle POST (approve / reject) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $req_id     = (int)($_POST['req_id']     ?? 0);
    $decision   = trim($_POST['decision']    ?? '');   // 'approve' or 'reject'
    $review_note = trim($_POST['review_note'] ?? '');
    $reviewer   = auth_user();

    if (!in_array($decision, ['approve', 'reject'], true) || !$req_id) {
        flash_set('error', 'Invalid request.');
        redirect(APP_URL . '/faculty-profiles/pending-deletes.php');
    }

    // Load request
    $stmt = db()->prepare('SELECT * FROM faculty_file_delete_requests WHERE id = ?');
    $stmt->execute([$req_id]);
    $req = $stmt->fetch();

    if (!$req || $req['status'] !== 'pending') {
        flash_set('error', 'Request not found or already reviewed.');
        redirect(APP_URL . '/faculty-profiles/pending-deletes.php');
    }

    if ($decision === 'approve') {
        // Execute the actual file deletion
        $file_stmt = db()->prepare('SELECT * FROM faculty_files WHERE id = ?');
        $file_stmt->execute([$req['file_id']]);
        $file = $file_stmt->fetch();

        if ($file) {
            $path = UPLOAD_DIR . '/faculty-profiles/files/' . basename($file['stored_name']);
            if (file_exists($path)) @unlink($path);
            db()->prepare('DELETE FROM faculty_files WHERE id = ?')->execute([$req['file_id']]);
        }

        // Mark request approved
        db()->prepare(
            "UPDATE faculty_file_delete_requests
             SET status='approved', reviewed_by=?, reviewed_at=NOW(), review_note=?
             WHERE id=?"
        )->execute([$reviewer['id'], $review_note ?: null, $req_id]);

        log_change('faculty-files', 'DELETE', $req['file_id'],
            $req['faculty_name'] . ' – ' . $req['file_name'],
            'file_delete', $req['file_name'], null,
            'Delete request approved by ' . $reviewer['full_name']
                . ($review_note ? '. ' . $review_note : '.'));

        flash_set('success', 'File "' . h($req['file_name']) . '" deleted and request approved.');

    } else {
        // Reject – file stays, just update the request record
        db()->prepare(
            "UPDATE faculty_file_delete_requests
             SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=?
             WHERE id=?"
        )->execute([$reviewer['id'], $review_note ?: null, $req_id]);

        log_change('faculty-files', 'DELETE_REQUEST_REJECT', $req['file_id'],
            $req['faculty_name'] . ' – ' . $req['file_name'],
            null, null, null,
            'Delete request rejected by ' . $reviewer['full_name']
                . ($review_note ? '. ' . $review_note : '.'));

        flash_set('success', 'Delete request for "' . h($req['file_name']) . '" rejected.');
    }

    redirect(APP_URL . '/faculty-profiles/pending-deletes.php');
}

// ── Filters ────────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? 'pending';
if (!in_array($filter_status, ['pending', 'approved', 'rejected', 'all'], true)) {
    $filter_status = 'pending';
}

$where  = [];
$params = [];

if ($filter_status !== 'all') {
    $where[]  = 'fdr.status = ?';
    $params[] = $filter_status;
}

$sql = 'SELECT fdr.*,
               req.full_name AS requester_name,
               rev.full_name AS reviewer_name
        FROM faculty_file_delete_requests fdr
        LEFT JOIN users req ON req.id = fdr.requested_by
        LEFT JOIN users rev ON rev.id = fdr.reviewed_by'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY fdr.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pending_count = (int)db()->query(
    "SELECT COUNT(*) FROM faculty_file_delete_requests WHERE status = 'pending'"
)->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/faculty-profiles/index.php">Faculty Profiles</a></li>
            <li class="breadcrumb-item active">Delete Requests</li>
        </ol>
    </nav>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-clock me-2 text-muted"></i>Faculty File Delete Requests
            <?php if ($pending_count > 0): ?>
            <span class="badge bg-danger ms-1"><?= $pending_count ?> pending</span>
            <?php endif; ?>
        </h6>
        <div class="d-flex gap-2">
            <?php
            $statuses = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'];
            foreach ($statuses as $sv => $sl):
            ?>
            <a href="?status=<?= $sv ?>"
               class="btn btn-sm <?= $filter_status === $sv ? 'btn-primary' : 'btn-outline-secondary' ?>"
               style="border-radius:7px;">
                <?= $sl ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-check-circle fa-2x mb-3 opacity-25"></i>
            <p class="mb-0">No <?= $filter_status !== 'all' ? $filter_status : '' ?> requests found.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>File</th>
                        <th>Faculty</th>
                        <th>Requested By</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $i => $req): ?>
                <tr>
                    <td class="px-4"><?= $i + 1 ?></td>
                    <td>
                        <span class="fw-medium"><?= h($req['file_name']) ?></span>
                        <?php if ($req['request_note']): ?>
                        <div class="small text-muted"><i class="fas fa-comment me-1"></i><?= h($req['request_note']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <a href="<?= APP_URL ?>/faculty-profiles/files.php?user_id=<?= (int)$req['faculty_user_id'] ?>">
                            <?= h($req['faculty_name']) ?>
                        </a>
                    </td>
                    <td class="small"><?= h($req['requester_name'] ?? '—') ?></td>
                    <td class="text-muted small"><?= h(date('d M Y, H:i', strtotime($req['created_at']))) ?></td>
                    <td>
                        <?php if ($req['status'] === 'pending'): ?>
                        <span class="badge bg-warning text-dark">Pending</span>
                        <?php elseif ($req['status'] === 'approved'): ?>
                        <span class="badge bg-success">Approved</span>
                        <?php else: ?>
                        <span class="badge bg-danger">Rejected</span>
                        <?php endif; ?>
                        <?php if ($req['review_note']): ?>
                        <div class="small text-muted mt-1"><?= h($req['review_note']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <?php if ($req['status'] === 'pending'): ?>
                        <button type="button" class="btn btn-sm btn-success me-1" style="border-radius:7px;"
                                data-bs-toggle="modal" data-bs-target="#reviewModal"
                                data-req-id="<?= (int)$req['id'] ?>"
                                data-decision="approve"
                                data-file-name="<?= h($req['file_name']) ?>">
                            <i class="fas fa-check me-1"></i>Approve
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:7px;"
                                data-bs-toggle="modal" data-bs-target="#reviewModal"
                                data-req-id="<?= (int)$req['id'] ?>"
                                data-decision="reject"
                                data-file-name="<?= h($req['file_name']) ?>">
                            <i class="fas fa-times me-1"></i>Reject
                        </button>
                        <?php else: ?>
                        <span class="text-muted small">
                            <?= $req['reviewer_name'] ? 'by ' . h($req['reviewer_name']) : '—' ?>
                            <?= $req['reviewed_at'] ? '<br>' . h(date('d M Y, H:i', strtotime($req['reviewed_at']))) : '' ?>
                        </span>
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

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" aria-labelledby="reviewModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= APP_URL ?>/faculty-profiles/pending-deletes.php">
                <?= csrf_field() ?>
                <input type="hidden" name="req_id"   id="rvReqId">
                <input type="hidden" name="decision" id="rvDecision">
                <div class="modal-header">
                    <h5 class="modal-title" id="reviewModalLabel"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3" id="rvDesc"></p>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Note <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea name="review_note" class="form-control" rows="3"
                                  placeholder="Add a note for the requester…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="rvSubmitBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('reviewModal').addEventListener('show.bs.modal', function(e) {
    var btn      = e.relatedTarget;
    var decision = btn.dataset.decision;
    var fileName = btn.dataset.fileName;
    document.getElementById('rvReqId').value    = btn.dataset.reqId;
    document.getElementById('rvDecision').value = decision;
    var isApprove = decision === 'approve';
    document.getElementById('reviewModalLabel').textContent = isApprove ? 'Approve Delete Request' : 'Reject Delete Request';
    document.getElementById('rvDesc').textContent = isApprove
        ? 'Approving will permanently delete "' + fileName + '". This cannot be undone.'
        : 'Rejecting will keep the file "' + fileName + '" and notify the requester.';
    var btn2 = document.getElementById('rvSubmitBtn');
    btn2.textContent = isApprove ? 'Approve & Delete' : 'Reject Request';
    btn2.className = 'btn ' + (isApprove ? 'btn-success' : 'btn-danger');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
