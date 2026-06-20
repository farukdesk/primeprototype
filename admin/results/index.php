<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('results');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/workflow-helpers.php';

$page_title = 'Result Management';

// ── Active tab (workflow dashboard) ───────────────────────────────────────────
$active_tab = $_GET['tab'] ?? 'my_sheets';

// Apply department scope
$dept_scope = get_dept_scope();

// ── Workflow counts (chain-aware) ─────────────────────────────────────────────
$wf_counts  = ['my_sheets' => 0, 'queue' => 0, 'published' => 0];
$user_id    = (int)(auth_user()['id'] ?? 0);
try {
    // My sheets
    if (wf_can_create_sheet()) {
        $r = db()->prepare('SELECT COUNT(*) FROM result_mark_sheets WHERE created_by = ?');
        $r->execute([$user_id]);
        $wf_counts['my_sheets'] = (int)$r->fetchColumn();
    }
    // Approver queue (sheets pending current user's action)
    if (wf_has_approver_role()) {
        $wf_counts['queue'] = count(wf_get_approver_queue());
    }
    // Published
    $pub_where  = "workflow_status = 'published'";
    $pub_params = [];
    if ($dept_scope !== null && !empty($dept_scope)) {
        $dphs        = implode(',', array_fill(0, count($dept_scope), '?'));
        $pub_where  .= " AND dept_id IN ($dphs)";
        $pub_params  = $dept_scope;
    } elseif ($dept_scope !== null && empty($dept_scope)) {
        $pub_where .= ' AND 0=1';
    }
    $r = db()->prepare("SELECT COUNT(*) FROM result_mark_sheets WHERE $pub_where");
    $r->execute($pub_params);
    $wf_counts['published'] = (int)$r->fetchColumn();
} catch (Throwable $_e) { /* tables may not exist yet */ }

// ── Redirect before any output ────────────────────────────────────────────────
if ($active_tab === 'queue' && wf_has_approver_role()) {
    redirect(APP_URL . '/results/workflow-queue.php');
}


require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Results</li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (wf_can_create_sheet()): ?>
        <a href="<?= APP_URL ?>/results/mark-entry.php" class="btn btn-success" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-pen-nib me-1"></i> New Mark Sheet
        </a>
        <?php endif; ?>

    </div>
</div>

<?php flash_show(); ?>

<!-- ── Workflow Dashboard Tabs (chain-aware) ──────────────────────────────── -->
<?php $has_wf_access = wf_can_create_sheet() || wf_has_approver_role(); ?>
<?php if ($has_wf_access): ?>
<ul class="nav nav-tabs mb-4" id="resultTabs">
    <?php if (wf_can_create_sheet()): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'my_sheets' ? 'active' : '' ?>" href="?tab=my_sheets">
            <i class="fas fa-pen-nib me-1"></i> My Sheets
            <?php if ($wf_counts['my_sheets'] > 0): ?>
            <span class="badge bg-secondary ms-1"><?= $wf_counts['my_sheets'] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if (wf_has_approver_role()): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'queue' ? 'active' : '' ?>" href="?tab=queue">
            <i class="fas fa-tasks me-1"></i> Workflow Queue
            <?php if ($wf_counts['queue'] > 0): ?>
            <span class="badge bg-primary ms-1"><?= $wf_counts['queue'] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'published' ? 'active' : '' ?>" href="?tab=published">
            <i class="fas fa-globe me-1"></i> Published
            <?php if ($wf_counts['published'] > 0): ?>
            <span class="badge bg-success bg-opacity-75 ms-1"><?= $wf_counts['published'] ?></span>
            <?php endif; ?>
        </a>
    </li>

</ul>
<?php endif; ?>

<?php
// ── Active tab: My Sheets ─────────────────────────────────────────────────────
if ($active_tab === 'my_sheets' && wf_can_create_sheet()):
    _wf_render_my_sheets($user_id, $dept_scope);
    require_once __DIR__ . '/../includes/footer.php';
    exit;
endif;

// ── Active tab: Published workflow sheets ─────────────────────────────────────
if ($active_tab === 'published' && $has_wf_access):
    _wf_render_published($dept_scope);
    require_once __DIR__ . '/../includes/footer.php';
    exit;
endif;
// Fall through to default — show the my_sheets view for users with access
?>

<?php
/**
 * Render "My Sheets" tab — teacher view of their own mark sheets.
 */
