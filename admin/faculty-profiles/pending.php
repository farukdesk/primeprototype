<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/fp-helpers.php';

if (!fp_can_manage_pending()) {
    flash_set('error', 'Access denied.');
    redirect(APP_URL . '/faculty-profiles/index.php');
}

$page_title = 'Pending Faculty Registrations';

$filter = trim($_GET['status'] ?? 'pending');
$allowed_statuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filter, $allowed_statuses, true)) $filter = 'pending';

$where  = $filter !== 'all' ? 'WHERE fr.status = ?' : '';
$params = $filter !== 'all' ? [$filter] : [];

$rows = db()->prepare(
    "SELECT fr.*, d.name AS dept_name, u.full_name AS reviewer_name
     FROM faculty_registrations fr
     LEFT JOIN dept_departments d ON d.id = fr.dept_id
     LEFT JOIN users u ON u.id = fr.reviewed_by
     $where
     ORDER BY fr.created_at DESC"
);
$rows->execute($params);
$registrations = $rows->fetchAll();

// Counts per status
$counts = db()->query(
    "SELECT status, COUNT(*) AS cnt FROM faculty_registrations GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/faculty-profiles/index.php">Faculty Profiles</a></li>
            <li class="breadcrumb-item active">Pending Registrations</li>
        </ol>
    </nav>
</div>

<!-- Status filter tabs -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <div class="d-flex gap-2 flex-wrap">
            <?php
            $tabs = [
                'pending'  => ['label' => 'Pending',  'badge' => 'bg-warning text-dark'],
                'approved' => ['label' => 'Approved', 'badge' => 'bg-success'],
                'rejected' => ['label' => 'Rejected', 'badge' => 'bg-danger'],
                'all'      => ['label' => 'All',      'badge' => 'bg-secondary'],
            ];
            foreach ($tabs as $s => $tab):
                $active = $filter === $s;
                $cnt    = $s === 'all' ? array_sum($counts) : ($counts[$s] ?? 0);
            ?>
            <a href="?status=<?= $s ?>"
               class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-secondary' ?>"
               style="border-radius:20px;">
                <?= $tab['label'] ?>
                <span class="badge <?= $tab['badge'] ?> ms-1"><?= (int)$cnt ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-user-clock me-2 text-muted"></i>Faculty Registration Requests
        </h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($registrations) ?> shown</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Department</th>
                        <th>ID Card</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($registrations)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No registrations found.</td></tr>
                <?php else: ?>
                    <?php foreach ($registrations as $i => $reg): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td class="fw-medium"><?= h($reg['full_name']) ?></td>
                        <td><?= h($reg['email']) ?></td>
                        <td><?= h($reg['phone'] ?? '—') ?></td>
                        <td><?= h($reg['dept_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($reg['id_card_stored']): ?>
                            <a href="<?= UPLOAD_URL ?>/faculty-registrations/<?= h($reg['id_card_stored']) ?>"
                               target="_blank" rel="noopener"
                               class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="<?= h($reg['id_card_original']) ?>">
                                <i class="fas fa-file me-1"></i>View
                            </a>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h(date('d M Y H:i', strtotime($reg['created_at']))) ?></td>
                        <td>
                            <?php if ($reg['status'] === 'pending'): ?>
                            <span class="badge bg-warning text-dark">Pending</span>
                            <?php elseif ($reg['status'] === 'approved'): ?>
                            <span class="badge bg-success">Approved</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <?php if ($reg['status'] === 'pending'): ?>
                            <div class="d-flex gap-1 justify-content-end">
                                <form method="POST" action="<?= APP_URL ?>/faculty-profiles/approve.php"
                                      onsubmit="return confirm('Approve registration for <?= addslashes(h($reg['full_name'])) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$reg['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn btn-sm btn-success" style="border-radius:7px;">
                                        <i class="fas fa-check me-1"></i>Approve
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:7px;"
                                        data-bs-toggle="modal" data-bs-target="#rejectModal<?= (int)$reg['id'] ?>">
                                    <i class="fas fa-times me-1"></i>Reject
                                </button>
                            </div>

                            <!-- Reject Modal -->
                            <div class="modal fade" id="rejectModal<?= (int)$reg['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reject Registration</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" action="<?= APP_URL ?>/faculty-profiles/approve.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$reg['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <div class="modal-body">
                                                <p>You are about to reject the registration from <strong><?= h($reg['full_name']) ?></strong>.</p>
                                                <div class="mb-3">
                                                    <label class="form-label fw-medium">Reason / Notes <span class="text-muted">(optional, will be emailed)</span></label>
                                                    <textarea name="notes" class="form-control" rows="3" placeholder="Reason for rejection…"></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Reject Registration</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <?php elseif ($reg['status'] === 'approved'): ?>
                            <small class="text-muted">
                                Approved by <?= h($reg['reviewer_name'] ?? '—') ?><br>
                                <?= h(date('d M Y', strtotime($reg['reviewed_at']))) ?>
                            </small>
                            <?php else: ?>
                            <small class="text-muted">
                                Rejected by <?= h($reg['reviewer_name'] ?? '—') ?><br>
                                <?= h(date('d M Y', strtotime($reg['reviewed_at']))) ?>
                            </small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
