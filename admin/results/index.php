<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('results');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/workflow-helpers.php';

$page_title = 'Result Management';

// ── Active tab (workflow dashboard) ───────────────────────────────────────────
$active_tab = $_GET['tab'] ?? 'legacy';

// ── Filters ───────────────────────────────────────────────────────────────────
$f_dept    = (int)($_GET['dept_id']  ?? 0);
$f_program = (int)($_GET['program_id'] ?? 0);
$f_batch   = trim($_GET['batch'] ?? '');
$f_pub     = $_GET['published'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 20;

$where  = [];
$params = [];

if ($f_dept > 0)    { $where[] = 'e.dept_id = ?';    $params[] = $f_dept; }
if ($f_program > 0) { $where[] = 'e.program_id = ?'; $params[] = $f_program; }
if ($f_batch !== '') { $where[] = 'e.batch = ?';     $params[] = $f_batch; }
if ($f_pub === '1') { $where[] = 'e.is_published = 1'; }
if ($f_pub === '0') { $where[] = 'e.is_published = 0'; }

// Apply department scope
$dept_scope = get_dept_scope();
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $where[] = '0 = 1';
    } else {
        $phs     = implode(',', array_fill(0, count($dept_scope), '?'));
        $where[] = "e.dept_id IN ($phs)";
        array_push($params, ...$dept_scope);
    }
}

$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count_stmt = db()->prepare('SELECT COUNT(*) FROM result_exams e' . $where_sql);
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = db()->prepare(
    'SELECT e.*,
            d.name AS dept_name,
            p.program_name,
            (SELECT COUNT(*) FROM result_subjects rs WHERE rs.exam_id = e.id) AS subject_count,
            (SELECT COUNT(DISTINCT rg.student_sid) FROM result_grades rg WHERE rg.exam_id = e.id) AS student_count
     FROM result_exams e
     JOIN dept_departments d ON d.id = e.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = e.program_id'
    . $where_sql
    . ' ORDER BY e.created_at DESC LIMIT ' . $per_page . ' OFFSET ' . $offset
);
$stmt->execute($params);
$exams = $stmt->fetchAll();

// Dept list for filter
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
if ($dept_scope !== null) {
    $departments = array_values(array_filter(
        $departments,
        fn($d) => in_array((int)$d['id'], $dept_scope, true)
    ));
}

// ── Workflow dashboard counts ──────────────────────────────────────────────────
$wf_counts = ['my_sheets' => 0, 'review' => 0, 'hod' => 0, 'publish' => 0, 'published' => 0];
$user_id = (int)(auth_user()['id'] ?? 0);
try {
    // My sheets (teacher)
    if (wf_can_enter()) {
        $r = db()->prepare('SELECT COUNT(*) FROM result_mark_sheets WHERE created_by = ?');
        $r->execute([$user_id]);
        $wf_counts['my_sheets'] = (int)$r->fetchColumn();
    }
    // Pending review
    if (wf_can_review()) {
        $r_where = "workflow_status = 'submitted'";
        $r_params = [];
        if ($dept_scope !== null && !empty($dept_scope)) {
            $phs      = implode(',', array_fill(0, count($dept_scope), '?'));
            $r_where .= " AND dept_id IN ($phs)";
            $r_params = $dept_scope;
        } elseif ($dept_scope !== null && empty($dept_scope)) {
            $r_where .= ' AND 0=1';
        }
        $r = db()->prepare("SELECT COUNT(*) FROM result_mark_sheets WHERE $r_where");
        $r->execute($r_params);
        $wf_counts['review'] = (int)$r->fetchColumn();
    }
    // Pending HOD
    if (wf_can_hod()) {
        $h_where  = "workflow_status = 'under_review'";
        $h_params = [];
        if ($dept_scope !== null && !empty($dept_scope)) {
            $phs      = implode(',', array_fill(0, count($dept_scope), '?'));
            $h_where .= " AND dept_id IN ($phs)";
            $h_params = $dept_scope;
        } elseif ($dept_scope !== null && empty($dept_scope)) {
            $h_where .= ' AND 0=1';
        }
        $r = db()->prepare("SELECT COUNT(*) FROM result_mark_sheets WHERE $h_where");
        $r->execute($h_params);
        $wf_counts['hod'] = (int)$r->fetchColumn();
    }
    // Pending publish
    if (wf_can_publish()) {
        $r = db()->prepare("SELECT COUNT(*) FROM result_mark_sheets WHERE workflow_status = 'hod_approved'");
        $r->execute();
        $wf_counts['publish'] = (int)$r->fetchColumn();
    }
    // Published sheets
    $pub_where  = "workflow_status = 'published'";
    $pub_params = [];
    if ($dept_scope !== null && !empty($dept_scope)) {
        $phs         = implode(',', array_fill(0, count($dept_scope), '?'));
        $pub_where  .= " AND dept_id IN ($phs)";
        $pub_params  = $dept_scope;
    } elseif ($dept_scope !== null && empty($dept_scope)) {
        $pub_where  .= ' AND 0=1';
    }
    $r = db()->prepare("SELECT COUNT(*) FROM result_mark_sheets WHERE $pub_where");
    $r->execute($pub_params);
    $wf_counts['published'] = (int)$r->fetchColumn();
} catch (Throwable $e) { /* tables may not exist yet on fresh installs */ }

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
        <?php if (wf_can_enter()): ?>
        <a href="<?= APP_URL ?>/results/mark-entry.php" class="btn btn-success" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-pen-nib me-1"></i> New Mark Sheet
        </a>
        <?php endif; ?>
        <?php if (rm_can_create()): ?>
        <button type="button" class="btn btn-outline-success"
                data-bs-toggle="modal" data-bs-target="#bulkImportModal"
                style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-magic me-1"></i> Bulk Import
        </button>
        <a href="<?= APP_URL ?>/results/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-plus me-1"></i> New Result Exam
        </a>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<!-- ── Workflow Dashboard Tabs ─────────────────────────────────────────────── -->