function _wf_render_my_sheets(int $user_id, ?array $dept_scope): void
{
    try {
        $stmt = db()->prepare(
            "SELECT ms.*,
                    d.name          AS dept_name,
                    p.program_name,
                    c.name          AS chain_name,
                    s.step_label    AS current_step_label,
                    g.name          AS current_group_name,
                    (SELECT COUNT(*) FROM result_sheet_grades sg WHERE sg.sheet_id = ms.id) AS student_count
             FROM result_mark_sheets ms
             JOIN dept_departments d              ON d.id  = ms.dept_id
             LEFT JOIN dept_academic_programs p   ON p.id  = ms.program_id
             LEFT JOIN wf_chains c                ON c.id  = ms.chain_id
             LEFT JOIN wf_chain_steps s           ON s.chain_id = ms.chain_id
                                                 AND s.step_order = ms.current_step_order
             LEFT JOIN user_groups g              ON g.id  = s.group_id
             WHERE ms.created_by = ?
             ORDER BY ms.updated_at DESC
             LIMIT 200"
        );
        $stmt->execute([$user_id]);
        $sheets = $stmt->fetchAll();
    } catch (Throwable $e) {
        echo '<div class="alert alert-warning">Run <code>results-workflow-migration.sql</code> first.</div>';
        return;
    }
    ?>
    <div class="card" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-pen-nib me-2 text-muted"></i>My Mark Sheets</h6>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-secondary"><?= count($sheets) ?></span>
                <a href="<?= APP_URL ?>/results/mark-entry.php" class="btn btn-sm btn-success" style="border-radius:8px;">
                    <i class="fas fa-plus me-1"></i> New
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-4">#</th>
                            <th>Subject</th>
                            <th>Department / Program</th>
                            <th>Batch</th>
                            <th class="text-center">Students</th>
                            <th>Status</th>
                            <th>Current Step</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($sheets)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-5">
                            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                            No mark sheets yet.
                            <a href="<?= APP_URL ?>/results/mark-entry.php">Create one now</a>.
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
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= (int)$s['student_count'] ?></span>
                            </td>
                            <td><?= wf_status_badge($s['workflow_status']) ?></td>
                            <td>
                                <?php if ($s['workflow_status'] === 'pending' && $s['current_step_label']): ?>
                                <small class="text-info"><?= h($s['current_step_label']) ?></small>
                                <?php elseif ($s['workflow_status'] === 'published'): ?>
                                <small class="text-success"><i class="fas fa-globe me-1"></i>Published</small>
                                <?php elseif ($s['workflow_status'] === 'returned'): ?>
                                <small class="text-danger"><i class="fas fa-undo me-1"></i>Returned – check remarks</small>
                                <?php else: ?>
                                <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex gap-1 justify-content-end">
                                    <?php if (in_array($s['workflow_status'], ['draft','returned'], true)): ?>
                                    <a href="<?= APP_URL ?>/results/mark-entry.php?id=<?= $s['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="<?= APP_URL ?>/results/sheet-print.php?id=<?= $s['id'] ?>" target="_blank"
                                       class="btn btn-sm btn-outline-secondary" title="Print" style="border-radius:7px;">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render "Published" tab — all published workflow sheets in user's scope.
 */
function _wf_render_published(?array $dept_scope): void
{
    $where  = ["ms.workflow_status = 'published'"];
    $params = [];
    if ($dept_scope !== null) {
        if (empty($dept_scope)) { $where[] = '0=1'; }
        else {
            $phs     = implode(',', array_fill(0, count($dept_scope), '?'));
            $where[] = "ms.dept_id IN ($phs)";
            array_push($params, ...$dept_scope);
        }
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where);

    try {
        $stmt = db()->prepare(
            "SELECT ms.*,
                    d.name          AS dept_name,
                    p.program_name,
                    c.name          AS chain_name,
                    u.username      AS creator_name,
                    (SELECT COUNT(*) FROM result_sheet_grades sg WHERE sg.sheet_id = ms.id) AS student_count
             FROM result_mark_sheets ms
             JOIN dept_departments d              ON d.id  = ms.dept_id
             LEFT JOIN dept_academic_programs p   ON p.id  = ms.program_id
             LEFT JOIN wf_chains c                ON c.id  = ms.chain_id
             LEFT JOIN users u                    ON u.id  = ms.created_by
             $where_sql
             ORDER BY ms.updated_at DESC
             LIMIT 500"
        );
        $stmt->execute($params);
        $sheets = $stmt->fetchAll();
    } catch (Throwable $e) {
        echo '<div class="alert alert-warning">Run <code>results-workflow-migration.sql</code> first.</div>';
        return;
    }
    ?>
    <div class="card" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-globe me-2 text-muted"></i>Published Mark Sheets</h6>
            <span class="badge bg-success"><?= count($sheets) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="px-4">#</th>
                            <th>Subject</th>
                            <th>Department / Program</th>
                            <th>Batch</th>
                            <th>Teacher</th>
                            <th class="text-center">Students</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($sheets)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-5">No published sheets yet.</td></tr>
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
                                <?php if ($s['program_name']): ?><small class="text-muted"><?= h($s['program_name']) ?></small><?php endif; ?>
                            </td>
                            <td><?= h($s['semester']) ?></td>
                            <td><?= h($s['creator_name'] ?? '—') ?></td>
                            <td class="text-center"><span class="badge bg-secondary"><?= (int)$s['student_count'] ?></span></td>
                            <td class="text-end pe-4">
                                <a href="<?= APP_URL ?>/results/sheet-print.php?id=<?= $s['id'] ?>" target="_blank"
                                   class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="Print">
                                    <i class="fas fa-print"></i>
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
    <?php
}
?>





<?php require_once __DIR__ . '/../includes/footer.php'; ?>
