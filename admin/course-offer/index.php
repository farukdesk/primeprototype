<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

if (!can_access('course-offer')) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

$page_title = 'Course Offer';

// ── Filters ───────────────────────────────────────────────────────────────────
$f_dept_id        = (int)($_GET['dept_id']         ?? 0);
$f_program_id     = (int)($_GET['program_id']      ?? 0);
$f_batch_id       = (int)($_GET['batch_id']        ?? 0);
$f_semester       = trim($_GET['semester']         ?? '');
$f_academic_intake = trim($_GET['academic_intake'] ?? '');
$f_status         = $_GET['status'] ?? '';
$f_search         = trim($_GET['search']           ?? '');
$per_page         = 50;
$cur_page         = max(1, (int)($_GET['page'] ?? 1));

$filters = [
    'dept_id'         => $f_dept_id,
    'program_id'      => $f_program_id,
    'batch_id'        => $f_batch_id,
    'semester'        => $f_semester,
    'academic_intake' => $f_academic_intake,
    'status'          => $f_status,
    'search'          => $f_search,
];

$result   = co_get_offers_filtered($filters, $cur_page, $per_page);
$offers   = $result['rows'];
$total    = $result['total'];
$pages    = (int)ceil($total / $per_page);

// Dropdown data
$departments      = co_departments();
$programs         = $f_dept_id > 0 ? co_programs($f_dept_id) : [];
$all_batches      = co_student_batches();
$semester_opts    = co_semester_options();
$intake_opts      = co_academic_intake_options();

// Pre-load teacher names for each offer row
$offer_ids   = array_column($offers, 'id');
$teacher_map = [];
if (!empty($offer_ids)) {
    $ph  = implode(',', array_fill(0, count($offer_ids), '?'));
    $tst = db()->prepare(
        "SELECT t.offer_id, f.name, f.designation
           FROM co_offer_teachers t
           JOIN dept_faculty f ON f.id = t.faculty_id
          WHERE t.offer_id IN ($ph)
          ORDER BY t.sort_order ASC, f.name ASC"
    );
    $tst->execute($offer_ids);
    foreach ($tst->fetchAll() as $tr) {
        $teacher_map[(int)$tr['offer_id']][] = [
            'name'        => $tr['name'],
            'designation' => $tr['designation'],
        ];
    }
}