<?php if (wf_has_any_role()): ?>
<ul class="nav nav-tabs mb-4" id="resultTabs">
    <?php if (wf_can_enter()): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'my_sheets' ? 'active' : '' ?>"
           href="?tab=my_sheets">
            <i class="fas fa-pen-nib me-1"></i> My Sheets
            <?php if ($wf_counts['my_sheets'] > 0): ?>
            <span class="badge bg-secondary ms-1"><?= $wf_counts['my_sheets'] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if (wf_can_review()): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'review' ? 'active' : '' ?>"
           href="?tab=review">
            <i class="fas fa-search me-1"></i> Pending Review
            <?php if ($wf_counts['review'] > 0): ?>
            <span class="badge bg-primary ms-1"><?= $wf_counts['review'] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if (wf_can_hod()): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'hod' ? 'active' : '' ?>"
           href="?tab=hod">
            <i class="fas fa-user-tie me-1"></i> Pending HOD Approval
            <?php if ($wf_counts['hod'] > 0): ?>
            <span class="badge bg-warning text-dark ms-1"><?= $wf_counts['hod'] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
    <?php if (wf_can_publish()): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'publish' ? 'active' : '' ?>"
           href="?tab=publish">
            <i class="fas fa-check-double me-1"></i> Pending Publish
            <?php if ($wf_counts['publish'] > 0): ?>
            <span class="badge bg-success ms-1"><?= $wf_counts['publish'] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'published' ? 'active' : '' ?>"
           href="?tab=published">
            <i class="fas fa-globe me-1"></i> Published
            <?php if ($wf_counts['published'] > 0): ?>
            <span class="badge bg-success bg-opacity-75 ms-1"><?= $wf_counts['published'] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'legacy' ? 'active' : '' ?>"
           href="?tab=legacy">
            <i class="fas fa-archive me-1"></i> Legacy Exams
        </a>
    </li>
</ul>
<?php endif; ?>

<?php
// ── Render the active tab content ────────────────────────────────────────────
if ($active_tab !== 'legacy' && wf_has_any_role()):
    wf_render_tab($active_tab, $dept_scope);
    require_once __DIR__ . '/../includes/footer.php';
    exit;
endif;
// Fall through to legacy exam list
?>
<?php

/**
 * Render inline tab content for the workflow dashboard.
 */
