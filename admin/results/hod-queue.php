<?php
/**
 * HOD Queue – lists under_review mark sheets for Department Head approval.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/workflow-helpers.php';

if (!wf_can_hod()) {
    flash_set('error', 'You do not have permission to access the HOD queue.');
    redirect(APP_URL . '/results/index.php');
}

$page_title = 'HOD Approval Queue';
$dept_scope = get_dept_scope();

$where  = ["ms.workflow_status = 'under_review'"];
$params = [];

if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $where[] = '0 = 1';
    } else {
        $phs     = implode(',', array_fill(0, count($dept_scope), '?'));
        $where[] = "ms.dept_id IN ($phs)";
        array_push($params, ...$dept_scope);
    }
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$sheets = db()->prepare(
    "SELECT ms.*,
            d.name          AS dept_name,
            p.program_name,
            u_c.username    AS creator_name,
            u_r.username    AS reviewer_name,
            (SELECT COUNT(*) FROM result_sheet_grades g WHERE g.sheet_id = ms.id) AS student_count
     FROM result_mark_sheets ms
     JOIN dept_departments d             ON d.id = ms.dept_id
     LEFT JOIN dept_academic_programs p  ON p.id = ms.program_id
     LEFT JOIN users u_c                 ON u_c.id = ms.created_by
     LEFT JOIN users u_r                 ON u_r.id = ms.reviewed_by
     $where_sql
     ORDER BY ms.reviewed_at ASC"
);
$sheets->execute($params);
$sheets = $sheets->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item active">HOD Approval Queue</li>
        </ol>
    </nav>
    <span class="badge bg-warning text-dark" style="font-size:.85rem;">
        <?= count($sheets) ?> sheet<?= count($sheets) !== 1 ? 's' : '' ?> pending
    </span>
</div>

<?php flash_show(); ?>

<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-tie me-2 text-muted"></i>Mark Sheets Awaiting HOD Approval</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">#</th>
                        <th>Subject</th>
                        <th>Department / Program</th>
                        <th>Semester</th>
                        <th>Teacher</th>
                        <th>Reviewer</th>
                        <th class="text-center">Students</th>
                        <th>Reviewed</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sheets)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-5">
                        <i class="fas fa-check-circle fa-2x mb-2 d-block text-success"></i>
                        No mark sheets pending HOD approval.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($sheets as $i => $s): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-medium"><?= h($s['subject_title']) ?></div>
                            <?php if ($s['subject_code']): ?>
                            <small class="text-muted"><?= h($s['subject_code']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= h($s['dept_name']) ?></div>
                            <?php if ($s['program_name']): ?>
                            <small class="text-muted"><?= h($s['program_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($s['semester']) ?></td>
                        <td><?= h($s['creator_name'] ?? '—') ?></td>
                        <td><?= h($s['reviewer_name'] ?? '—') ?></td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= (int)$s['student_count'] ?></span>
                        </td>
                        <td>
                            <small><?= $s['reviewed_at'] ? date('d M Y', strtotime($s['reviewed_at'])) : '—' ?></small>
                        </td>
                        <td class="text-end pe-4">
                            <a href="<?= APP_URL ?>/results/hod-review.php?id=<?= $s['id'] ?>"
                               class="btn btn-sm btn-warning text-dark" style="border-radius:8px;">
                                <i class="fas fa-user-tie me-1"></i> Review
                            </a>
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
