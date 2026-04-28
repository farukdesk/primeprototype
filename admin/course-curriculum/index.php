<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

$page_title = 'Course Curriculum';

// Resolve selected department, program, and intake from GET
$sel_dept    = (int)($_GET['dept_id']    ?? 0);
$sel_program = (int)($_GET['program_id'] ?? 0);
$sel_intake  = (int)($_GET['intake_id']  ?? 0);

$departments = cc_departments();
$programs    = $sel_dept > 0 ? cc_programs($sel_dept) : [];

// Validate program
$program_row = null;
if ($sel_program > 0 && $sel_dept > 0) {
    $st = db()->prepare(
        "SELECT p.*, d.name AS dept_name
           FROM dept_academic_programs p
           JOIN dept_departments d ON d.id = p.dept_id
          WHERE p.id = ? AND p.dept_id = ? AND p.is_active = 1
          LIMIT 1"
    );
    $st->execute([$sel_program, $sel_dept]);
    $program_row = $st->fetch() ?: null;
}

// Validate intake (must belong to the selected program)
$intake_row  = null;
$curriculum  = [];
if ($program_row && $sel_intake > 0) {
    $intake_row = cc_get_intake($sel_intake);
    if ($intake_row && (int)$intake_row['program_id'] !== $sel_program) {
        $intake_row = null; // security: intake doesn't belong to this program
    }
    if ($intake_row) {
        $curriculum = cc_get_curriculum($sel_program, $sel_intake);
    }
}

// Intake list (shown when program selected but no intake yet)
$intakes = [];
if ($program_row && !$intake_row) {
    $intakes = cc_get_intakes($sel_program);
}

$semester_labels = cc_semester_labels();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── Breadcrumb & top action ────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <?php if ($intake_row): ?>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>">
                    Course Curriculum
                </a>
            </li>
            <li class="breadcrumb-item active"><?= h($intake_row['batch_name']) ?></li>
            <?php else: ?>
            <li class="breadcrumb-item active">Course Curriculum</li>
            <?php endif; ?>
        </ol>
    </nav>
    <?php if ($program_row && !$intake_row && cc_is_staff()): ?>
    <a href="<?= APP_URL ?>/course-curriculum/intake-create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>"
       class="btn btn-primary btn-sm">
        <i class="fas fa-plus me-1"></i> New Intake / Batch
    </a>
    <?php endif; ?>
    <?php if ($intake_row && cc_is_staff()): ?>
    <a href="<?= APP_URL ?>/course-curriculum/create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>&intake_id=<?= $sel_intake ?>"
       class="btn btn-primary btn-sm">
        <i class="fas fa-plus me-1"></i> Add Course
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<!-- ── Selector card ───────────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-filter me-2 text-muted"></i>Select Department &amp; Program
        </h6>
    </div>
    <div class="card-body p-4">
        <form method="GET" action="" id="selector-form" class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label fw-medium">Department <span class="text-danger">*</span></label>
                <select name="dept_id" id="dept_select" class="form-select" required>
                    <option value="">— Select Department —</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $d['id'] == $sel_dept ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label fw-medium">Program <span class="text-danger">*</span></label>
                <select name="program_id" id="prog_select" class="form-select" required
                        <?= $sel_dept ? '' : 'disabled' ?>>
                    <option value="">— Select Program —</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $sel_program ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-eye me-1"></i> View
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($sel_dept && $sel_program && !$program_row): ?>
<div class="alert alert-warning">Program not found or inactive.</div>
<?php endif; ?>