// Group rows by batch for the grouped display
$grouped = [];
foreach ($offers as $row) {
    $key = (int)$row['batch_id'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = ['batch_name' => $row['batch_name'], 'rows' => []];
    }
    $grouped[$key]['rows'][] = $row;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Course Offer</li>
        </ol>
    </nav>
    <?php if (co_can_create()): ?>
    <a href="<?= APP_URL ?>/course-offer/create.php" class="btn btn-primary" style="border-radius:10px;">
        <i class="fas fa-plus me-1"></i> New Course Offer
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end" id="filter-form">
            <div class="col-md-2">
                <label class="form-label small fw-medium mb-1">Department</label>
                <select name="dept_id" id="f-dept" class="form-select form-select-sm"
                        onchange="loadPrograms(this.value)">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept_id == $d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-medium mb-1">Program</label>
                <select name="program_id" id="f-program" class="form-select form-select-sm">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $f_program_id == $p['id'] ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-medium mb-1">Batch</label>
                <select name="batch_id" id="f-batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($all_batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $f_batch_id == $b['id'] ? 'selected' : '' ?>>
                        <?= h($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-medium mb-1">Semester</label>
                <select name="semester" id="f-semester" class="form-select form-select-sm">
                    <option value="">All Semesters</option>
                    <?php foreach ($semester_opts as $s): ?>
                    <option value="<?= h($s) ?>" <?= $f_semester === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                    <?php if ($f_semester && !in_array($f_semester, $semester_opts)): ?>
                    <option value="<?= h($f_semester) ?>" selected><?= h($f_semester) ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-medium mb-1">Academic Intake</label>
                <select name="academic_intake" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($intake_opts as $ai): ?>
                    <option value="<?= h($ai) ?>" <?= $f_academic_intake === $ai ? 'selected' : '' ?>><?= h($ai) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-medium mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="active"   <?= $f_status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $f_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-medium mb-1">Subject Search</label>
                <div class="input-group input-group-sm">
                    <input type="text" name="search" class="form-control"
                           value="<?= h($f_search) ?>" placeholder="Code or name…">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    <a href="<?= APP_URL ?>/course-offer/index.php" class="btn btn-light" title="Clear">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Results ────────────────────────────────────────────────────────────── -->
<?php if (empty($offers)): ?>
<div class="card" style="border-radius:12px;">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-book-open fa-3x mb-3 opacity-25"></i>
        <p class="mb-0">No course offers found.</p>
        <?php if (co_can_create()): ?>
        <a href="<?= APP_URL ?>/course-offer/create.php" class="btn btn-primary btn-sm mt-3">
            <i class="fas fa-plus me-1"></i> Create First Offer
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <small class="text-muted">
        Showing <?= ($cur_page - 1) * $per_page + 1 ?>–<?= min($cur_page * $per_page, $total) ?> of <?= $total ?> offer<?= $total != 1 ? 's' : '' ?>
    </small>
</div>

// Global row counter across all batch groups, starting at the page offset.
<?php $global_row = ($cur_page - 1) * $per_page + 1; ?>
<?php foreach ($grouped as $batch_id => $group): ?>
<div class="card mb-4" style="border-radius:12px; overflow:hidden;">
    <!-- Batch header -->
    <div class="card-header py-2 px-4 d-flex align-items-center gap-2"
         style="background:linear-gradient(135deg,#0d6efd18 0%,#0d6efd08 100%); border-bottom:2px solid #0d6efd33;">
        <i class="fas fa-users text-primary"></i>
        <span class="fw-bold text-primary" style="font-size:1rem;">
            <?= h($group['batch_name']) ?>
        </span>
        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle ms-1" style="font-size:.72rem;">
            <?= count($group['rows']) ?> subject<?= count($group['rows']) != 1 ? 's' : '' ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
            <thead class="table-light">
                <tr>
                    <th style="width:2.5rem;">#</th>
                    <th>Subject</th>
                    <th style="width:4rem;" class="text-center">Credit</th>
                    <th>Semester</th>
                    <th>Academic Intake</th>
                    <th>Offering Dept / Program</th>
                    <th>Teacher(s)</th>
                    <th style="width:5rem;" class="text-center">Status</th>
                    <th class="text-end" style="width:6rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($group['rows'] as $row): ?>
            <tr>
                <td class="text-muted"><?= $global_row++ ?></td>

                <!-- Subject code + name -->
                <td>
                    <?php if ($row['course_code']): ?>
                    <span class="badge bg-light text-dark border me-1"
                          style="font-size:.68rem;font-family:monospace;">
                        <?= h($row['course_code']) ?>
                    </span>
                    <?php endif; ?>
                    <strong><?= h($row['course_name']) ?></strong>
                    <?php if (!empty($row['subject_dept_name'])): ?>
                    <div class="text-muted" style="font-size:.75rem;">
                        <?= h($row['subject_dept_name']) ?> &rsaquo; <?= h($row['subject_program_name']) ?>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Credit -->
                <td class="text-center">
                    <?php if ($row['credit']): ?>
                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                        <?= h($row['credit']) ?> cr
                    </span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>

                <!-- Semester -->
                <td>
                    <?php if ($row['semester']): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                          style="font-size:.72rem;">
                        <i class="fas fa-calendar-alt me-1"></i><?= h($row['semester']) ?>
                    </span>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>

                <!-- Academic Intake -->
                <td>
                    <?php if ($row['academic_intake']): ?>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
                          style="font-size:.72rem;">
                        <?= h($row['academic_intake']) ?>
                    </span>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>

                <!-- Offering dept / program -->
                <td class="small">
                    <?= h($row['dept_name']) ?><br>
                    <span class="text-muted"><?= h($row['program_name']) ?></span>
                </td>

                <!-- Teachers -->
                <td>
                    <?php $teachers = $teacher_map[(int)$row['id']] ?? []; ?>
                    <?php if (empty($teachers)): ?>
                    <span class="text-muted small">—</span>
                    <?php else: ?>
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($teachers as $t): ?>
                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"
                          style="font-size:.7rem;">
                        <i class="fas fa-chalkboard-teacher me-1 opacity-75"></i><?= h($t['name']) ?>
                        <?php if ($t['designation']): ?>
                        <span class="opacity-75">(<?= h($t['designation']) ?>)</span>
                        <?php endif; ?>
                    </span>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Status -->
                <td class="text-center">
                    <?php if ($row['status'] === 'active'): ?>
                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Active</span>
                    <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Inactive</span>
                    <?php endif; ?>
                </td>

                <!-- Actions -->
                <td class="text-end">
                    <?php if (co_is_staff()): ?>
                    <a href="<?= APP_URL ?>/course-offer/edit.php?id=<?= $row['id'] ?>"
                       class="btn btn-sm btn-outline-secondary me-1" title="Edit">
                        <i class="fas fa-pen"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (co_can_delete()): ?>
                    <a href="<?= APP_URL ?>/course-offer/delete.php?id=<?= $row['id'] ?>"
                       class="btn btn-sm btn-outline-danger" title="Delete"
                       onclick="return confirm('Delete this course offer? This cannot be undone.')">
                        <i class="fas fa-trash"></i>
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
<nav class="d-flex justify-content-center mt-3">
    <ul class="pagination pagination-sm mb-0">
        <?php
        $base = '?' . http_build_query(array_filter([
            'dept_id'         => $f_dept_id         ?: null,
            'program_id'      => $f_program_id      ?: null,
            'batch_id'        => $f_batch_id        ?: null,
            'semester'        => $f_semester        ?: null,
            'academic_intake' => $f_academic_intake ?: null,
            'status'          => $f_status          ?: null,
            'search'          => $f_search          ?: null,
        ]));
        for ($p = 1; $p <= $pages; $p++):
        ?>
        <li class="page-item <?= $p === $cur_page ? 'active' : '' ?>">
            <a class="page-link" href="<?= $base ?>&page=<?= $p ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<script>
function loadPrograms(deptId) {
    var sel = document.getElementById('f-program');
    sel.innerHTML = '<option value="">All Programs</option>';
    if (!deptId) return;
    fetch('<?= APP_URL ?>/course-offer/get-programs.php?dept_id=' + encodeURIComponent(deptId))
        .then(r => r.json())
        .then(function(data) {
            data.forEach(function(p) {
                var opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.program_name;
                sel.appendChild(opt);
            });
        });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