function wf_render_tab(string $tab, ?array $dept_scope): void
{
    $where  = [];
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

    $user_id = (int)(auth_user()['id'] ?? 0);

    switch ($tab) {
        case 'my_sheets':
            $where[] = 'ms.created_by = ?';
            $params[] = $user_id;
            $order   = 'ms.updated_at DESC';
            break;
        case 'review':
            $where[] = "ms.workflow_status = 'submitted'";
            $order   = 'ms.submitted_at ASC';
            break;
        case 'hod':
            $where[] = "ms.workflow_status = 'under_review'";
            $order   = 'ms.reviewed_at ASC';
            break;
        case 'publish':
            $where[] = "ms.workflow_status = 'hod_approved'";
            $order   = 'ms.hod_approved_at ASC';
            break;
        case 'published':
        default:
            $where[] = "ms.workflow_status = 'published'";
            $order   = 'ms.published_at DESC';
            break;
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $stmt = db()->prepare(
            "SELECT ms.*,
                    d.name          AS dept_name,
                    p.program_name,
                    u_c.username    AS creator_name,
                    (SELECT COUNT(*) FROM result_sheet_grades g WHERE g.sheet_id = ms.id) AS student_count
             FROM result_mark_sheets ms
             JOIN dept_departments d             ON d.id = ms.dept_id
             LEFT JOIN dept_academic_programs p  ON p.id = ms.program_id
             LEFT JOIN users u_c                 ON u_c.id = ms.created_by
             $where_sql
             ORDER BY $order
             LIMIT 100"
        );
        $stmt->execute($params);
        $sheets = $stmt->fetchAll();
    } catch (Throwable $e) {
        echo '<div class="alert alert-warning">Workflow tables not yet created. Please run <code>results-workflow-migration.sql</code>.</div>';
        return;
    }
    ?>
    <div class="card" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-table me-2 text-muted"></i>
                <?php
                $labels = [
                    'my_sheets' => 'My Mark Sheets',
                    'review'    => 'Sheets Pending Review',
                    'hod'       => 'Sheets Pending HOD Approval',
                    'publish'   => 'Sheets Pending Publication',
                    'published' => 'Published Sheets',
                ];
                echo h($labels[$tab] ?? 'Sheets');
                ?>
            </h6>
            <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($sheets) ?></span>
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
                            <th class="text-center">Students</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($sheets)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-5">
                            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No sheets found.
                            <?php if ($tab === 'my_sheets' && wf_can_enter()): ?>
                                <a href="<?= APP_URL ?>/results/mark-entry.php">Create a new mark sheet</a>.
                            <?php endif; ?>
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
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= (int)$s['student_count'] ?></span>
                            </td>
                            <td><?= wf_status_badge($s['workflow_status']) ?></td>
                            <td class="text-end pe-4">
                                <div class="d-flex gap-1 justify-content-end flex-wrap">
                                    <?php if (in_array($s['workflow_status'], ['draft','returned'], true) && wf_can_edit_sheet($s)): ?>
                                    <a href="<?= APP_URL ?>/results/mark-entry.php?id=<?= $s['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($s['workflow_status'] === 'submitted' && wf_can_review()): ?>
                                    <a href="<?= APP_URL ?>/results/review-sheet.php?id=<?= $s['id'] ?>"
                                       class="btn btn-sm btn-primary" title="Review" style="border-radius:7px;">
                                        <i class="fas fa-search"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($s['workflow_status'] === 'under_review' && wf_can_hod()): ?>
                                    <a href="<?= APP_URL ?>/results/hod-review.php?id=<?= $s['id'] ?>"
                                       class="btn btn-sm btn-warning text-dark" title="HOD Review" style="border-radius:7px;">
                                        <i class="fas fa-user-tie"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($s['workflow_status'] === 'hod_approved' && wf_can_publish()): ?>
                                    <a href="<?= APP_URL ?>/results/controller-approve.php?id=<?= $s['id'] ?>"
                                       class="btn btn-sm btn-success" title="Publish" style="border-radius:7px;">
                                        <i class="fas fa-check-double"></i>
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
?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Department</label>
                <select name="dept_id" id="f_dept" class="form-select form-select-sm">
                    <option value="">All Depts</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Batch</label>
                <input type="text" name="batch" class="form-control form-control-sm"
                       placeholder="e.g. 52nd" value="<?= h($f_batch) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold" style="font-size:.8rem;">Status</label>
                <select name="published" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="1" <?= $f_pub === '1' ? 'selected' : '' ?>>Published</option>
                    <option value="0" <?= $f_pub === '0' ? 'selected' : '' ?>>Draft</option>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill" style="border-radius:7px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <a href="<?= APP_URL ?>/results/index.php" class="btn btn-outline-secondary btn-sm flex-fill" style="border-radius:7px;">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-chart-bar me-2 text-muted"></i>Result Exams</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= $total_rows ?> result<?= $total_rows !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Exam Title</th>
                        <th>Department / Program</th>
                        <th>Batch</th>
                        <th class="text-center">Subjects</th>
                        <th class="text-center">Students</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($exams)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">
                        No result exams found.
                        <?php if (rm_can_create()): ?>
                            <a href="<?= APP_URL ?>/results/create.php">Create the first one</a>.
                        <?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($exams as $i => $ex): ?>
                    <tr>
                        <td class="px-4"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="fw-medium"><?= h($ex['exam_title']) ?></div>
                            <?php if ($ex['exam_level']): ?>
                            <small class="text-muted"><?= h($ex['exam_level']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><?= h($ex['dept_name']) ?></div>
                            <?php if ($ex['program_name']): ?>
                            <small class="text-muted"><?= h($ex['program_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= h($ex['batch'] ?? '—') ?></td>
                        <td class="text-center">
                            <span class="badge bg-info text-dark"><?= (int)$ex['subject_count'] ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= (int)$ex['student_count'] ?></span>
                        </td>
                        <td>
                            <?php if ($ex['is_published']): ?>
                            <span class="badge bg-success">Published</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/results/view.php?id=<?= $ex['id'] ?>"
                                   class="btn btn-sm btn-outline-info" title="View / Enter Grades" style="border-radius:7px;">
                                    <i class="fas fa-table"></i>
                                </a>
                                <a href="<?= APP_URL ?>/results/print.php?id=<?= $ex['id'] ?>"
                                   target="_blank"
                                   class="btn btn-sm btn-outline-secondary" title="Print" style="border-radius:7px;">
                                    <i class="fas fa-print"></i>
                                </a>
                                <?php if (rm_is_staff()): ?>
                                <a href="<?= APP_URL ?>/results/bulk-upload.php?exam_id=<?= $ex['id'] ?>"
                                   class="btn btn-sm btn-outline-success" title="Smart Bulk Upload" style="border-radius:7px;">
                                    <i class="fas fa-magic"></i>
                                </a>
                                <a href="<?= APP_URL ?>/results/edit.php?id=<?= $ex['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (rm_can_delete()): ?>
                                <form method="POST" action="<?= APP_URL ?>/results/delete.php"
                                      onsubmit="return confirm('Delete result exam &quot;<?= h($ex['exam_title']) ?>&quot;? All subjects and grades will be removed.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?>
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $qp = $_GET;
                for ($p = 1; $p <= $total_pages; $p++):
                    $qp['page'] = $p;
                    $active = $p === $page;
                ?>
                <li class="page-item <?= $active ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query($qp) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>


<?php if (rm_can_create()): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     Quick Bulk Import Modal
     Uploads OCR result text → auto-detects dept/program/batch → creates exam
     → saves grades. No manual selection needed.
════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="bulkImportModal" tabindex="-1"
     aria-labelledby="bulkImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title fw-semibold" id="bulkImportModalLabel">
                    <i class="fas fa-magic me-2 text-success"></i>Quick Bulk Import
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">

                <!-- Step indicator -->
                <div class="d-flex align-items-center gap-2 mb-4">
                    <span id="qi_dot1" class="badge rounded-pill bg-primary px-3 py-2">1 · Upload</span>
                    <div style="flex:1;height:2px;background:#dee2e6;"></div>
                    <span id="qi_dot2" class="badge rounded-pill bg-secondary px-3 py-2">2 · Preview</span>
                    <div style="flex:1;height:2px;background:#dee2e6;"></div>
                    <span id="qi_dot3" class="badge rounded-pill bg-secondary px-3 py-2">3 · Done</span>
                </div>

                <!-- Inline alert -->
                <div id="qi_alert" class="alert mb-3" style="display:none;"></div>

                <!-- ── Step 1: Upload ── -->
                <div id="qi_step1">
                    <div class="alert alert-info mb-4" style="border-radius:10px;">
                        <i class="fas fa-bolt me-1"></i>
                        Upload an OCR-scanned result file <strong>or</strong> paste the text below.
                        Department, program, and batch are <strong>auto-detected</strong>
                        from student IDs — no manual selection needed.
                        <div class="mt-2 small text-muted">
                            Supported: <code>.txt</code> · <code>.docx</code> · <code>.doc</code>
                            · <code>.rtf</code> · <code>.odt</code>
                        </div>
                    </div>
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="card h-100" style="border-radius:12px;">
                                <div class="card-header py-3 px-4">
                                    <h6 class="mb-0 fw-semibold">
                                        <i class="fas fa-file-upload me-2 text-muted"></i>Upload File
                                    </h6>
                                </div>
                                <div class="card-body p-4 d-flex flex-column">
                                    <div id="qi_drop_zone"
                                         class="border border-2 border-dashed rounded-3 p-5 text-center flex-grow-1"
                                         style="border-color:#c8d0e0 !important;cursor:pointer;transition:background .2s;"
                                         onclick="document.getElementById('qi_file').click()">
                                        <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                        <div class="fw-medium">Drop file here or click to browse</div>
                                        <div class="small text-muted mt-1">
                                            .txt &nbsp;.docx &nbsp;.doc &nbsp;.rtf &nbsp;.odt
                                        </div>
                                        <div id="qi_file_name" class="mt-3 small text-primary fw-medium"></div>
                                    </div>
                                    <input type="file" id="qi_file" class="d-none"
                                           accept=".txt,.text,.doc,.docx,.rtf,.odt,.csv">
                                    <div class="text-center mt-2 small text-muted">Maximum 10 MB</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card h-100" style="border-radius:12px;">
                                <div class="card-header py-3 px-4">
                                    <h6 class="mb-0 fw-semibold">
                                        <i class="fas fa-paste me-2 text-muted"></i>Or Paste OCR Text
                                    </h6>
                                </div>
                                <div class="card-body p-4 d-flex flex-column">
                                    <textarea id="qi_raw_text" class="form-control flex-grow-1" rows="10"
                                              placeholder="Paste OCR-scanned result text here…

Example:
1. Student Name (ID: 193020101021)CGPA: 3.19 | Total Credits: 129
BEL-111English Reading & Public SpeakingB+3.25
..."
                                              style="font-size:.825rem;font-family:monospace;resize:vertical;"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Step 2: Preview ── -->
                <div id="qi_step2" style="display:none;">

                    <div id="qi_detected_banner" class="alert alert-success mb-4" style="border-radius:10px;"></div>

                    <div id="qi_warn_box" class="alert alert-warning mb-3" style="display:none;border-radius:10px;">
                        <strong><i class="fas fa-exclamation-triangle me-1"></i>Parser warnings</strong>
                        <ul id="qi_warn_list" class="mb-0 mt-1 ps-3" style="font-size:.875rem;"></ul>
                    </div>

                    <!-- Auto-detected fields -->
                    <div class="card mb-4" style="border-radius:12px;">
                        <div class="card-header py-3 px-4">
                            <h6 class="mb-0 fw-semibold">
                                <i class="fas fa-robot me-2 text-success"></i>Auto-detected Information
                            </h6>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-medium" style="font-size:.8rem;">Department</label>
                                    <input type="text" id="qi_dept_name" class="form-control form-control-sm"
                                           readonly placeholder="Will be detected on import">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-medium" style="font-size:.8rem;">Batch</label>
                                    <input type="text" id="qi_batch" class="form-control form-control-sm"
                                           readonly placeholder="Will be detected on import">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-medium" style="font-size:.8rem;">Students Parsed</label>
                                    <input type="text" id="qi_stu_count" class="form-control form-control-sm" readonly>
                                </div>
                            </div>
                            <div>
                                <label class="form-label fw-medium" style="font-size:.8rem;">
                                    Exam Title
                                    <span class="text-muted fw-normal">(auto-generated — you may edit)</span>
                                </label>
                                <input type="text" id="qi_exam_title" class="form-control"
                                       maxlength="300"
                                       placeholder="e.g. BSc CSE – Bulk Import 17 Apr 2026">
                            </div>
                        </div>
                    </div>

                    <!-- Parsed students preview -->
                    <div id="qi_students_preview" class="mb-4"></div>

                    <!-- Import options -->
                    <div class="card" style="border-radius:12px;">
                        <div class="card-body p-4">
                            <h6 class="fw-semibold mb-3">
                                <i class="fas fa-cog me-2 text-muted"></i>Import Options
                            </h6>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="qi_create_subjects" checked>
                                <label class="form-check-label" for="qi_create_subjects">
                                    <strong>Auto-create missing subjects</strong>
                                    <div class="small text-muted">
                                        Subjects found in the text but not yet in the exam will be added automatically.
                                    </div>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="qi_overwrite" checked>
                                <label class="form-check-label" for="qi_overwrite">
                                    <strong>Overwrite existing grades</strong>
                                    <div class="small text-muted">
                                        If a grade already exists for a student/subject it will be updated.
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Step 3: Done ── -->
                <div id="qi_step3" style="display:none;">
                    <div id="qi_result_card" class="text-center py-5"></div>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" id="qi_again_btn" class="btn btn-outline-secondary" style="display:none;">
                    <i class="fas fa-redo me-1"></i> Start Over
                </button>
                <button type="button" id="qi_parse_btn" class="btn btn-primary">
                    <i class="fas fa-search me-1"></i> Parse &amp; Preview
                </button>
                <button type="button" id="qi_import_btn" class="btn btn-success" style="display:none;">
                    <i class="fas fa-file-import me-1"></i>
                    Import <span id="qi_import_count">0</span> Student(s)
                </button>
            </div>

        </div>
    </div>
</div>

<!-- Full-screen loading overlay (shared with modal) -->
<div id="qi_overlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
            z-index:10060;justify-content:center;align-items:center;">
    <div class="bg-white rounded-3 p-4 text-center shadow-lg" style="min-width:220px;">
        <div class="spinner-border text-primary mb-3"></div>
        <div class="fw-medium" id="qi_overlay_msg">Processing…</div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var CSRF_TOKEN   = <?= json_encode(csrf_token()) ?>;
    var CSRF_NAME    = <?= json_encode(CSRF_TOKEN_NAME) ?>;
    var PARSE_URL    = '<?= APP_URL ?>/results/bulk-upload-parse.php';
    var IMPORT_URL   = '<?= APP_URL ?>/results/auto-import.php';

    var parsedStudents = [];

    // ── DOM refs ──────────────────────────────────────────────────────────────
    var overlay     = document.getElementById('qi_overlay');
    var alertBox    = document.getElementById('qi_alert');
    var step1       = document.getElementById('qi_step1');
    var step2       = document.getElementById('qi_step2');
    var step3       = document.getElementById('qi_step3');
    var parseBtn    = document.getElementById('qi_parse_btn');
    var importBtn   = document.getElementById('qi_import_btn');
    var againBtn    = document.getElementById('qi_again_btn');
    var fileInput   = document.getElementById('qi_file');
    var dropZone    = document.getElementById('qi_drop_zone');
    var rawText     = document.getElementById('qi_raw_text');
    var dot1        = document.getElementById('qi_dot1');
    var dot2        = document.getElementById('qi_dot2');
    var dot3        = document.getElementById('qi_dot3');

    // ── Helpers ───────────────────────────────────────────────────────────────

    function esc(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function showOverlay(msg) {
        document.getElementById('qi_overlay_msg').textContent = msg || 'Processing…';
        overlay.style.display = 'flex';
    }
    function hideOverlay() { overlay.style.display = 'none'; }

    function showAlert(msg, type) {
        alertBox.className = 'alert alert-' + (type || 'danger') + ' mb-3';
        alertBox.innerHTML = msg;
        alertBox.style.display = '';
    }
    function clearAlert() { alertBox.style.display = 'none'; }

    function gradeColor(l) {
        return {
            'A+':'success','A':'success','A-':'success',
            'B+':'primary','B':'primary','B-':'primary',
            'C+':'warning','C':'warning',
            'D':'danger','F':'danger'
        }[l] || 'secondary';
    }

    function setStep(n) {
        step1.style.display = n === 1 ? '' : 'none';
        step2.style.display = n === 2 ? '' : 'none';
        step3.style.display = n === 3 ? '' : 'none';
        parseBtn.style.display  = n === 1 ? '' : 'none';
        importBtn.style.display = n === 2 ? '' : 'none';
        againBtn.style.display  = n >= 2 ? '' : 'none';
        dot1.className = 'badge rounded-pill px-3 py-2 ' + (n >= 1 ? 'bg-primary' : 'bg-secondary');
        dot2.className = 'badge rounded-pill px-3 py-2 ' + (n >= 2 ? 'bg-primary' : 'bg-secondary');
        dot3.className = 'badge rounded-pill px-3 py-2 ' + (n >= 3 ? 'bg-success' : 'bg-secondary');
    }

    function updateImportCount() {
        var n = document.querySelectorAll('#qi_students_preview .qi_stu_chk:checked').length;
        document.getElementById('qi_import_count').textContent = n;
        importBtn.disabled = n === 0;
    }

    function buildStudentCard(s, idx) {
        var gradeRows = (s.grades || []).map(function (g, gi) {
            return '<tr>' +
                '<td class="ps-3 text-muted" style="width:30px;">' + (gi + 1) + '</td>' +
                '<td>' + (g.code
                    ? '<span class="badge bg-light text-dark border">' + esc(g.code) + '</span>'
                    : '<span class="text-muted small">—</span>') + '</td>' +
                '<td class="text-truncate" style="max-width:200px;">' + esc(g.title || '—') + '</td>' +
                '<td class="text-center"><span class="badge bg-' + gradeColor(g.letter) + '">' + esc(g.letter) + '</span></td>' +
                '<td class="text-center text-muted">' + (g.gp !== undefined ? (+g.gp).toFixed(2) : '—') + '</td>' +
                '</tr>';
        }).join('');

        return '<div class="card mb-2" style="border-radius:10px;">' +
            '<div class="card-header py-2 px-3 d-flex align-items-center gap-2 flex-wrap">' +
            '<input type="checkbox" class="form-check-input qi_stu_chk" data-idx="' + idx + '" checked style="cursor:pointer;">' +
            '<div class="fw-semibold">' + esc(s.name) + '</div>' +
            '<code class="text-primary small">' + esc(s.sid) + '</code>' +
            (s.cgpa !== null && s.cgpa !== undefined
                ? '<span class="badge bg-info text-dark ms-1">CGPA: ' + (+s.cgpa).toFixed(2) + '</span>'
                : '') +
            '<span class="badge bg-secondary ms-1">' + (s.grades || []).length + ' grades</span>' +
            '<button type="button" class="btn btn-sm btn-link py-0 ms-auto qi-toggle-grades" ' +
            'data-target="qi_grades_' + idx + '" title="Toggle grades">' +
            '<i class="fas fa-chevron-down"></i></button>' +
            '</div>' +
            '<div class="d-none" id="qi_grades_' + idx + '">' +
            '<div class="table-responsive">' +
            '<table class="table table-sm mb-0 align-middle" style="font-size:.8rem;">' +
            '<thead class="table-light"><tr>' +
            '<th class="ps-3" style="width:30px;">#</th>' +
            '<th style="width:90px;">Code</th><th>Title</th>' +
            '<th class="text-center" style="width:65px;">Grade</th>' +
            '<th class="text-center" style="width:55px;">GP</th>' +
            '</tr></thead><tbody>' + gradeRows + '</tbody>' +
            '</table></div></div></div>';
    }

    // ── Reset modal on close ──────────────────────────────────────────────────

    document.getElementById('bulkImportModal').addEventListener('hidden.bs.modal', function () {
        setStep(1);
        clearAlert();
        parsedStudents = [];
        rawText.value  = '';
        fileInput.value = '';
        document.getElementById('qi_file_name').textContent = '';
        document.getElementById('qi_students_preview').innerHTML = '';
    });

    // ── File input ────────────────────────────────────────────────────────────

    fileInput.addEventListener('change', function () {
        document.getElementById('qi_file_name').textContent = this.files[0] ? '📄 ' + this.files[0].name : '';
        if (this.files[0]) rawText.value = '';
    });

    // Drag-and-drop
    ['dragover', 'dragenter'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropZone.style.background = '#eef2ff';
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropZone.style.background = '';
            if (evt === 'drop' && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                document.getElementById('qi_file_name').textContent = '📄 ' + e.dataTransfer.files[0].name;
                rawText.value = '';
            }
        });
    });

    // ── STEP 1 → Parse ────────────────────────────────────────────────────────

    parseBtn.addEventListener('click', function () {
        clearAlert();
        var hasFile = fileInput.files && fileInput.files.length > 0;
        var hasText = rawText.value.trim().length > 5;

        if (!hasFile && !hasText) {
            showAlert('<i class="fas fa-exclamation-circle me-1"></i>Please upload a file or paste OCR text first.');
            return;
        }

        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_TOKEN);
        if (hasFile) fd.append('ocr_file', fileInput.files[0]);
        else         fd.append('raw_text', rawText.value);

        showOverlay('Parsing OCR text…');

        fetch(PARSE_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                hideOverlay();
                if (data.error) {
                    showAlert('<i class="fas fa-times-circle me-1"></i>' + esc(data.error));
                    return;
                }

                parsedStudents = data.students || [];
                if (parsedStudents.length === 0) {
                    var msg = (data.warnings || []).join(' | ') || 'No student entries detected.';
                    showAlert('<i class="fas fa-exclamation-triangle me-1"></i>' + esc(msg));
                    return;
                }

                // Warnings
                var warnBox  = document.getElementById('qi_warn_box');
                var warnList = document.getElementById('qi_warn_list');
                if (data.warnings && data.warnings.length) {
                    warnList.innerHTML = data.warnings.map(function (w) {
                        return '<li>' + esc(w) + '</li>';
                    }).join('');
                    warnBox.style.display = '';
                } else {
                    warnBox.style.display = 'none';
                }

                // Populate preview
                var totalGrades = parsedStudents.reduce(function (n, s) {
                    return n + (s.grades || []).length;
                }, 0);

                document.getElementById('qi_detected_banner').innerHTML =
                    '<i class="fas fa-check-circle me-2"></i>' +
                    '<strong>' + parsedStudents.length + ' student(s)</strong> detected with ' +
                    '<strong>' + totalGrades + '</strong> grade entries. ' +
                    'Department, program, and batch will be <strong>auto-detected</strong> ' +
                    'from the student database when you click Import.';

                document.getElementById('qi_stu_count').value    = parsedStudents.length + ' student(s)';
                document.getElementById('qi_dept_name').value    = 'Auto-detecting on import…';
                document.getElementById('qi_batch').value        = 'Auto-detecting on import…';

                // Auto-generate exam title
                var now = new Date();
                var months = ['Jan','Feb','Mar','Apr','May','Jun',
                              'Jul','Aug','Sep','Oct','Nov','Dec'];
                document.getElementById('qi_exam_title').value =
                    'Bulk Import – ' +
                    String(now.getDate()).padStart(2, '0') + ' ' +
                    months[now.getMonth()] + ' ' + now.getFullYear();

                // Build student cards
                var preview = document.getElementById('qi_students_preview');
                preview.innerHTML = parsedStudents.map(buildStudentCard).join('');

                // Toggle grade rows
                preview.querySelectorAll('.qi-toggle-grades').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var tgt  = document.getElementById(this.dataset.target);
                        var icon = this.querySelector('i');
                        if (tgt.classList.contains('d-none')) {
                            tgt.classList.remove('d-none');
                            icon.className = 'fas fa-chevron-up';
                        } else {
                            tgt.classList.add('d-none');
                            icon.className = 'fas fa-chevron-down';
                        }
                    });
                });

                // Student checkboxes
                preview.querySelectorAll('.qi_stu_chk').forEach(function (chk) {
                    chk.addEventListener('change', updateImportCount);
                });

                updateImportCount();
                setStep(2);
            })
            .catch(function (err) {
                hideOverlay();
                showAlert('<i class="fas fa-times-circle me-1"></i>Parse error: ' + esc(String(err)));
            });
    });

    // ── STEP 2 → Import ───────────────────────────────────────────────────────

    importBtn.addEventListener('click', function () {
        clearAlert();

        var selected = [];
        document.querySelectorAll('#qi_students_preview .qi_stu_chk:checked').forEach(function (chk) {
            selected.push(parsedStudents[parseInt(chk.dataset.idx, 10)]);
        });

        if (!selected.length) {
            showAlert('No students selected for import.');
            return;
        }

        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_TOKEN);
        fd.append('students_json',  JSON.stringify(selected));
        fd.append('exam_title',     document.getElementById('qi_exam_title').value);
        fd.append('create_subjects', document.getElementById('qi_create_subjects').checked ? '1' : '0');
        fd.append('overwrite',       document.getElementById('qi_overwrite').checked       ? '1' : '0');

        showOverlay('Importing grades…');

        fetch(IMPORT_URL, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                hideOverlay();
                if (data.error) {
                    showAlert('<i class="fas fa-times-circle me-1"></i>' + esc(data.error));
                    return;
                }

                // Update detected fields
                document.getElementById('qi_dept_name').value = data.dept_name || '—';
                document.getElementById('qi_batch').value     = data.batch     || '—';

                var hasErr = data.errors && data.errors.length > 0;
                var errHtml = '';
                if (hasErr) {
                    errHtml = '<div class="alert alert-warning text-start mt-3" style="font-size:.8rem;">' +
                        '<strong>Notices:</strong><ul class="mb-0 mt-1 ps-3">' +
                        data.errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') +
                        '</ul></div>';
                }

                function stat(icon, val, label, color) {
                    return '<div class="col-auto">' +
                        '<div class="card border-0 shadow-sm px-4 py-3" style="border-radius:10px;">' +
                        '<div class="fw-bold fs-3 text-' + color + '">' + (val || 0) + '</div>' +
                        '<div class="small text-muted"><i class="' + icon + ' me-1"></i>' + label + '</div>' +
                        '</div></div>';
                }

                document.getElementById('qi_result_card').innerHTML =
                    '<div class="text-' + (hasErr ? 'warning' : 'success') + ' mb-3">' +
                    '<i class="fas fa-' + (hasErr ? 'exclamation-triangle' : 'check-circle') + ' fa-4x"></i>' +
                    '</div>' +
                    '<h4 class="fw-bold mb-1">' + (hasErr ? 'Import completed with notices' : 'Import successful!') + '</h4>' +
                    '<p class="text-muted mb-1">Exam: <strong>' + esc(data.exam_title) + '</strong></p>' +
                    '<p class="text-muted mb-1">Department: <strong>' + esc(data.dept_name || '—') + '</strong></p>' +
                    '<p class="text-muted mb-3">Batch: <strong>' + esc(data.batch || '—') + '</strong></p>' +
                    '<div class="row justify-content-center g-3 mb-3">' +
                    stat('fas fa-star',        data.saved,            'Grades Saved',     'success') +
                    stat('fas fa-forward',     data.skipped,          'Skipped',          'secondary') +
                    stat('fas fa-plus-circle', data.created_subjects, 'Subjects Created', 'info') +
                    '</div>' +
                    errHtml +
                    '<div class="mt-4 d-flex gap-2 justify-content-center">' +
                    '<a href="' + esc(data.redirect) + '" class="btn btn-primary" style="border-radius:10px;">' +
                    '<i class="fas fa-table me-1"></i>View Result Sheet</a>' +
                    '<button type="button" onclick="document.getElementById(\'qi_again_btn\').click()" ' +
                    'class="btn btn-outline-secondary" style="border-radius:10px;">' +
                    '<i class="fas fa-upload me-1"></i>Import More</button>' +
                    '</div>';

                setStep(3);
            })
            .catch(function (err) {
                hideOverlay();
                showAlert('<i class="fas fa-times-circle me-1"></i>Import error: ' + esc(String(err)));
            });
    });

    // ── Start Over ────────────────────────────────────────────────────────────

    againBtn.addEventListener('click', function () {
        parsedStudents = [];
        clearAlert();
        rawText.value = '';
        fileInput.value = '';
        document.getElementById('qi_file_name').textContent = '';
        document.getElementById('qi_students_preview').innerHTML = '';
        setStep(1);
    });

})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