<?php if ($program_row && !$intake_row): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     INTAKE LIST VIEW — shown when a program is selected but no intake yet
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- Program header -->
<div class="card mb-4" style="border-radius:12px; border-left:4px solid #002147;">
    <div class="card-body px-4 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-1 fw-bold" style="color:#002147;"><?= h($program_row['program_name']) ?></h5>
            <span class="text-muted small">
                <i class="fas fa-building me-1"></i><?= h($program_row['dept_name']) ?>
                <?php if (!empty($program_row['degree_type'])): ?>
                &nbsp;·&nbsp;<span class="badge bg-secondary"><?= h($program_row['degree_type']) ?></span>
                <?php endif; ?>
                <?php if (!empty($program_row['duration'])): ?>
                &nbsp;·&nbsp;<?= h($program_row['duration']) ?>
                <?php endif; ?>
            </span>
        </div>
        <?php if (cc_is_staff()): ?>
        <a href="<?= APP_URL ?>/course-curriculum/intake-create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>"
           class="btn btn-success btn-sm">
            <i class="fas fa-plus me-1"></i> New Intake / Batch
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Intake list -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-layer-group me-2 text-muted"></i>Semester Intakes / Batches
        </h6>
        <span class="badge bg-secondary"><?= count($intakes) ?> intake<?= count($intakes) !== 1 ? 's' : '' ?></span>
    </div>
    <?php if (empty($intakes)): ?>
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-folder-open fa-3x mb-3 d-block" style="opacity:.25;"></i>
        No intakes yet.
        <?php if (cc_is_staff()): ?>
        <a href="<?= APP_URL ?>/course-curriculum/intake-create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>">
            Create the first one
        </a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle" style="font-size:14px;">
            <thead style="background-color:#F1F5F9;">
                <tr>
                    <th class="ps-4" style="width:40px;">#</th>
                    <th>Batch / Intake Name</th>
                    <th style="width:80px;" class="text-center">Year</th>
                    <th style="width:90px;" class="text-center">Season</th>
                    <th style="width:90px;" class="text-center">Courses</th>
                    <th style="width:90px;" class="text-center">Credits</th>
                    <th style="width:110px;" class="text-center">Status</th>
                    <?php if (cc_is_staff()): ?>
                    <th style="width:160px;" class="text-end pe-4">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($intakes as $i => $itk): ?>
                <tr>
                    <td class="ps-4 text-muted"><?= $i + 1 ?></td>
                    <td>
                        <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>&intake_id=<?= $itk['id'] ?>"
                           class="fw-semibold text-decoration-none" style="color:#002147;">
                            <?= h($itk['batch_name']) ?>
                        </a>
                        <?php if (!empty($itk['notes'])): ?>
                        <div class="text-muted small"><?= h(mb_strimwidth($itk['notes'], 0, 80, '…')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= $itk['intake_year'] ? h($itk['intake_year']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center">
                        <?php if ($itk['intake_season']): ?>
                        <span class="badge bg-light text-dark border"><?= h($itk['intake_season']) ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int)$itk['course_count'] ?></td>
                    <td class="text-center fw-medium" style="color:#002147;">
                        <?= $itk['total_credits'] !== null ? number_format((float)$itk['total_credits'], 2) : '—' ?>
                    </td>
                    <td class="text-center">
                        <?php if ($itk['is_published']): ?>
                        <span class="badge" style="background-color:#198754;">
                            <i class="fas fa-globe me-1"></i>Published
                        </span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Draft</span>
                        <?php endif; ?>
                    </td>
                    <?php if (cc_is_staff()): ?>
                    <td class="text-end pe-4">
                        <!-- View -->
                        <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>&intake_id=<?= $itk['id'] ?>"
                           class="btn btn-sm btn-outline-primary me-1" title="View curriculum">
                            <i class="fas fa-eye"></i>
                        </a>
                        <!-- Edit -->
                        <a href="<?= APP_URL ?>/course-curriculum/intake-edit.php?id=<?= $itk['id'] ?>&dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>"
                           class="btn btn-sm btn-outline-secondary me-1" title="Edit intake">
                            <i class="fas fa-edit"></i>
                        </a>
                        <!-- Publish toggle -->
                        <button type="button"
                                class="btn btn-sm <?= $itk['is_published'] ? 'btn-success' : 'btn-outline-success' ?> me-1"
                                title="<?= $itk['is_published'] ? 'Unpublish' : 'Publish' ?>"
                                data-bs-toggle="modal" data-bs-target="#publishModal"
                                data-id="<?= $itk['id'] ?>"
                                data-name="<?= h($itk['batch_name']) ?>"
                                data-published="<?= $itk['is_published'] ?>">
                            <i class="fas fa-globe"></i>
                        </button>
                        <!-- Delete -->
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                title="Delete intake"
                                data-bs-toggle="modal" data-bs-target="#deleteIntakeModal"
                                data-id="<?= $itk['id'] ?>"
                                data-name="<?= h($itk['batch_name']) ?>"
                                data-courses="<?= (int)$itk['course_count'] ?>">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (cc_is_staff()): ?>
<!-- Publish/Unpublish modal -->
<div class="modal fade" id="publishModal" tabindex="-1" aria-labelledby="publishModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header">
                <h5 class="modal-title" id="publishModalLabel">
                    <i class="fas fa-globe text-success me-2"></i><span id="pub-title">Publish Intake</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="pub-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= APP_URL ?>/course-curriculum/intake-publish.php" id="pub-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" id="pub-id">
                    <input type="hidden" name="dept_id" value="<?= $sel_dept ?>">
                    <input type="hidden" name="program_id" value="<?= $sel_program ?>">
                    <button type="submit" class="btn btn-success" id="pub-btn">
                        <i class="fas fa-globe me-1"></i>Publish
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Intake modal -->
<div class="modal fade" id="deleteIntakeModal" tabindex="-1" aria-labelledby="deleteIntakeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteIntakeModalLabel">
                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>Delete Intake
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="del-intake-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= APP_URL ?>/course-curriculum/intake-delete.php" id="del-intake-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" id="del-intake-id">
                    <input type="hidden" name="dept_id" value="<?= $sel_dept ?>">
                    <input type="hidden" name="program_id" value="<?= $sel_program ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php elseif ($intake_row): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     CURRICULUM VIEW — shown when a specific intake is selected
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- Intake / program header -->
<div class="card mb-4" style="border-radius:12px; border-left:4px solid #002147;">
    <div class="card-body px-4 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <h5 class="mb-0 fw-bold" style="color:#002147;"><?= h($intake_row['batch_name']) ?></h5>
                <?php if ($intake_row['is_published']): ?>
                <span class="badge" style="background-color:#198754;"><i class="fas fa-globe me-1"></i>Published</span>
                <?php else: ?>
                <span class="badge bg-secondary">Draft</span>
                <?php endif; ?>
            </div>
            <span class="text-muted small">
                <i class="fas fa-graduation-cap me-1"></i><?= h($program_row['program_name']) ?>
                &nbsp;·&nbsp;<i class="fas fa-building me-1"></i><?= h($program_row['dept_name']) ?>
                <?php if ($intake_row['intake_year'] || $intake_row['intake_season']): ?>
                &nbsp;·&nbsp;
                <?= $intake_row['intake_season'] ? h($intake_row['intake_season']) . ' ' : '' ?><?= $intake_row['intake_year'] ? h($intake_row['intake_year']) : '' ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (cc_is_staff()): ?>
            <a href="<?= APP_URL ?>/course-curriculum/intake-edit.php?id=<?= $sel_intake ?>&dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>"
               class="btn btn-outline-secondary btn-sm" title="Edit this intake">
                <i class="fas fa-edit me-1"></i>Edit Intake
            </a>
            <?php if (!$intake_row['is_published']): ?>
            <form method="POST" action="<?= APP_URL ?>/course-curriculum/intake-publish.php" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $sel_intake ?>">
                <input type="hidden" name="dept_id" value="<?= $sel_dept ?>">
                <input type="hidden" name="program_id" value="<?= $sel_program ?>">
                <input type="hidden" name="intake_id" value="<?= $sel_intake ?>">
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="fas fa-globe me-1"></i>Publish
                </button>
            </form>
            <?php else: ?>
            <form method="POST" action="<?= APP_URL ?>/course-curriculum/intake-publish.php" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $sel_intake ?>">
                <input type="hidden" name="dept_id" value="<?= $sel_dept ?>">
                <input type="hidden" name="program_id" value="<?= $sel_program ?>">
                <input type="hidden" name="intake_id" value="<?= $sel_intake ?>">
                <button type="submit" class="btn btn-outline-success btn-sm">
                    <i class="fas fa-globe me-1"></i>Unpublish
                </button>
            </form>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/course-curriculum/create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>&intake_id=<?= $sel_intake ?>"
               class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i>Add Course
            </a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>"
               class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i>All Intakes
            </a>
        </div>
    </div>
</div>

<?php
// Calculate total credits for this intake
$total_credits = 0;
foreach ($curriculum as $sem_rows) {
    foreach ($sem_rows as $r) {
        $total_credits += (float)($r['credit'] ?? 0);
    }
}
$total_courses = array_sum(array_map('count', $curriculum));
?>
<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px;">
            <div class="card-body py-3">
                <div class="fw-bold fs-4" style="color:#002147;"><?= count($curriculum) ?></div>
                <div class="small text-muted">Semesters with Courses</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px;">
            <div class="card-body py-3">
                <div class="fw-bold fs-4" style="color:#002147;"><?= $total_courses ?></div>
                <div class="small text-muted">Total Courses</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px;">
            <div class="card-body py-3">
                <div class="fw-bold fs-4" style="color:#D21034;"><?= number_format($total_credits, 2) ?></div>
                <div class="small text-muted">Total Credits</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0 shadow-sm" style="border-radius:10px;">
            <div class="card-body py-3">
                <div class="fw-bold fs-4" style="color:#002147;">12</div>
                <div class="small text-muted">Total Semesters</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Semester sections ───────────────────────────────────────────────────── -->
<?php foreach ($semester_labels as $sem_no => $sem_label): ?>
<?php $rows = $curriculum[$sem_no] ?? []; ?>
<div class="card mb-3" style="border-radius:12px;" id="sem-<?= $sem_no ?>">
    <div class="card-header d-flex justify-content-between align-items-center px-4 py-3"
         style="background-color:#002147; border-radius:12px 12px 0 0; cursor:pointer;"
         data-bs-toggle="collapse" data-bs-target="#sem-body-<?= $sem_no ?>"
         aria-expanded="<?= !empty($rows) ? 'true' : 'false' ?>">
        <div class="d-flex align-items-center gap-3">
            <span class="badge rounded-pill" style="background-color:#D21034; font-size:12px; min-width:28px;"><?= $sem_no ?></span>
            <span class="fw-semibold text-white"><?= h($sem_label) ?></span>
            <?php if (!empty($rows)): ?>
            <span class="badge bg-light text-dark small">
                <?= count($rows) ?> course<?= count($rows) !== 1 ? 's' : '' ?>
                &nbsp;·&nbsp;
                <?= number_format(array_sum(array_column($rows, 'credit')), 2) ?> cr
            </span>
            <?php else: ?>
            <span class="badge bg-secondary small">No courses yet</span>
            <?php endif; ?>
        </div>
        <?php if (cc_is_staff()): ?>
        <a href="<?= APP_URL ?>/course-curriculum/create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>&intake_id=<?= $sel_intake ?>&semester=<?= $sem_no ?>"
           class="btn btn-sm btn-outline-light"
           onclick="event.stopPropagation();"
           title="Add course to <?= h($sem_label) ?>">
            <i class="fas fa-plus"></i>
        </a>
        <?php endif; ?>
    </div>
    <div class="collapse <?= !empty($rows) ? 'show' : '' ?>" id="sem-body-<?= $sem_no ?>">
        <?php if (empty($rows)): ?>
        <div class="card-body text-center text-muted py-4 small">
            No courses added for this semester yet.
            <?php if (cc_is_staff()): ?>
            <a href="<?= APP_URL ?>/course-curriculum/create.php?dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>&intake_id=<?= $sel_intake ?>&semester=<?= $sem_no ?>">Add one</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" style="font-size:14px;">
                <thead style="background-color:#F1F5F9;">
                    <tr>
                        <th style="width:60px;" class="ps-4">SL</th>
                        <th style="width:120px;">BNQF Code</th>
                        <th style="width:120px;">Course Code</th>
                        <th>Course Name</th>
                        <th style="width:80px;" class="text-center">Credit</th>
                        <?php if (cc_is_staff()): ?>
                        <th style="width:100px;" class="text-end pe-4">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="ps-4"><?= h($row['sl_no']) ?></td>
                        <td><?= $row['bnqf_code'] ? h($row['bnqf_code']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $row['course_code'] ? '<span class="badge bg-light text-dark border">' . h($row['course_code']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="fw-medium"><?= h($row['course_name']) ?></td>
                        <td class="text-center">
                            <?= $row['credit'] !== null
                                ? '<span class="badge" style="background-color:#002147;">' . h(rtrim(rtrim(number_format((float)$row['credit'], 2), '0'), '.')) . '</span>'
                                : '<span class="text-muted">—</span>' ?>
                        </td>
                        <?php if (cc_is_staff()): ?>
                        <td class="text-end pe-4">
                            <a href="<?= APP_URL ?>/course-curriculum/edit.php?id=<?= $row['id'] ?>&dept_id=<?= $sel_dept ?>&program_id=<?= $sel_program ?>&intake_id=<?= $sel_intake ?>"
                               class="btn btn-sm btn-outline-primary me-1" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    title="Delete"
                                    data-bs-toggle="modal" data-bs-target="#deleteModal"
                                    data-id="<?= $row['id'] ?>"
                                    data-name="<?= h($row['course_name']) ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="background-color:#F8FAFC;">
                    <tr>
                        <td colspan="4" class="ps-4 text-muted small">
                            <?= count($rows) ?> course<?= count($rows) !== 1 ? 's' : '' ?>
                        </td>
                        <td class="text-center fw-bold small" style="color:#002147;">
                            <?= number_format(array_sum(array_column($rows, 'credit')), 2) ?>
                        </td>
                        <?php if (cc_is_staff()): ?><td></td><?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if (cc_is_staff()): ?>
<!-- Delete course confirmation modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="fas fa-exclamation-triangle text-danger me-2"></i>Delete Course
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete <strong id="del-name"></strong>? This action cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= APP_URL ?>/course-curriculum/delete.php" id="del-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" id="del-id">
                    <input type="hidden" name="dept_id" value="<?= $sel_dept ?>">
                    <input type="hidden" name="program_id" value="<?= $sel_program ?>">
                    <input type="hidden" name="intake_id" value="<?= $sel_intake ?>">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php elseif (!$sel_dept || !$sel_program): ?>
<div class="text-center py-5 text-muted">
    <i class="fas fa-graduation-cap fa-3x mb-3 d-block" style="color:#002147; opacity:.3;"></i>
    Select a department and program above to view or manage its course curriculum.
</div>
<?php endif; ?>

<script>
(function () {
    var deptSel = document.getElementById('dept_select');
    var progSel = document.getElementById('prog_select');
    var savedProg = <?= $sel_program ?: 0 ?>;

    deptSel.addEventListener('change', function () {
        var deptId = this.value;
        progSel.innerHTML = '<option value="">Loading…</option>';
        progSel.disabled = true;
        if (!deptId) {
            progSel.innerHTML = '<option value="">— Select Program —</option>';
            return;
        }
        fetch('<?= APP_URL ?>/course-curriculum/get-programs.php?dept_id=' + encodeURIComponent(deptId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                progSel.innerHTML = '<option value="">— Select Program —</option>';
                data.forEach(function (p) {
                    var opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = p.program_name;
                    if (p.id == savedProg) opt.selected = true;
                    progSel.appendChild(opt);
                });
                progSel.disabled = false;
            })
            .catch(function () {
                progSel.innerHTML = '<option value="">— Error loading programs —</option>';
                progSel.disabled = false;
            });
    });

    <?php if ($sel_dept): ?>
    progSel.disabled = false;
    <?php endif; ?>

    // Delete course modal population
    var delModal = document.getElementById('deleteModal');
    if (delModal) {
        delModal.addEventListener('show.bs.modal', function (e) {
            var btn = e.relatedTarget;
            document.getElementById('del-id').value        = btn.dataset.id;
            document.getElementById('del-name').textContent = btn.dataset.name;
        });
    }

    // Publish modal population
    var pubModal = document.getElementById('publishModal');
    if (pubModal) {
        pubModal.addEventListener('show.bs.modal', function (e) {
            var btn        = e.relatedTarget;
            var id         = btn.dataset.id;
            var name       = btn.dataset.name;
            var published  = btn.dataset.published === '1';
            document.getElementById('pub-id').value         = id;
            document.getElementById('pub-title').textContent = published ? 'Unpublish Intake' : 'Publish Intake';
            document.getElementById('pub-body').innerHTML    = published
                ? 'Unpublish <strong>' + name + '</strong>? It will no longer be visible on the public site.'
                : 'Publish <strong>' + name + '</strong>? Any currently published intake for this program will be unpublished.';
            var btn2 = document.getElementById('pub-btn');
            btn2.textContent = published ? 'Unpublish' : 'Publish';
            btn2.className   = published ? 'btn btn-warning' : 'btn btn-success';
            document.getElementById('pub-form').querySelector('input[name="id"]').value = id;
        });
    }

    // Delete intake modal population
    var delIntakeModal = document.getElementById('deleteIntakeModal');
    if (delIntakeModal) {
        delIntakeModal.addEventListener('show.bs.modal', function (e) {
            var btn     = e.relatedTarget;
            var name    = btn.dataset.name;
            var courses = btn.dataset.courses;
            document.getElementById('del-intake-id').value     = btn.dataset.id;
            document.getElementById('del-intake-body').innerHTML =
                'Are you sure you want to delete the intake <strong>' + name + '</strong>?'
                + (parseInt(courses) > 0
                    ? ' This will also permanently delete <strong>' + courses + ' course(s)</strong> in this intake.'
                    : '')
                + ' This action cannot be undone.';
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
