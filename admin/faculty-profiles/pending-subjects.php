<?php
/**
 * Pending Faculty Subject Assignments — list for HoD / admin to review.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/fp-helpers.php';

if (!fp_can_approve_subjects()) {
    flash_set('error', 'You do not have permission to view pending subject assignments.');
    redirect(APP_URL . '/faculty-profiles/index.php');
}

$page_title = 'Pending Subject Assignments';

$filter = trim($_GET['status'] ?? 'pending');
$allowed = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filter, $allowed, true)) $filter = 'pending';

// Build scope restriction for HoD
$dept_ids  = fp_head_dept_ids(); // null = all, [] = none, [n,…] = restricted
$where     = [];
$params    = [];

if ($filter !== 'all') {
    $where[]  = 'fsa.status = ?';
    $params[] = $filter;
}

if ($dept_ids !== null) {
    if (empty($dept_ids)) {
        // User is not HoD of any dept — no results
        $where[] = '1=0';
    } else {
        $ph      = implode(',', array_fill(0, count($dept_ids), '?'));
        $where[] = "dap.dept_id IN ($ph)";
        $params  = array_merge($params, $dept_ids);
    }
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = db()->prepare(
    "SELECT fsa.*,
            cc.course_name, cc.course_code, cc.credit,
            dap.dept_id,
            d.name AS dept_name,
            dap.program_name,
            u.full_name AS faculty_name, u.email AS faculty_email,
            rv.full_name AS reviewer_name
       FROM faculty_subject_assignments fsa
       JOIN course_curriculum cc ON cc.id = fsa.course_id
       JOIN dept_academic_programs dap ON dap.id = cc.program_id
       JOIN dept_departments d ON d.id = dap.dept_id
       JOIN users u ON u.id = fsa.faculty_user_id
       LEFT JOIN users rv ON rv.id = fsa.reviewed_by
       $where_sql
       ORDER BY fsa.created_at DESC"
);
$rows->execute($params);
$assignments = $rows->fetchAll();

// Count per status (within scope)
$count_params = $dept_ids !== null ? ($dept_ids ?: [0]) : [];
if ($dept_ids === null) {
    $cnt_sql = "SELECT status, COUNT(*) AS cnt
                  FROM faculty_subject_assignments GROUP BY status";
    $cnt_st  = db()->query($cnt_sql);
} else {
    $ph     = implode(',', array_fill(0, count($count_params), '?'));
    $cnt_st = db()->prepare(
        "SELECT fsa.status, COUNT(*) AS cnt
           FROM faculty_subject_assignments fsa
           JOIN course_curriculum cc ON cc.id = fsa.course_id
           JOIN dept_academic_programs dap ON dap.id = cc.program_id
          WHERE dap.dept_id IN ($ph)
          GROUP BY fsa.status"
    );
    $cnt_st->execute($count_params);
}
$counts = $cnt_st ? $cnt_st->fetchAll(PDO::FETCH_KEY_PAIR) : [];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/faculty-profiles/index.php">Faculty Profiles</a></li>
            <li class="breadcrumb-item active">Pending Subject Assignments</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

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
            <i class="fas fa-book me-2 text-muted"></i>Subject Assignment Requests
        </h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($assignments) ?> shown</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" style="font-size:14px;">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Faculty</th>
                        <th>Subject</th>
                        <th>Program / Dept</th>
                        <th style="width:70px;" class="text-center">Credit</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($assignments)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No requests found.</td></tr>
                <?php else: ?>
                    <?php foreach ($assignments as $i => $asgn): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-medium"><?= h($asgn['faculty_name']) ?></div>
                            <div class="text-muted small"><?= h($asgn['faculty_email']) ?></div>
                        </td>
                        <td>
                            <?php if ($asgn['course_code']): ?>
                            <span class="badge bg-light text-dark border me-1"><?= h($asgn['course_code']) ?></span>
                            <?php endif; ?>
                            <span class="fw-medium"><?= h($asgn['course_name']) ?></span>
                        </td>
                        <td>
                            <div><?= h($asgn['program_name']) ?></div>
                            <div class="text-muted small"><?= h($asgn['dept_name']) ?></div>
                        </td>
                        <td class="text-center">
                            <?= $asgn['credit'] !== null
                                ? '<span class="badge bg-secondary">' . h(number_format((float)$asgn['credit'], 2)) . '</span>'
                                : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="small text-muted"><?= h(date('d M Y H:i', strtotime($asgn['created_at']))) ?></td>
                        <td>
                            <?php if ($asgn['status'] === 'pending'): ?>
                            <span class="badge bg-warning text-dark">Pending</span>
                            <?php elseif ($asgn['status'] === 'approved'): ?>
                            <span class="badge bg-success">Approved</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <?php if ($asgn['status'] === 'pending'): ?>
                            <div class="d-flex gap-1 justify-content-end">
                                <form method="POST" action="<?= APP_URL ?>/faculty-profiles/subject-approve.php"
                                      onsubmit="return confirm('Approve subject assignment for <?= addslashes(h($asgn['faculty_name'])) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id"     value="<?= (int)$asgn['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn btn-sm btn-success" style="border-radius:7px;">
                                        <i class="fas fa-check me-1"></i>Approve
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:7px;"
                                        data-bs-toggle="modal" data-bs-target="#rejectModal<?= (int)$asgn['id'] ?>">
                                    <i class="fas fa-times me-1"></i>Reject
                                </button>
                            </div>

                            <!-- Reject Modal -->
                            <div class="modal fade" id="rejectModal<?= (int)$asgn['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reject Subject Assignment</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" action="<?= APP_URL ?>/faculty-profiles/subject-approve.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id"     value="<?= (int)$asgn['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <div class="modal-body">
                                                <p>Reject request from <strong><?= h($asgn['faculty_name']) ?></strong> to teach <strong><?= h($asgn['course_name']) ?></strong>.</p>
                                                <div class="mb-3">
                                                    <label class="form-label fw-medium">Reason / Notes <span class="text-muted">(optional)</span></label>
                                                    <textarea name="notes" class="form-control" rows="3" placeholder="Reason for rejection…"></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Reject</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <?php else: ?>
                            <small class="text-muted">
                                <?= ucfirst($asgn['status']) ?> by <?= h($asgn['reviewer_name'] ?? '—') ?><br>
                                <?= $asgn['reviewed_at'] ? h(date('d M Y', strtotime($asgn['reviewed_at']))) : '' ?>
                            </small>
                            <?php if ($asgn['notes']): ?>
                            <div class="small text-muted fst-italic mt-1"><?= h(mb_strimwidth($asgn['notes'], 0, 80, '…')) ?></div>
                            <?php endif; ?>
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
